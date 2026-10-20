<?php

namespace App\Services;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Support\Uploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * El catalogo de caracteristicas de una empresa.
 *
 * La plataforma nace para la canasta familiar: lo primero son las medidas (volumen, peso,
 * unidades por paquete), despues sabor y tamaño, y tambien talla y color para lo que no es comida.
 * Nada de esto es fijo: cada empresa crea, renombra, ordena y desactiva lo suyo.
 */
class ProductAttributeCatalog
{
    /**
     * Lo que trae una empresa nueva.
     *
     * @var list<array{name: string, display: string, measure: string|null, name_in_label: bool, values: list<string|array{0: float, 1: string}|array{value: string, hex: string}>}>
     */
    public const DEFAULTS = [
        ['name' => 'Volumen', 'display' => ProductAttribute::DISPLAY_BUTTONS, 'measure' => ProductAttribute::MEASURE_VOLUME, 'name_in_label' => false,
            'values' => [[250, 'ml'], [500, 'ml'], [900, 'ml'], [1, 'l'], [1.5, 'l'], [2, 'l'], [3, 'l']]],
        ['name' => 'Peso', 'display' => ProductAttribute::DISPLAY_BUTTONS, 'measure' => ProductAttribute::MEASURE_WEIGHT, 'name_in_label' => false,
            'values' => [[125, 'g'], [250, 'g'], [500, 'g'], [1, 'kg'], [2.5, 'kg'], [5, 'kg']]],
        ['name' => 'Unidades por paquete', 'display' => ProductAttribute::DISPLAY_BUTTONS, 'measure' => ProductAttribute::MEASURE_UNITS, 'name_in_label' => false,
            'values' => [[6, 'un'], [12, 'un'], [24, 'un']]],
        ['name' => 'Sabor', 'display' => ProductAttribute::DISPLAY_BUTTONS, 'measure' => null, 'name_in_label' => false, 'values' => []],
        ['name' => 'Tamaño', 'display' => ProductAttribute::DISPLAY_BUTTONS, 'measure' => null, 'name_in_label' => false,
            'values' => ['Pequeño', 'Mediano', 'Grande', 'Familiar']],
        ['name' => 'Color', 'display' => ProductAttribute::DISPLAY_SWATCHES, 'measure' => null, 'name_in_label' => false,
            'values' => [
                ['value' => 'Negro', 'hex' => '#1F1F1F'], ['value' => 'Blanco', 'hex' => '#FFFFFF'], ['value' => 'Azul', 'hex' => '#1E4E9A'],
                ['value' => 'Rojo', 'hex' => '#C62828'], ['value' => 'Gris', 'hex' => '#8A8F98'], ['value' => 'Beige', 'hex' => '#D9C6A5'],
            ]],
        ['name' => 'Talla', 'display' => ProductAttribute::DISPLAY_BUTTONS, 'measure' => null, 'name_in_label' => true,
            'values' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']],
    ];

    /** Siembra lo de fabrica. Si la empresa ya tiene una caracteristica con ese nombre, la deja. */
    public function seedDefaults(int $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            foreach (self::DEFAULTS as $order => $definition) {
                $slug = Str::slug($definition['name']);

                $exists = ProductAttribute::query()->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('slug', $slug)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $attribute = ProductAttribute::query()->withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'name' => $definition['name'],
                    'slug' => $slug,
                    'display' => $definition['display'],
                    'measure' => $definition['measure'],
                    'name_in_label' => $definition['name_in_label'],
                    'sort_order' => $order,
                    'is_active' => true,
                ]);

                foreach ($definition['values'] as $position => $value) {
                    if (is_array($value) && isset($value['value'])) {
                        $this->addValue($attribute, $value['value'], colorHex: $value['hex'], sortOrder: $position);
                    } elseif (is_array($value)) {
                        $this->addValue($attribute, null, amount: (float) $value[0], unit: $value[1], sortOrder: $position);
                    } else {
                        $this->addValue($attribute, $value, sortOrder: $position);
                    }
                }
            }
        });
    }

    /**
     * Un valor nuevo, o el que ya existe escrito de otra forma («M» y « m » son el mismo).
     *
     * En una medida el texto se arma con la cantidad y la unidad: nadie escribe «1 L» de dos
     * maneras distintas.
     */
    public function addValue(
        ProductAttribute $attribute,
        ?string $value,
        ?float $amount = null,
        ?string $unit = null,
        ?string $colorHex = null,
        ?int $sortOrder = null,
    ): ProductAttributeValue {
        if ($attribute->isMeasure()) {
            $unit = in_array($unit, $attribute->units(), true) ? $unit : $attribute->units()[0];
            $amount = max(0.001, (float) $amount);
            $value = ProductAttribute::measureLabel($amount, $unit);
        } else {
            $amount = null;
            $unit = null;
            $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
        }

        $slug = ProductAttributeValue::slugFor($value);
        $existing = ProductAttributeValue::query()->where('product_attribute_id', $attribute->id)->where('slug', $slug)->first();

        if ($existing) {
            if (! $existing->is_active) {
                $existing->update(['is_active' => true]);
            }

            return $existing;
        }

        return ProductAttributeValue::query()->create([
            'product_attribute_id' => $attribute->id,
            'value' => Str::limit($value, 40, ''),
            'slug' => $slug,
            'amount' => $amount,
            'unit' => $unit,
            'color_hex' => $colorHex,
            'sort_order' => $sortOrder ?? $this->nextSort($attribute, $amount, $unit),
            'is_active' => true,
        ]);
    }

    /**
     * Una caracteristica por su nombre, como la escribe una persona en un archivo. Si la empresa no
     * la tiene, nace como texto con botones: una medida se crea a proposito en Atributos, porque
     * hay que decir en que unidades se mide.
     */
    public function attributeNamed(int $companyId, string $name): ProductAttribute
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $slug = Str::slug($name);

        $found = ProductAttribute::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('slug', $slug)->first();

        if ($found) {
            if (! $found->is_active) {
                $found->update(['is_active' => true]);
            }

            return $found;
        }

        return ProductAttribute::query()->withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'name' => Str::limit($name, 60, ''),
            'slug' => $slug,
            'display' => ProductAttribute::DISPLAY_BUTTONS,
            'measure' => null,
            'name_in_label' => false,
            'sort_order' => (int) ProductAttribute::query()->withoutGlobalScopes()->where('company_id', $companyId)->max('sort_order') + 1,
            'is_active' => true,
        ]);
    }

    /**
     * Un valor escrito en un archivo. En una medida se lee la cantidad y la unidad («900 ml»,
     * «1,5 L», «x 6 und»), y lo que no se entienda falla con su motivo en vez de inventar.
     */
    public function valueFromText(ProductAttribute $attribute, string $text): ProductAttributeValue
    {
        if (! $attribute->isMeasure()) {
            return $this->addValue($attribute, $text);
        }

        $parsed = self::parseMeasure($text, $attribute);

        if ($parsed === null) {
            $units = implode(', ', array_map(fn ($unit) => ProductAttribute::UNIT_LABELS[$unit] ?? $unit, $attribute->units()));

            throw ValidationException::withMessages([
                'value' => "«{$text}» no es un valor de {$attribute->name}: escribe la cantidad y la unidad ({$units})",
            ]);
        }

        return $this->addValue($attribute, null, amount: $parsed[0], unit: $parsed[1]);
    }

    /**
     * «900 ml», «1,5 L», «1.000 g», «2 kilos», «x 6 und», «6»: cantidad y unidad de la medida.
     *
     * @return array{0: float, 1: string}|null
     */
    public static function parseMeasure(string $text, ProductAttribute $attribute): ?array
    {
        $aliases = [
            'ml' => ['ml', 'cc', 'mililitro', 'mililitros'],
            'l' => ['l', 'lt', 'lts', 'litro', 'litros'],
            'g' => ['g', 'gr', 'grs', 'gramo', 'gramos'],
            'kg' => ['kg', 'kgs', 'kilo', 'kilos', 'kilogramo', 'kilogramos'],
            'un' => ['un', 'und', 'unds', 'u', 'ud', 'uds', 'unidad', 'unidades'],
        ];

        $clean = Str::lower(Str::ascii(trim($text)));

        if (preg_match('/^(?:x\s*)?(\d[\d.,]*)\s*([a-z]*)\.?$/', $clean, $match) !== 1) {
            return null;
        }

        // En Colombia «1.000» es mil y «1,5» es uno y medio; «1.5» tambien se entiende.
        $number = $match[1];
        $number = preg_match('/^\d{1,3}(\.\d{3})+$/', $number) === 1 ? str_replace('.', '', $number) : $number;
        $number = str_contains($number, ',') ? str_replace(['.', ','], ['', '.'], $number) : $number;
        $amount = (float) $number;

        if ($amount <= 0) {
            return null;
        }

        $written = $match[2];
        $unit = null;

        if ($written === '') {
            $unit = $attribute->measure === ProductAttribute::MEASURE_UNITS ? 'un' : null;
        } else {
            foreach ($aliases as $key => $names) {
                if (in_array($written, $names, true)) {
                    $unit = $key;
                }
            }
        }

        return $unit !== null && in_array($unit, $attribute->units(), true) ? [$amount, $unit] : null;
    }

    /**
     * Donde cae un valor nuevo: en una medida, en su lugar por cantidad (1 L despues de 900 ml);
     * en un texto, al final.
     */
    protected function nextSort(ProductAttribute $attribute, ?float $amount, ?string $unit): int
    {
        $values = ProductAttributeValue::query()->where('product_attribute_id', $attribute->id)->orderBy('sort_order')->get();

        if ($amount === null || $unit === null) {
            return (int) $values->max('sort_order') + 1;
        }

        $probe = new ProductAttributeValue(['amount' => $amount, 'unit' => $unit]);
        $base = $probe->baseAmount() ?? $amount;
        $position = 0;

        foreach ($values as $index => $existing) {
            if (($existing->baseAmount() ?? PHP_FLOAT_MAX) <= $base) {
                $position = $index + 1;
            }
        }

        // Se corren los de despues para abrirle espacio.
        foreach ($values->slice($position) as $index => $existing) {
            $existing->update(['sort_order' => $index + 1]);
        }

        return $position;
    }
    /**
     * El catalogo para el formulario del articulo y para la pantalla de Atributos.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogFor(int $companyId, bool $onlyActive = false): array
    {
        $attributes = ProductAttribute::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->with(['values' => fn ($query) => $query->when($onlyActive, fn ($inner) => $inner->where('is_active', true))->with('media')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $valueIds = $attributes->flatMap(fn (ProductAttribute $attribute) => $attribute->values->pluck('id'))->all();
        $usage = $this->usage($valueIds);

        return $attributes->map(fn (ProductAttribute $attribute) => [
            'id' => $attribute->id,
            'name' => $attribute->name,
            'display' => $attribute->display,
            'measure' => $attribute->measure,
            'units' => $attribute->units(),
            'name_in_label' => (bool) $attribute->name_in_label,
            'is_active' => (bool) $attribute->is_active,
            'values' => $attribute->values->map(fn (ProductAttributeValue $value) => $this->valuePayload($value, $usage[$value->id] ?? 0))->values()->all(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function valuePayload(ProductAttributeValue $value, int $usage): array
    {
        return [
            'id' => $value->id,
            'value' => $value->value,
            'amount' => $value->amount,
            'unit' => $value->unit,
            'color_hex' => $value->color_hex,
            'media_url' => $value->media ? Uploads::url((string) ($value->media->variants['square'] ?? $value->media->path)) : null,
            'media_id' => $value->media_id,
            'sort_order' => $value->sort_order,
            'is_active' => (bool) $value->is_active,
            'usage' => $usage,
        ];
    }

    /**
     * Cuantos subarticulos usan cada valor, en una sola consulta.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    protected function usage(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = [];

        foreach (['variant_value_1_id', 'variant_value_2_id'] as $column) {
            DB::table('products')
                ->whereNull('deleted_at')
                ->whereIn($column, $ids)
                ->groupBy($column)
                ->selectRaw("{$column} as value_id, COUNT(*) as total")
                ->get()
                ->each(function ($row) use (&$counts) {
                    $counts[(int) $row->value_id] = ($counts[(int) $row->value_id] ?? 0) + (int) $row->total;
                });
        }

        return $counts;
    }

}
