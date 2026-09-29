<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla base de la tienda, de la plataforma.
 *
 * Solo guarda diseño: bloques de todas las paginas, la forma del tema y la estructura global.
 * La marca, el domicilio, los pagos, el contacto y los datos legales son siempre de cada sede.
 * El reparto vive en `DESIGN_KEYS` y en ningun otro lado.
 */
class StorefrontTemplate extends Model
{
    use HasFactory;

    /**
     * Lo que pone la plantilla. Lo que no este aqui es de la sede.
     *
     * `settings_except` son los campos de una clave de diseño que siguen siendo de la sede: el
     * pie es diseño, pero su contacto, sus redes y sus datos legales son de quien vende.
     */
    public const DESIGN_KEYS = [
        'blocks' => true,
        'theme' => ['scheme', 'allow_scheme_toggle', 'font_pair', 'radius', 'density', 'button_style'],
        'settings' => [
            'topbar', 'header', 'nav', 'mobile_nav', 'card', 'cart_bar',
            'category_page', 'product_page', 'search', 'footer', 'catalog',
        ],
        'settings_except' => [
            'footer' => ['contact', 'social', 'legal_text', 'terms_target', 'privacy_target', 'pqrs_target'],
        ],
    ];

    protected $fillable = [
        'name', 'description', 'preview_media_id', 'draft', 'published', 'published_at',
        'published_by_user_id', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'published' => 'array',
            'published_at' => 'datetime',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StorefrontTemplateVersion::class, 'template_id');
    }

    /** Las tiendas que la siguen hoy: reciben cada publicacion sin tocar sus versiones. */
    public function followers(): HasMany
    {
        return $this->hasMany(Storefront::class, 'template_id')->withoutGlobalScopes()->where('follows_template', true);
    }

    public function previewMedia(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'preview_media_id')->withoutGlobalScopes();
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * Solo el diseño de un retrato de tienda.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{theme: array<string, mixed>, blocks: list<array<string, mixed>>, settings: array<string, mixed>}
     */
    public static function designOf(array $snapshot): array
    {
        $settings = [];

        foreach (self::DESIGN_KEYS['settings'] as $key) {
            if (! isset($snapshot['settings'][$key]) || ! is_array($snapshot['settings'][$key])) {
                continue;
            }

            $settings[$key] = array_diff_key($snapshot['settings'][$key], array_flip(self::DESIGN_KEYS['settings_except'][$key] ?? []));
        }

        return [
            'theme' => array_intersect_key($snapshot['theme'] ?? [], array_flip(self::DESIGN_KEYS['theme'])),
            'blocks' => array_values($snapshot['blocks'] ?? []),
            'settings' => $settings,
        ];
    }

    /**
     * El retrato de una sede con el diseño de la plantilla encima.
     *
     * Todo lo que es de la sede se queda: marca, domicilio, pagos, zonas, franjas, contacto y
     * datos legales.
     *
     * @param  array<string, mixed>  $sede
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public static function compose(array $sede, array $design): array
    {
        $composed = $sede;
        $composed['blocks'] = array_values($design['blocks'] ?? []);
        $composed['theme'] = array_merge($sede['theme'] ?? [], array_intersect_key($design['theme'] ?? [], array_flip(self::DESIGN_KEYS['theme'])));

        foreach (self::DESIGN_KEYS['settings'] as $key) {
            if (! isset($design['settings'][$key]) || ! is_array($design['settings'][$key])) {
                continue;
            }

            $own = array_intersect_key($sede['settings'][$key] ?? [], array_flip(self::DESIGN_KEYS['settings_except'][$key] ?? []));
            $composed['settings'][$key] = array_merge(
                array_diff_key($design['settings'][$key], array_flip(self::DESIGN_KEYS['settings_except'][$key] ?? [])),
                $own
            );
        }

        return $composed;
    }
}
