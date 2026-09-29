<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceComponent;
use App\Support\ScanCodeGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * Servicios desde un archivo, con los articulos que consume cada uno.
 *
 * Tiene las mismas columnas de la exportacion: exportar, borrar y volver a importar deja lo
 * mismo. La columna `articulos` va como `SKU:cantidad:devuelve:precio;SKU:cantidad:devuelve:precio`;
 * el precio es opcional y solo cuenta en un servicio detallado.
 */
class ServiceImport extends MasterImport
{
    /**
     * Los componentes de la fila en curso, ya traducidos: se guardan despues del servicio.
     *
     * @var list<array{product_id: int, quantity: float, returnable_by_default: bool}>
     */
    protected array $components = [];

    public function module(): string
    {
        return 'services';
    }

    public function title(): string
    {
        return 'Importar servicios';
    }

    public function description(): string
    {
        return 'Si el código ya existe, el servicio se actualiza en vez de duplicarse, con los artículos que diga el archivo.';
    }

    public function model(): string
    {
        return Service::class;
    }

    public function columns(): array
    {
        return [
            'codigo' => 'Código del servicio (no puede ser el SKU de un artículo)',
            'nombre' => 'Como se llama',
            'categoria' => 'Opcional: nombre exacto de la categoría',
            'precio' => 'Precio al público con IVA, en pesos enteros',
            'precio_en_linea' => 'Opcional: precio en la tienda en línea',
            'iva' => implode(' / ', array_map('strval', Product::TAX_RATES)),
            'modo_precio' => 'cerrado (los artículos van incluidos) / detallado (cada artículo se cobra aparte)',
            'precio_variable' => 'si / no: el precio se digita en la caja',
            'costo' => 'Opcional: costo propio del servicio (mano de obra)',
            'activo' => 'si / no',
            'en_linea' => 'si / no',
            'articulos' => 'SKU:cantidad:devuelve:precio separados por punto y coma. «devuelve» es si o no (por defecto si); «precio» es opcional: el del artículo dentro del servicio detallado (vacío, su precio vigente)',
            'codigo_barras' => 'Opcional',
        ];
    }

    public function required(): array
    {
        return ['codigo', 'nombre'];
    }

    public function example(): array
    {
        return ['SRV-ANCHETA', 'Armado de ancheta', 'Licores', '25000', '', '19', 'detallado', 'no', '6000', 'si', 'no', 'LIC-012:1:si:42000;DUL-004:2:si', ''];
    }

    public function notes(): array
    {
        return [
            'Un SKU de la columna «articulos» que no exista hace fallar solo esa fila, con el motivo.',
            'Con precio variable el precio se ignora y el servicio no se publica en línea.',
            'Sin artículos, el servicio se cobra a precio cerrado.',
            'El precio de un artículo en «articulos» solo cuenta si el servicio es detallado; en uno cerrado los artículos van incluidos.',
        ];
    }

    public function existing(array $row, int $companyId): ?Model
    {
        return Service::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('code', $this->text($row, 'codigo'))
            ->first();
    }

    public function payload(array $row, int $companyId): ?array
    {
        $code = (string) $this->text($row, 'codigo');
        $existing = $this->existing($row, $companyId);

        foreach (['codigo', 'codigo_barras'] as $column) {
            if ($conflict = ScanCodeGuard::forService($companyId, $this->text($row, $column), $existing?->id)) {
                throw new \RuntimeException($conflict);
            }
        }

        if (mb_strlen($code) > 40) {
            throw new \RuntimeException('el código admite hasta 40 caracteres');
        }

        $category = null;
        $categoryName = $this->text($row, 'categoria');

        if ($categoryName !== null) {
            $category = Category::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('name', $categoryName)
                ->first();

            if (! $category) {
                throw new \RuntimeException("la categoría {$categoryName} no existe");
            }
        }

        $tax = (float) str_replace(',', '.', (string) ($this->text($row, 'iva') ?? '0'));

        if (! in_array($tax, array_map('floatval', Product::TAX_RATES), true)) {
            throw new \RuntimeException('el IVA solo admite '.implode(', ', array_map('strval', Product::TAX_RATES)));
        }

        $openPrice = $this->boolean($row, 'precio_variable', false);
        $price = $this->integer($row, 'precio');

        if (! $openPrice && $price <= 0) {
            throw new \RuntimeException('el precio debe ser mayor que cero (o marca precio_variable)');
        }

        $this->components = $this->parseComponents((string) $this->text($row, 'articulos'), $companyId);
        $itemized = mb_strtolower((string) $this->text($row, 'modo_precio')) === 'detallado';
        $online = $this->text($row, 'precio_en_linea');

        return [
            'code' => $code,
            'barcode' => $this->text($row, 'codigo_barras'),
            'name' => mb_substr((string) $this->text($row, 'nombre'), 0, 160),
            'category_id' => $category?->id,
            'price' => $openPrice ? 0 : $price,
            'online_price' => $openPrice || $online === null ? null : $this->integer($row, 'precio_en_linea'),
            'cost' => max(0, $this->integer($row, 'costo')),
            'tax_rate' => $tax,
            'pricing_mode' => $itemized && $this->components !== [] ? Service::PRICING_ITEMIZED : Service::PRICING_BUNDLED,
            'open_price' => $openPrice,
            'is_active' => $this->boolean($row, 'activo', true),
            'is_online_visible' => ! $openPrice && $this->boolean($row, 'en_linea', false),
        ];
    }

    /** Los articulos se reemplazan completos, como al guardar el formulario. */
    public function saved(Model $record, array $row, int $companyId): void
    {
        $record->components()->delete();
        $itemized = $record instanceof Service && $record->isItemized();

        foreach ($this->components as $index => $component) {
            ServiceComponent::create([
                ...$component,
                'unit_price' => $itemized ? $component['unit_price'] : null,
                'service_id' => $record->id,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @return list<array{product_id: int, quantity: float, unit_price: int|null, returnable_by_default: bool}>
     */
    protected function parseComponents(string $value, int $companyId): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $components = [];

        foreach (array_filter(array_map('trim', explode(';', $value))) as $part) {
            $pieces = array_map('trim', explode(':', $part));
            $sku = $pieces[0] ?? '';
            $quantity = (float) str_replace(',', '.', $pieces[1] ?? '1');
            $returnable = ! in_array(mb_strtolower($pieces[2] ?? 'si'), ['no', 'n', '0', 'false'], true);
            $price = trim($pieces[3] ?? '');

            $product = Product::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('sku', $sku)
                ->first();

            if (! $product) {
                throw new \RuntimeException("el SKU {$sku} no existe");
            }

            if (! $product->is_active) {
                throw new \RuntimeException("el artículo {$sku} está inactivo");
            }

            if (isset($components[$product->id])) {
                throw new \RuntimeException("el SKU {$sku} está repetido");
            }

            if ($quantity <= 0 || round($quantity, 3) !== $quantity) {
                throw new \RuntimeException("la cantidad de {$sku} debe ser mayor que cero, con máximo tres decimales");
            }

            if (! $product->sold_by_weight && floor($quantity) !== $quantity) {
                throw new \RuntimeException("{$sku} se vende por unidad: la cantidad debe ser entera");
            }

            if ($price !== '' && ! ctype_digit($price)) {
                throw new \RuntimeException("el precio de {$sku} debe ser un número entero de pesos, sin puntos ni comas");
            }

            $components[$product->id] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $price === '' ? null : (int) $price,
                'returnable_by_default' => $returnable,
            ];
        }

        return array_values($components);
    }
}
