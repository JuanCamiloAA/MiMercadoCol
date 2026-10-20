<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\StorefrontTemplate;
use App\Models\StorefrontTheme;
use App\Services\Storefront\StorefrontPublisher;
use App\Support\Color;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El aspecto de la tienda de una sede: su marca (nombre, lema, logo, isotipo, favicon), sus
 * colores (acento y rojo de oferta) y la forma (tipografia, esquinas, densidad, esquema).
 *
 * Si la sede sigue una plantilla, la forma la pone la plantilla: aqui solo se guarda la marca.
 */
class ThemeController extends Controller
{
    use ResolvesStorefront;

    public function __construct(protected StorefrontPublisher $publisher) {}

    public function update(Request $request): RedirectResponse
    {
        $storefront = $this->storefront();
        $companyMedia = fn () => Rule::exists('media_assets', 'id')->where('company_id', $storefront->company_id);

        $validated = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'accent' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'deal_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'scheme' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['scheme'])],
            'allow_scheme_toggle' => ['sometimes', 'boolean'],
            'font_pair' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['font_pair'])],
            'radius' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['radius'])],
            'density' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['density'])],
            'button_style' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['button_style'])],
            'logo_font' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['logo_font'])],
            'surface' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['surface'])],
            'heading_case' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['heading_case'])],
            'container' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['container'])],
            'button_shape' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['button_shape'])],
            // La marca es de la empresa: nada de medios de otra empresa ni de la plataforma.
            'logo_media_id' => ['sometimes', 'nullable', 'integer', $companyMedia()],
            'favicon_media_id' => ['sometimes', 'nullable', 'integer', $companyMedia()],
            'mark_media_id' => ['sometimes', 'nullable', 'integer', $companyMedia()],
        ], [
            'accent.regex' => 'El color va en formato #RRGGBB.',
            'deal_color.regex' => 'El color va en formato #RRGGBB.',
            'logo_media_id.exists' => 'Esa imagen no está en la biblioteca de la empresa.',
            'favicon_media_id.exists' => 'Esa imagen no está en la biblioteca de la empresa.',
            'mark_media_id.exists' => 'Esa imagen no está en la biblioteca de la empresa.',
        ]);

        // Un color sobre el que no se lee el texto no se guarda: se avisa en el momento.
        if (isset($validated['accent']) && $this->publisher->contrast($validated['accent']) < 4.5) {
            return back()->withErrors([
                'accent' => 'Sobre ese color no se lee ni el texto blanco ni el negro. Elige uno con más contraste.',
            ]);
        }

        // El rojo de oferta va como precio sobre blanco: tiene que leerse ahí.
        if (isset($validated['deal_color']) && Color::contrast($validated['deal_color'], '#FFFFFF') < 4.5) {
            return back()->withErrors([
                'deal_color' => 'Ese rojo no se lee sobre fondo blanco (necesita 4,5:1). Elige uno más oscuro.',
            ]);
        }

        // Con la plantilla, la forma la pone ella: se guarda solo lo que es de la sede.
        if ($storefront->follows_template) {
            $validated = array_diff_key($validated, array_flip(StorefrontTemplate::DESIGN_KEYS['theme']));
        }

        if ($validated !== []) {
            $storefront->theme()->updateOrCreate(['storefront_id' => $storefront->id], $validated);
        }

        return back(303);
    }
}
