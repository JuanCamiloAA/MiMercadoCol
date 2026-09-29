<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\ImageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Una imagen del editor.
 *
 * Guarda el texto alternativo y el punto focal junto al archivo, no en el bloque que la usa:
 * la misma foto puede aparecer en dos sitios y su descripcion es una sola.
 *
 * Sin empresa (`company_id` nulo) es un medio de plataforma: lo usa la plantilla base de la
 * tienda. Ninguna consulta normal lo devuelve —ni a una empresa ni al super usuario en
 * consolidado—; hay que pedirlo con `platform()` o sin los alcances globales.
 */
class MediaAsset extends Model
{
    use BelongsToCompany, HasFactory;

    /** Recortes que se generan al subir: apaisado, retrato y cuadrado. */
    public const VARIANTS = ['wide' => [16, 9], 'portrait' => [4, 5], 'square' => [1, 1]];

    protected $fillable = [
        'company_id', 'path', 'disk', 'mime', 'width', 'height', 'bytes',
        'alt', 'focal_x', 'focal_y', 'variants', 'created_by_user_id',
    ];

    protected static function booted(): void
    {
        // `CompanyScope` filtra por empresa, pero en consolidado no filtra nada: sin esto el
        // super usuario veria los medios de plataforma mezclados con los de las empresas.
        static::addGlobalScope('company_media', fn (Builder $query) => $query->whereNotNull($query->getModel()->getTable().'.company_id'));
    }

    /** Los medios de plataforma, y solo ellos. */
    public static function platform(): Builder
    {
        return static::query()->withoutGlobalScopes()->whereNull('company_id');
    }

    /**
     * Crea un medio de plataforma.
     *
     * Sin eventos: `BelongsToCompany` le pondria la empresa activa del super usuario.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createForPlatform(array $attributes): self
    {
        return static::withoutEvents(fn () => static::query()->withoutGlobalScopes()->create(array_merge($attributes, ['company_id' => null])));
    }

    public function isPlatform(): bool
    {
        return $this->company_id === null;
    }

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'focal_x' => 'float',
            'focal_y' => 'float',
            'variants' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Lo que el editor y la tienda necesitan para pintarla.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $disk = Storage::disk($this->disk);
        $small = $this->variants['sm'] ?? null;

        return [
            // Las dos anchuras que hay del original: el navegador baja la que le sirve.
            'srcset' => $this->width
                ? collect([$small ? $disk->url($small).' '.ImageService::SMALL_WIDTH.'w' : null, $this->url().' '.$this->width.'w'])->filter()->implode(', ')
                : null,
            'id' => $this->id,
            'url' => $this->url(),
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
            'focal_x' => $this->focal_x,
            'focal_y' => $this->focal_y,
            'variants' => collect($this->variants ?? [])
                ->map(fn (string $path) => Storage::disk($this->disk)->url($path))
                ->all(),
        ];
    }
}
