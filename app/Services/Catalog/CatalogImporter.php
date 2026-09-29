<?php

namespace App\Services\Catalog;

use App\Models\CatalogImport;
use App\Models\CatalogImportItem;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mete en el catalogo de una empresa lo que llego de una fuente externa.
 *
 * La regla que manda sobre todas: **una segunda importacion no puede deshacer el trabajo
 * manual del comerciante**. Si el articulo ya existia, solo se rellenan los campos que estan
 * vacios. La categoria, si esta activo, los precios y cualquier decision comercial se quedan
 * como el administrador las dejo, aunque la fuente diga otra cosa.
 *
 * Lo que entra nuevo entra **apagado y sin precio**. Open Food Facts no tiene precios, y un
 * articulo activo con precio cero es un articulo que la caja regala.
 */
class CatalogImporter
{
    /**
     * Lo unico que se completa al reimportar: datos descriptivos que estaban en blanco.
     *
     * Ni `category_id`, ni `is_active`, ni precios. Esa lista corta es la garantia.
     *
     * @var list<string>
     */
    public const FILLABLE_ON_UPDATE = ['description', 'brand', 'content_amount', 'content_unit', 'image_url'];

    public function __construct(protected ProductNormalizer $normalizer) {}

    /**
     * Procesa un bloque de articulos crudos y devuelve los contadores del bloque.
     *
     * @param  list<array<string, mixed>>  $rawProducts
     * @param  array<string, true>  $seen  EAN ya procesados en esta corrida, por referencia
     * @return array{imported: int, updated: int, skipped: int, failed: int}
     */
    public function importChunk(CatalogImport $import, array $rawProducts, int $fallbackCategoryId, array &$seen): array
    {
        $counters = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        $normalized = [];

        foreach ($rawProducts as $raw) {
            $product = $this->normalizer->normalize($raw);

            if (! $product->isUsable()) {
                $this->record($import, $product->ean, $product->name, $raw, CatalogImportItem::RESULT_SKIPPED, $product->reason);
                $counters['skipped']++;

                continue;
            }

            // Un mismo EAN puede repetirse entre paginas si el catalogo cambia mientras se
            // recorre. Se corta aqui, en memoria, antes de llegar a la base.
            if (isset($seen[$product->ean])) {
                $this->record($import, $product->ean, $product->name, $raw, CatalogImportItem::RESULT_SKIPPED, 'duplicate');
                $counters['skipped']++;

                continue;
            }

            $seen[$product->ean] = true;
            $normalized[] = $product;
        }

        if ($normalized === []) {
            return $counters;
        }

        // Una transaccion por bloque, no una gigante: quince mil filas en una sola bloquean la
        // tabla el rato entero y dejan la barra de avance congelada.
        DB::transaction(function () use ($import, $normalized, $fallbackCategoryId, &$counters) {
            $existing = $this->existingByEan($import->company_id, array_map(fn ($p) => $p->ean, $normalized));
            $categories = $this->categoriesByName($import->company_id);

            foreach ($normalized as $product) {
                try {
                    $result = $this->persist($import, $product, $existing->get($product->ean), $categories, $fallbackCategoryId);
                } catch (\Throwable $exception) {
                    $this->record($import, $product->ean, $product->name, $product->payload, CatalogImportItem::RESULT_FAILED, $this->shorten($exception->getMessage()));
                    $counters['failed']++;

                    continue;
                }

                $counters[$result]++;
            }
        });

        return $counters;
    }

    /**
     * @param  Collection<string, Category>  $categories
     * @return 'imported'|'updated'|'skipped'
     */
    protected function persist(
        CatalogImport $import,
        NormalizedProduct $product,
        ?Product $existing,
        Collection $categories,
        int $fallbackCategoryId,
    ): string {
        if ($existing !== null && $existing->trashed()) {
            // Resucitar en silencio algo que el administrador elimino seria pasarle por encima.
            $this->record($import, $product->ean, $product->name, $product->payload, CatalogImportItem::RESULT_SKIPPED, 'deleted_product', $existing->id);

            return 'skipped';
        }

        if ($existing !== null) {
            $changed = $this->completeEmptyFields($existing, $product);

            $this->record(
                $import, $product->ean, $product->name, $product->payload,
                CatalogImportItem::RESULT_UPDATED,
                $changed ? null : 'no_changes',
                $existing->id,
            );

            return 'updated';
        }

        $sku = $this->availableSku($import->company_id, $product->ean);

        if ($sku === null) {
            $this->record($import, $product->ean, $product->name, $product->payload, CatalogImportItem::RESULT_SKIPPED, 'sku_taken');

            return 'skipped';
        }

        $created = Product::query()->withoutGlobalScopes()->create([
            'company_id' => $import->company_id,
            'category_id' => $this->categoryFor($product, $categories, $fallbackCategoryId),
            'name' => $product->name,
            'sku' => $sku,
            'barcode' => $product->ean,
            'brand' => $product->brand,
            'description' => $product->description,
            'content_amount' => $product->contentAmount,
            'content_unit' => $product->contentUnit,
            'image_url' => $product->imageUrl,
            'unit' => Product::UNIT_UNIT,
            // Sin precio y apagado: la fuente no trae precios y un articulo activo en cero es
            // un articulo que la caja regala. El administrador lo enciende cuando lo tarifa.
            'cost' => 0,
            'price' => 0,
            'tax_rate' => 0,
            'is_active' => false,
            'is_online_visible' => false,
            // De donde salio de verdad: por archivo o por la API. Mezclarlos borraria la
            // pista de como entro cada cosa al catalogo.
            'source' => $import->source === CatalogImport::SOURCE_CSV ? Product::SOURCE_CSV : Product::SOURCE_API,
            'source_ref' => $product->ean,
        ]);

        $this->record($import, $product->ean, $product->name, $product->payload, CatalogImportItem::RESULT_IMPORTED, null, $created->id);

        return 'imported';
    }

    /**
     * Que le aportaria la fuente a un articulo que ya existe.
     *
     * Vacio quiere decir «ya esta al dia»: la fuente no tiene nada que este articulo no tenga.
     *
     * Lo usan dos sitios —la previsualizacion, para no volver a ofrecer lo que ya entro, y la
     * importacion, para rellenar— y tiene que ser el mismo, o la pantalla ofreceria articulos
     * que al importarse no cambian nada.
     *
     * @return array<string, mixed>
     */
    public function pendingChanges(Product $product, NormalizedProduct $incoming): array
    {
        $candidates = [
            'description' => $incoming->description,
            'brand' => $incoming->brand,
            'content_amount' => $incoming->contentAmount,
            'content_unit' => $incoming->contentUnit,
            'image_url' => $incoming->imageUrl,
        ];

        $changes = [];

        foreach ($candidates as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $product->getAttribute($field);

            if ($current === null || $current === '' || $current === 0.0) {
                $changes[$field] = $value;
            }
        }

        // Un articulo que se digito a mano y despues aparecio en la fuente deja constancia de
        // donde esta su referencia, sin dejar de ser suyo.
        if ($product->source_ref === null) {
            $changes['source_ref'] = $incoming->ean;
        }

        return $changes;
    }

    /**
     * Rellena solo lo que estaba en blanco. Devuelve si cambio algo.
     */
    protected function completeEmptyFields(Product $product, NormalizedProduct $incoming): bool
    {
        $changes = $this->pendingChanges($product, $incoming);

        if ($changes === []) {
            return false;
        }

        $product->forceFill($changes)->save();

        return true;
    }

    /**
     * La categoria del articulo nuevo.
     *
     * Si la de la fuente coincide por nombre con una de la empresa, se usa esa; si no, cae en
     * la que el administrador eligio en el asistente. Nunca se crean categorias solas: el
     * arbol de categorias es una decision del comerciante, no de una API.
     *
     * @param  Collection<string, Category>  $categories
     */
    protected function categoryFor(NormalizedProduct $product, Collection $categories, int $fallbackCategoryId): int
    {
        if ($product->externalCategory === null) {
            return $fallbackCategoryId;
        }

        $match = $categories->get(mb_strtolower($product->externalCategory));

        return $match?->id ?? $fallbackCategoryId;
    }

    /**
     * Un SKU libre derivado del codigo de barras.
     *
     * Devuelve null si el EAN y sus variantes ya estan tomados por otros articulos: antes de
     * romper el indice unico, se omite y se dice por que.
     */
    protected function availableSku(int $companyId, string $ean): ?string
    {
        foreach ([$ean, 'EAN-'.$ean, 'EAN-'.$ean.'-2', 'EAN-'.$ean.'-3'] as $candidate) {
            $taken = Product::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->where('company_id', $companyId)
                ->where('sku', mb_substr($candidate, 0, 40))
                ->exists();

            if (! $taken) {
                return mb_substr($candidate, 0, 40);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $eans
     * @return Collection<string, Product>
     */
    protected function existingByEan(int $companyId, array $eans): Collection
    {
        return Product::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('barcode', $eans)
            ->get()
            ->keyBy('barcode');
    }

    /**
     * @return Collection<string, Category>
     */
    protected function categoriesByName(int $companyId): Collection
    {
        return Category::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy(fn (Category $category) => mb_strtolower($category->name));
    }

    /** El codigo que la fuente no tiene: queda como omitido, con su motivo. */
    public function recordMissing(CatalogImport $import, string $ean): void
    {
        $this->record($import, $ean, null, [], CatalogImportItem::RESULT_SKIPPED, 'not_found');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function record(
        CatalogImport $import,
        ?string $ean,
        ?string $name,
        array $payload,
        string $result,
        ?string $reason = null,
        ?int $productId = null,
    ): void {
        CatalogImportItem::create([
            'catalog_import_id' => $import->id,
            'ean' => $ean,
            'name' => $name === null ? null : mb_substr($name, 0, 200),
            'payload' => $payload,
            'result' => $result,
            'reason' => $reason,
            'product_id' => $productId,
        ]);
    }

    protected function shorten(string $message): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 255);
    }
}
