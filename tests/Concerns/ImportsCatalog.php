<?php

namespace Tests\Concerns;

use App\Jobs\ImportCatalogJob;
use App\Models\CatalogImport;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Ayudantes para las pruebas de importacion de catalogo.
 *
 * Ni una sola llamada de red: todo sale de las fixtures guardadas en el repositorio. Una
 * prueba que depende de que Open Food Facts este arriba no es una prueba.
 */
trait ImportsCatalog
{
    /**
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        $path = base_path("tests/Fixtures/catalog/{$name}.json");

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Responde a la busqueda con la fixture indicada y, si se piden, a las consultas por EAN.
     *
     * @param  array<string, array<string, mixed>|null>  $byEan  EAN => producto, o null si no existe
     */
    protected function fakeCatalog(string $searchFixture = 'search-page-1', array $byEan = []): void
    {
        $search = $this->fixture($searchFixture);

        $responses = [
            '*/api/v2/search*' => Http::response($search),
        ];

        // Lo que no se declare a mano se responde desde la misma fixture de busqueda, para no
        // tener que repetir el mismo producto en dos archivos.
        foreach ($search['products'] as $product) {
            $code = (string) ($product['code'] ?? '');

            if ($code !== '' && ! array_key_exists($code, $byEan)) {
                $byEan[$code] = $product;
            }
        }

        foreach ($byEan as $ean => $product) {
            $responses["*/api/v2/product/{$ean}.json*"] = $product === null
                ? Http::response(['code' => $ean, 'status' => 0, 'status_verbose' => 'product not found'])
                : Http::response(['code' => $ean, 'status' => 1, 'status_verbose' => 'product found', 'product' => $product]);
        }

        // Cualquier codigo no declarado: la API contesta 200 con status 0, que es la trampa.
        $responses['*/api/v2/product/*'] = Http::response(['status' => 0, 'status_verbose' => 'product not found']);

        Http::fake($responses);
    }

    /** La categoria donde caen los articulos que la fuente no sabe clasificar. */
    protected function fallbackCategory(): Category
    {
        return $this->category;
    }

    /**
     * Crea la corrida y ejecuta el job en el acto, sin cola.
     *
     * @param  array<string, mixed>  $criteria
     */
    protected function runImport(User $user, array $criteria = [], ?Category $category = null, string $source = CatalogImport::SOURCE_API): CatalogImport
    {
        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'source' => $source,
            'criteria' => array_merge(['country' => 'Colombia', 'limit' => 500, 'eans' => []], $criteria),
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        app()->call([new ImportCatalogJob($import->id, ($category ?? $this->fallbackCategory())->id), 'handle']);

        return $import->refresh();
    }
}
