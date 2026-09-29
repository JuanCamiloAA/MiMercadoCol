<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductFamilyService;
use App\Support\ScanCodeGuard;
use Illuminate\Database\Eloquent\Model;

class ProductImport extends MasterImport
{
    public function module(): string
    {
        return 'products';
    }

    public function title(): string
    {
        return 'Importar articulos';
    }

    public function description(): string
    {
        return 'Si el SKU ya existe, el articulo se actualiza en vez de duplicarse.';
    }

    public function model(): string
    {
        return Product::class;
    }

    public function columns(): array
    {
        return [
            'sku' => 'Codigo interno del articulo',
            'nombre' => 'Como se llama',
            'categoria' => 'Nombre exacto de la categoria',
            'unidad' => implode(' / ', Product::UNITS),
            'costo' => 'Lo que cuesta, en pesos enteros',
            'precio' => 'Precio al publico con IVA incluido',
            'iva' => implode(' / ', array_map('strval', Product::TAX_RATES)),
            'minimo' => 'Existencia minima antes de pedir',
            'codigo_barras' => 'Opcional',
            'marca' => 'Opcional',
            // Las cinco que siguen arman articulos con subarticulos; vacias, el articulo es individual.
            'articulo_principal' => 'Opcional: codigo o nombre exacto del articulo con subarticulos',
            'atributo_1' => 'Con articulo_principal: la caracteristica (Volumen, Talla, Sabor…)',
            'valor_1' => 'Con articulo_principal: el valor (900 ml, 8, Fresa…)',
            'atributo_2' => 'Opcional: segunda caracteristica (Color…)',
            'valor_2' => 'Opcional: su valor (Azul…)',
        ];
    }

    public function aliases(): array
    {
        // Los encabezados que escribe Exportar.
        return ['codigo_de_barras' => 'codigo_barras'];
    }

    public function required(): array
    {
        return ['sku', 'nombre'];
    }

    public function example(): array
    {
        return ['ACE-900', 'Aceite de girasol 900 ml', 'Despensa', 'unit', '9800', '13900', '19', '12', '7702001000123', 'Premier', 'Aceite de girasol', 'Volumen', '900 ml', '', ''];
    }

    public function notes(): array
    {
        return [
            'La categoria se busca por nombre exacto; si no aparece se usa la primera del catalogo.',
            'El IVA solo admite '.implode(', ', array_map('strval', Product::TAX_RATES)).'.',
            'Los precios van en pesos enteros: el sistema no maneja centavos.',
            'Sin articulo_principal la fila es un articulo individual, como siempre.',
            'Las filas con el mismo articulo_principal forman o completan un articulo con subarticulos. Categoria, marca e IVA salen de la primera fila; una fila que los contradiga no entra y se dice por que.',
            'Las caracteristicas y los valores que no existan se crean. En una medida el valor lleva cantidad y unidad: 900 ml, 1,5 L, 500 g, x 6 und.',
        ];
    }

    public function existing(array $row, int $companyId): ?Model
    {
        return Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('sku', $this->text($row, 'sku'))
            ->first();
    }

    public function payload(array $row, int $companyId): ?array
    {
        $name = $this->text($row, 'categoria');
        $familyName = $this->text($row, 'articulo_principal');
        $family = $familyName ? ProductFamilyService::findFamily($companyId, $familyName) : null;

        if ($familyName) {
            $this->assertVariantColumns($row);
        }

        // En un subarticulo, lo que la fila deja vacio lo pone su articulo principal.
        $category = ($name
            ? Category::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('name', $name)->first()
            : null)
            ?? ($family ? Category::query()->withoutGlobalScopes()->whereKey($family->category_id)->first() : null)
            ?? Category::query()->withoutGlobalScopes()->where('company_id', $companyId)->first();

        if (! $category) {
            throw new \RuntimeException('no hay ninguna categoria creada todavia');
        }

        // Un SKU o codigo de barras de un servicio dejaria al escaner de la caja con dos respuestas.
        foreach (['sku', 'codigo_barras'] as $column) {
            if ($conflict = ScanCodeGuard::forProduct($companyId, $this->text($row, $column))) {
                throw new \RuntimeException($conflict);
            }
        }

        $unit = $this->text($row, 'unidad');
        $tax = $this->text($row, 'iva') === null && $family
            ? (float) $family->tax_rate
            : (float) str_replace(',', '.', (string) $this->text($row, 'iva'));

        $payload = [
            'sku' => $this->text($row, 'sku'),
            'category_id' => $category->id,
            'name' => $this->text($row, 'nombre'),
            'brand' => $this->text($row, 'marca') ?? $family?->brand,
            'barcode' => $this->text($row, 'codigo_barras'),
            'unit' => in_array($unit, Product::UNITS, true) ? $unit : Product::UNIT_UNIT,
            'cost' => $this->integer($row, 'costo'),
            'price' => $this->integer($row, 'precio'),
            'tax_rate' => in_array($tax, array_map('floatval', Product::TAX_RATES), true) ? $tax : 0,
            'min_stock' => $this->integer($row, 'minimo'),
            'is_active' => true,
        ];

        // Un archivo sin las columnas nuevas no saca a nadie de su articulo principal: se
        // actualiza lo propio del subarticulo y lo comun lo sigue poniendo la familia.
        if (! $familyName && ($current = $this->existing($row, $companyId)) instanceof Product && $current->product_family_id) {
            unset($payload['name'], $payload['category_id'], $payload['brand'], $payload['tax_rate']);
        }

        return $payload;
    }

    /**
     * Con `articulo_principal`, el articulo entra a su familia por el unico camino: el servicio.
     * Lo que contradiga la familia falla la fila entera, articulo incluido.
     */
    public function saved(Model $record, array $row, int $companyId): void
    {
        $familyName = $this->text($row, 'articulo_principal');

        if (! $familyName || ! $record instanceof Product) {
            return;
        }

        app(ProductFamilyService::class)->placeImported($record, [
            'family' => $familyName,
            'attribute_1' => (string) $this->text($row, 'atributo_1'),
            'value_1' => (string) $this->text($row, 'valor_1'),
            'attribute_2' => $this->text($row, 'atributo_2'),
            'value_2' => $this->text($row, 'valor_2'),
        ], $companyId);
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function assertVariantColumns(array $row): void
    {
        if (! $this->text($row, 'atributo_1') || ! $this->text($row, 'valor_1')) {
            throw new \RuntimeException('con articulo_principal hacen falta atributo_1 y valor_1');
        }

        if (($this->text($row, 'atributo_2') === null) !== ($this->text($row, 'valor_2') === null)) {
            throw new \RuntimeException('atributo_2 y valor_2 van juntos: los dos o ninguno');
        }
    }
}
