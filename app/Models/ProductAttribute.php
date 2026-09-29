<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una caracteristica con la que se arman subarticulos: presentacion, peso, sabor, tamaño, talla,
 * color… Es de la empresa y se reutiliza entre articulos.
 *
 * Una medida (`measure`) guarda en cada valor cantidad y unidad, y esa cantidad pasa al
 * contenido del subarticulo: «Aceite · 900 ml» sabe cuanto trae y la tienda calcula su precio
 * por litro sin que nadie lo digite dos veces.
 */
class ProductAttribute extends Model
{
    use BelongsToCompany;

    public const DISPLAY_BUTTONS = 'buttons';

    public const DISPLAY_SWATCHES = 'swatches';

    public const DISPLAY_SELECT = 'select';

    /** @var array<string, string> */
    public const DISPLAYS = [
        self::DISPLAY_BUTTONS => 'Botones',
        self::DISPLAY_SWATCHES => 'Muestras de color o foto',
        self::DISPLAY_SELECT => 'Lista desplegable',
    ];

    public const MEASURE_VOLUME = 'volume';

    public const MEASURE_WEIGHT = 'weight';

    public const MEASURE_UNITS = 'units';

    /** @var array<string, string> */
    public const MEASURES = [
        self::MEASURE_VOLUME => 'Volumen (ml, L)',
        self::MEASURE_WEIGHT => 'Peso (g, kg)',
        self::MEASURE_UNITS => 'Unidades por paquete',
    ];

    /**
     * Las unidades de cada medida, las mismas de `products.content_unit`, y cuanto vale cada una
     * en la unidad base (para ordenar 500 ml antes que 1 L).
     *
     * @var array<string, array<string, float>>
     */
    public const MEASURE_UNITS_MAP = [
        self::MEASURE_VOLUME => ['ml' => 0.001, 'l' => 1.0],
        self::MEASURE_WEIGHT => ['g' => 0.001, 'kg' => 1.0],
        self::MEASURE_UNITS => ['un' => 1.0],
    ];

    /** @var array<string, string> */
    public const UNIT_LABELS = ['ml' => 'ml', 'l' => 'L', 'g' => 'g', 'kg' => 'kg', 'un' => 'und'];

    protected $fillable = ['company_id', 'name', 'slug', 'display', 'measure', 'name_in_label', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'name_in_label' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isMeasure(): bool
    {
        return $this->measure !== null && isset(self::MEASURE_UNITS_MAP[$this->measure]);
    }

    /** @return list<string> Las unidades que admite, si es una medida. */
    public function units(): array
    {
        return array_keys(self::MEASURE_UNITS_MAP[$this->measure] ?? []);
    }

    /** «1,5 L», «500 ml», «x 6 und». */
    public static function measureLabel(float $amount, string $unit): string
    {
        $number = rtrim(rtrim(number_format($amount, 3, ',', '.'), '0'), ',');

        return $unit === 'un' ? 'x '.$number.' und' : $number.' '.(self::UNIT_LABELS[$unit] ?? $unit);
    }
}
