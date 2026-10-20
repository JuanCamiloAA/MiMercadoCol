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
        // Nunca el acento ni el rojo de oferta: son de la sede. La plantilla los sugiere aparte
        // (`palette_suggestion`) y la sede decide aplicarlos desde Aspecto.
        'theme' => [
            'scheme', 'allow_scheme_toggle', 'font_pair', 'radius', 'density', 'button_style',
            'logo_font', 'surface', 'heading_case', 'container', 'button_shape',
        ],
        'settings' => [
            'topbar', 'header', 'nav', 'mobile_nav', 'card', 'cart_bar',
            'category_page', 'product_page', 'search', 'footer', 'catalog', 'floating',
        ],
        'settings_except' => [
            'footer' => ['contact', 'social', 'legal_text', 'terms_target', 'privacy_target', 'pqrs_target'],
        ],
    ];

    /** Las familias de comercio con que se filtra la galeria de plantillas. */
    public const AUDIENCE_GROUPS = ['Mercado', 'Moda', 'Belleza y hogar', 'Ferretería'];

    /** Los colores que una plantilla puede sugerir; los aplica la sede desde Aspecto, si quiere. */
    public const PALETTE_KEYS = ['accent', 'deal_color'];

    protected $fillable = [
        'name', 'slug', 'description', 'audience', 'audience_group', 'palette_suggestion', 'sort_order',
        'preview_media_id', 'draft', 'published', 'published_at', 'published_by_user_id', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'published' => 'array',
            'palette_suggestion' => 'array',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Los colores sugeridos que se pueden aplicar: solo las claves conocidas y hexadecimales
     * validos. Lo demas que traiga el JSON se ignora.
     *
     * @return array<string, string>
     */
    public function paletteSuggestion(): array
    {
        return array_filter(
            array_intersect_key($this->palette_suggestion ?? [], array_flip(self::PALETTE_KEYS)),
            fn ($value) => is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1,
        );
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

    /**
     * La miniatura entera para la galeria: la copia angosta si existe, si no el original. No la
     * variante `wide`: recortada a 16:9 se comia el nombre que lleva abajo.
     */
    public function previewThumbnail(): ?string
    {
        $payload = $this->previewMedia?->toPayload();

        return $payload ? ($payload['variants']['sm'] ?? $payload['url']) : null;
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
