<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Landing\LandingController;
use App\Landing\LandingBlockCatalogue;
use App\Models\AccessLog;
use App\Models\LandingBlock;
use App\Models\LandingVersion;
use App\Services\BrandThemeService;
use App\Services\Landing\LandingManager;
use App\Services\Landing\LandingRenderer;
use App\Services\PlatformSettingsService;
use App\Support\SectionAppearance;
use App\Support\Uploads;
use App\Support\VideoOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Landing: el editor por bloques, sus ajustes, publicar y el historial.
 */
class LandingAdminController extends Controller
{
    /** Los ajustes de la landing y de la marca de plataforma que se editan aqui. */
    public const SETTINGS = [
        'platform.name', 'platform.support_email', 'platform.leads_notify_email',
        'platform.brand_accent', 'platform.brand_logo', 'platform.brand_logo_dark', 'platform.brand_mark', 'platform.font_body', 'platform.font_display', 'platform.font_numeric',
        'landing.seo_title', 'landing.seo_description', 'landing.seo_image', 'landing.favicon', 'landing.whatsapp',
        'landing.terms_text', 'landing.privacy_text',
    ];

    public function __construct(
        protected LandingManager $manager,
        protected LandingRenderer $renderer,
        protected PlatformSettingsService $platform,
    ) {}

    public function index(): Response
    {
        $this->manager->ensureSeeded(request()->user());
        $latest = $this->manager->latestVersion();

        return Inertia::render('Platform/Landing/Editor', [
            'blocks' => $this->blocksPayload(),
            'catalogue' => LandingBlockCatalogue::toArray(),
            // Las opciones de la pestaña «Diseño» de cada seccion (tamaño, fondo, animacion).
            'appearanceCatalogue' => SectionAppearance::groups(SectionAppearance::SURFACE_LANDING),
            'icons' => array_values((array) config('landing_icons')),
            'settings' => $this->settingsPayload(),
            'fontOptions' => app(BrandThemeService::class)->fontOptions(),
            'versions' => LandingVersion::query()->with('publisher:id,name,last_name')->orderByDesc('number')->limit(20)->get()
                ->map(fn (LandingVersion $version) => [
                    'id' => $version->id,
                    'number' => $version->number,
                    'note' => $version->note,
                    'published_at' => $version->published_at?->toIso8601String(),
                    'publisher' => $version->publisher?->full_name,
                    'is_current' => $latest?->id === $version->id,
                ])->values(),
            'status' => $this->statusPayload(),
            'previewUrl' => route('platform.landing.preview'),
            'publicUrl' => url('/'),
        ]);
    }

    /** El borrador pintado con el mismo componente de la pagina publica, para el `iframe`. */
    public function preview(Request $request): Response
    {
        // El modo que el editor está revisando (Claro | Oscuro); sin él, el de la página.
        $scheme = $request->validate(['scheme' => ['nullable', Rule::in(['light', 'dark'])]])['scheme'] ?? null;

        return Inertia::render('Landing/Public', [
            ...app(LandingController::class)->pageProps($request),
            'blocks' => $this->renderer->resolve(array_values(array_filter($this->manager->draftSnapshot(), fn ($block) => $block['is_visible']))),
            'isPreview' => true,
            'previewScheme' => $scheme,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(LandingBlockCatalogue::all()))]]);
        $block = $this->manager->add($data['type']);

        return back()->with('success', LandingBlockCatalogue::for($block->type)->label().' agregado al borrador.');
    }

    /**
     * Guardado automatico de un bloque. Responde JSON: el editor no recarga la pagina entera
     * por cada letra.
     */
    public function update(Request $request, LandingBlock $block): JsonResponse
    {
        $request->validate(['data' => ['required', 'array']]);

        // El diseño de la seccion se rechaza, no se limpia: quien edita tiene que saber que eso no va.
        $violations = SectionAppearance::violations($request->input('data.appearance'), SectionAppearance::SURFACE_LANDING);

        if ($violations !== []) {
            throw ValidationException::withMessages(['data' => $violations]);
        }

        $block = $this->manager->update($block, $request->input('data'));

        return response()->json([
            'block' => $this->blockPayload($block),
            'status' => $this->statusPayload(),
        ]);
    }

    public function duplicate(LandingBlock $block): RedirectResponse
    {
        $this->manager->duplicate($block);

        return back()->with('success', 'Bloque duplicado.');
    }

    public function destroy(LandingBlock $block): RedirectResponse
    {
        $this->manager->remove($block);

        return back()->with('success', 'Bloque quitado del borrador.');
    }

    public function visibility(Request $request, LandingBlock $block): RedirectResponse
    {
        $data = $request->validate(['is_visible' => ['required', 'boolean']]);
        $this->manager->setVisible($block, (bool) $data['is_visible']);

        return back();
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate(['uuids' => ['required', 'array'], 'uuids.*' => ['string', 'max:40']]);
        $this->manager->reorder($data['uuids']);

        return back();
    }

    public function publish(Request $request): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:160']]);
        $version = $this->manager->publish($request->user(), $data['note'] ?? null);

        AccessLog::log('landing_published', $request->user()->id, ['version' => $version->number]);

        return back()->with('success', "La landing quedó publicada (versión {$version->number}).");
    }

    public function restore(Request $request, LandingVersion $version): RedirectResponse
    {
        $new = $this->manager->restore($version, $request->user());

        AccessLog::log('landing_restored', $request->user()->id, ['from' => $version->number, 'version' => $new->number]);

        return back()->with('success', "Se restauró la versión {$version->number} y quedó publicada como la {$new->number}.");
    }

    /**
     * Sube una imagen de la landing. Responde la ruta, que es lo que se guarda en el bloque.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'image.mimes' => 'La imagen debe ser JPG, PNG o WebP.',
            'image.max' => 'La imagen no puede pesar más de 4 MB.',
        ]);

        $file = $request->file('image');
        $path = $file->storeAs('landing/'.now()->format('Y/m'), Str::uuid().'.'.$file->extension(), Uploads::diskName());

        return response()->json(['path' => $path, 'url' => Uploads::url($path)]);
    }

    /**
     * Sube un video de la landing (el bloque «Video con pasos»). Como la imagen: responde la ruta,
     * que es lo que se guarda en el bloque, y el archivo queda en el disco de archivos subidos.
     */
    public function uploadVideo(Request $request): JsonResponse
    {
        [$rules, $messages] = VideoOptions::uploadRules();
        $request->validate(['video' => $rules], $messages);

        $file = $request->file('video');
        $path = $file->storeAs('landing/videos/'.now()->format('Y/m'), Str::uuid().'.'.$file->extension(), Uploads::diskName());

        return response()->json(['path' => $path, 'url' => Uploads::url($path)]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $fonts = (array) config('brand_fonts');
        $data = $request->validate([
            'platform_name' => ['nullable', 'string', 'max:60'],
            'support_email' => ['nullable', 'email', 'max:160'],
            'leads_notify_email' => ['nullable', 'email', 'max:160'],
            'brand_accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_logo' => ['nullable', 'string', 'regex:#^landing/[A-Za-z0-9/_\-.]+$#'],
            'brand_logo_dark' => ['nullable', 'string', 'regex:#^landing/[A-Za-z0-9/_\-.]+$#'],
            'brand_mark' => ['nullable', 'string', 'regex:#^landing/[A-Za-z0-9/_\-.]+$#'],
            'font_body' => ['required', Rule::in(array_keys($fonts['body'] ?? []))],
            'font_display' => ['required', Rule::in(array_keys($fonts['display'] ?? []))],
            'font_numeric' => ['required', Rule::in(array_keys($fonts['numeric'] ?? []))],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:170'],
            'seo_image' => ['nullable', 'string', 'regex:#^landing/[A-Za-z0-9/_\-.]+$#'],
            'favicon' => ['nullable', 'string', 'regex:#^landing/[A-Za-z0-9/_\-.]+$#'],
            'whatsapp' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'terms_text' => ['required', 'string', 'max:20000'],
            'privacy_text' => ['required', 'string', 'max:20000'],
        ], [
            'brand_accent.regex' => 'El color debe ser un hexadecimal como #0E6E4E.',
            'whatsapp.regex' => 'Escribe el WhatsApp con el indicativo, por ejemplo +57 300 123 4567.',
            'terms_text.required' => 'Los términos no pueden quedar vacíos.',
            'privacy_text.required' => 'La política de datos no puede quedar vacía.',
        ]);

        $this->platform->setMany([
            'platform.name' => ($data['platform_name'] ?? null) ?: null,
            'platform.support_email' => ($data['support_email'] ?? null) ?: null,
            'platform.leads_notify_email' => ($data['leads_notify_email'] ?? null) ?: null,
            'platform.brand_accent' => strtoupper($data['brand_accent']),
            'platform.brand_logo' => ($data['brand_logo'] ?? null) ?: null,
            'platform.brand_logo_dark' => ($data['brand_logo_dark'] ?? null) ?: null,
            'platform.brand_mark' => ($data['brand_mark'] ?? null) ?: null,
            'platform.font_body' => $data['font_body'],
            'platform.font_display' => $data['font_display'],
            'platform.font_numeric' => $data['font_numeric'],
            'landing.seo_title' => ($data['seo_title'] ?? null) ?: null,
            'landing.seo_description' => ($data['seo_description'] ?? null) ?: null,
            'landing.seo_image' => ($data['seo_image'] ?? null) ?: null,
            'landing.favicon' => ($data['favicon'] ?? null) ?: null,
            'landing.whatsapp' => ($data['whatsapp'] ?? null) ?: null,
            'landing.terms_text' => $data['terms_text'],
            'landing.privacy_text' => $data['privacy_text'],
        ]);

        return back()->with('success', 'Los ajustes de la landing quedaron guardados.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function blocksPayload(): array
    {
        return $this->manager->blocks()
            ->filter(fn (LandingBlock $block) => LandingBlockCatalogue::has($block->type))
            ->map(fn (LandingBlock $block) => $this->blockPayload($block))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function blockPayload(LandingBlock $block): array
    {
        $definition = LandingBlockCatalogue::for($block->type);
        $data = $definition->normalize($block->data ?? []);

        return [
            'id' => $block->id,
            'uuid' => $block->uuid,
            'type' => $block->type,
            'is_visible' => (bool) $block->is_visible,
            'data' => $data,
            // Por clave las imagenes y videos del bloque; por ruta, los de sus listas.
            'image_urls' => (object) $definition->mediaUrlMap($data),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusPayload(): array
    {
        $latest = $this->manager->latestVersion();

        return [
            'has_unpublished_changes' => $this->manager->hasUnpublishedChanges(),
            'published_version' => $latest?->number,
            'published_at' => $latest?->published_at?->toIso8601String(),
            'issues' => $this->manager->issues(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function settingsPayload(): array
    {
        $url = fn (?string $path) => Uploads::url($path);

        return [
            'platform_name' => $this->platform->get('platform.name') ?? '',
            'support_email' => $this->platform->get('platform.support_email') ?? '',
            'leads_notify_email' => $this->platform->get('platform.leads_notify_email') ?? '',
            'brand_accent' => $this->platform->get('platform.brand_accent'),
            'brand_logo' => $this->platform->get('platform.brand_logo'),
            'brand_logo_url' => $url($this->platform->get('platform.brand_logo')),
            'brand_logo_dark' => $this->platform->get('platform.brand_logo_dark'),
            'brand_logo_dark_url' => $url($this->platform->get('platform.brand_logo_dark')),
            'brand_mark' => $this->platform->get('platform.brand_mark'),
            'brand_mark_url' => $url($this->platform->get('platform.brand_mark')),
            'font_body' => $this->platform->get('platform.font_body'),
            'font_display' => $this->platform->get('platform.font_display'),
            'font_numeric' => $this->platform->get('platform.font_numeric'),
            'seo_title' => $this->platform->get('landing.seo_title') ?? '',
            'seo_description' => $this->platform->get('landing.seo_description') ?? '',
            'seo_image' => $this->platform->get('landing.seo_image'),
            'seo_image_url' => $url($this->platform->get('landing.seo_image')),
            'favicon' => $this->platform->get('landing.favicon'),
            'favicon_url' => $url($this->platform->get('landing.favicon')),
            'whatsapp' => $this->platform->get('landing.whatsapp') ?? '',
            'terms_text' => $this->platform->get('landing.terms_text'),
            'privacy_text' => $this->platform->get('landing.privacy_text'),
            'default_name' => config('app.name'),
        ];
    }
}
