<?php

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Services\Landing\LandingManager;
use App\Services\Landing\LandingRenderer;
use App\Services\PlatformSettingsService;
use App\Support\HomeResolver;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La landing publica de la plataforma (`/`), el buscador de sedes cercanas y los textos legales.
 */
class LandingController extends Controller
{
    public function __construct(
        protected LandingManager $manager,
        protected LandingRenderer $renderer,
        protected PlatformSettingsService $platform,
    ) {}

    public function show(Request $request): Response
    {
        $this->manager->ensureSeeded();

        return Inertia::render('Landing/Public', [
            ...$this->pageProps($request),
            'blocks' => $this->renderer->resolve($this->manager->publishedBlocks()),
            'isPreview' => false,
        ]);
    }

    /**
     * Las sedes vendibles ordenadas por cercania. La ubicacion llega, se usa y se olvida: no se
     * guarda ni se escribe en ningun registro.
     */
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        // Lo mismo que la carga inicial: la proxima franja solo si el buscador publicado la muestra.
        $withNextSlot = $this->renderer->wantsNextSlot($this->manager->publishedBlocks());

        return response()->json([
            'stores' => $this->renderer->nearby((float) $validated['lat'], (float) $validated['lng'], $withNextSlot),
        ]);
    }

    public function terms(Request $request): Response
    {
        return Inertia::render('Landing/Legal', [
            ...$this->pageProps($request),
            'title' => 'Términos y condiciones',
            'body' => (string) $this->platform->get('landing.terms_text'),
        ]);
    }

    public function privacy(Request $request): Response
    {
        return Inertia::render('Landing/Legal', [
            ...$this->pageProps($request),
            'title' => 'Tratamiento de datos personales',
            'body' => (string) $this->platform->get('landing.privacy_text'),
        ]);
    }

    /**
     * Lo comun de las paginas publicas: marca de plataforma, SEO y a donde lleva cada acceso.
     *
     * @return array<string, mixed>
     */
    public function pageProps(Request $request): array
    {
        $user = $request->user('web');
        $image = $this->platform->get('landing.seo_image');
        $favicon = $this->platform->get('landing.favicon');
        $logo = $this->platform->get('platform.brand_logo');
        $logoDark = $this->platform->get('platform.brand_logo_dark');
        $mark = $this->platform->get('platform.brand_mark');

        return [
            'site' => [
                'name' => $this->platform->name(),
                // La landing sigue el tema de quien la mira: el encabezado escoge el logo de cada fondo.
                'logo_url' => is_string($logo) && $logo !== '' ? Uploads::url($logo) : null,
                'logo_dark_url' => is_string($logoDark) && $logoDark !== '' ? Uploads::url($logoDark) : null,
                'mark_url' => is_string($mark) && $mark !== '' ? Uploads::url($mark) : null,
                'seo_title' => $this->platform->get('landing.seo_title') ?: $this->platform->name(),
                'seo_description' => $this->platform->get('landing.seo_description'),
                'seo_image_url' => is_string($image) && $image !== '' ? Uploads::url($image) : null,
                'favicon_url' => is_string($favicon) && $favicon !== '' ? Uploads::url($favicon) : null,
                'whatsapp' => $this->platform->get('landing.whatsapp'),
                'support_email' => $this->platform->get('platform.support_email'),
                'terms_url' => route('legal.terms'),
                'privacy_url' => route('legal.privacy'),
                'login_url' => route('login'),
                'signup_url' => route('signup.create'),
                'contact_url' => route('signup.contact'),
            ],
            // Con sesion de personal, el encabezado lleva al panel en vez de al ingreso.
            'panel' => $user ? ['name' => $user->name, 'url' => url(HomeResolver::for($user))] : null,
        ];
    }
}
