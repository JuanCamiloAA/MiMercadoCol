<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\PreviewController;
use App\Http\Requests\Platform\SaveStoreTemplateRequest;
use App\Models\AccessLog;
use App\Models\Company;
use App\Models\Store;
use App\Models\StorefrontTemplate;
use App\Models\StorefrontTemplateVersion;
use App\Services\Storefront\StorefrontEditorPayload;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPreviewBuilder;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\Blocks\BlockData;
use App\Support\SectionAppearance;
use App\Support\StorefrontContext;
use App\Support\VideoOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las plantillas base de la tienda, del super usuario.
 *
 * Una plantilla es solo diseño. Las tiendas nuevas nacen con la de por defecto y la siguen
 * hasta que se personalizan. Se edita con el mismo editor de las sedes (contexto `template`):
 * sin identidad de marca, sin la parte operativa de Funciones y sin ids de ninguna empresa.
 */
class StoreTemplateController extends Controller
{
    public function __construct(
        protected StorefrontTemplateService $templates,
        protected StorefrontManager $manager,
        protected StorefrontRenderer $renderer,
        protected StorefrontEditorPayload $payload,
        protected StorefrontPreviewBuilder $preview,
    ) {}

    public function index(Request $request): Response
    {
        $templates = StorefrontTemplate::query()
            ->withCount('followers')
            ->with(['publishedBy:id,name,last_name', 'previewMedia'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (StorefrontTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'audience' => $template->audience,
                'audience_group' => $template->audience_group,
                'sort_order' => $template->sort_order,
                'is_default' => $template->is_default,
                'is_active' => $template->is_active,
                'published_at' => $template->published_at?->toIso8601String(),
                'published_by' => $template->publishedBy?->full_name,
                'followers' => $template->followers_count,
                'has_unpublished_changes' => $template->published === null || $template->draft != $template->published,
                'blocks' => count($template->draft['blocks'] ?? []),
                'preview' => $template->previewThumbnail(),
            ]);

        return Inertia::render('Platform/StoreTemplates/Index', [
            'templates' => $templates,
            'groups' => StorefrontTemplate::AUDIENCE_GROUPS,
            'can' => [
                'edit' => $request->user()->can('platform.store_templates.edit'),
                'publish' => $request->user()->can('platform.store_templates.publish'),
            ],
        ]);
    }

    public function store(SaveStoreTemplateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $template = $this->templates->create($validated['name'], $validated['description'] ?? null, null, $request->safe()->only(['audience', 'audience_group']));

        // Partir de otra plantilla ahorra armarla desde cero.
        if (! empty($validated['copy_from'])) {
            $source = StorefrontTemplate::query()->findOrFail($validated['copy_from']);
            $template->forceFill(['draft' => $source->draft])->save();
        }

        return redirect()->route('platform.store_templates.edit', $template)->with('success', 'La plantilla quedó creada. Ármala y publícala.');
    }

    public function update(SaveStoreTemplateRequest $request, StorefrontTemplate $template): RedirectResponse
    {
        $template->update($request->safe()->only(['name', 'description', 'audience', 'audience_group']));

        return back()->with('success', 'Se guardó la plantilla.');
    }

    /** Una copia en borrador para partir de ella sin tocar la original ni a quienes la siguen. */
    public function duplicate(StorefrontTemplate $template): RedirectResponse
    {
        $copy = $this->templates->duplicate($template);

        return redirect()->route('platform.store_templates.edit', $copy)->with('success', 'Quedó la copia «'.$copy->name.'», sin publicar. Ajústala y publícala cuando esté lista.');
    }

    /** El editor de la tienda en contexto de plantilla. */
    public function edit(Request $request, StorefrontTemplate $template): Response
    {
        $store = $this->previewStore($request);
        $draft = $template->draft ?? $this->templates->emptyDraft();
        $defaults = $this->manager->defaultSettings();

        return Inertia::render('Storefront/Editor/Editor', [
            'context' => BlockData::CONTEXT_TEMPLATE,
            'storefront' => [
                'id' => $template->id,
                'slug' => Str::slug($template->name),
                'is_online' => $template->is_active,
                'store' => ['id' => $store?->id ?? 0, 'name' => $template->name],
                'has_unpublished_changes' => $template->published === null || $template->draft != $template->published,
                'last_saved_at' => $template->updated_at?->toIso8601String(),
                'published_at' => $template->published_at?->toIso8601String(),
                'published_by' => $template->publishedBy?->full_name,
                'published_version' => $template->versions()->max('number'),
                'preview_url' => $store ? route('platform.store_templates.preview', [$template, 'store' => $store->id]) : '',
            ],
            'blocks' => collect($draft['blocks'] ?? [])->map(fn (array $block) => $this->payload->blockRow($block, (string) $block['uuid']))->values()->all(),
            'catalogue' => $this->payload->catalogue(BlockData::CONTEXT_TEMPLATE),
            // Las opciones de la pestaña «Diseño» de cada bloque (tamaño, fondo, animacion).
            'appearanceCatalogue' => SectionAppearance::groups(SectionAppearance::SURFACE_STORE),
            // La forma de la plantilla sobre un tema neutro: la marca la pone cada sede.
            'theme' => array_merge([
                'display_name' => null, 'tagline' => null, 'accent' => '#0E6E4E', 'accent_ink' => '#FFFFFF',
                'deal_color' => '#D92D20', 'deal_ink' => '#FFFFFF', 'logo' => null, 'favicon' => null, 'mark' => null,
            ], $this->templates->emptyDraft()['theme'], $draft['theme'] ?? []),
            'settings' => collect($defaults)
                ->map(fn (array $default, string $key) => array_merge($default, is_array($draft['settings'][$key] ?? null) ? $draft['settings'][$key] : []))
                ->all(),
            'zones' => [],
            'slots' => [],
            'checks' => $this->templates->validate($template),
            'fieldOptions' => ['categories' => [], 'promotions' => []],
            'structureOptions' => $this->payload->structureOptions(BlockData::CONTEXT_TEMPLATE),
            'previewOptions' => $store ? $this->preview->options($store) : ['categories' => [], 'products' => []],
            'previewStores' => $this->storeOptions(),
            'icons' => config('storefront_icons'),
            'stores' => [],
            'template' => [
                'follows' => false,
                'id' => $template->id,
                'name' => $template->name,
                'is_default' => $template->is_default,
                'followers' => $template->followers()->count(),
                'available' => [],
            ],
            'endpoints' => $this->endpoints($template),
            'permissions' => [
                'content' => $request->user()->can('platform.store_templates.edit'),
                'appearance' => $request->user()->can('platform.store_templates.edit'),
                'settings' => $request->user()->can('platform.store_templates.edit'),
                'publish' => $request->user()->can('platform.store_templates.publish'),
                'history' => $request->user()->can('platform.store_templates.view'),
                'copy_store' => false,
                'coupons' => false,
            ],
            'media' => ['max_kb' => 4096, 'accept' => '.jpg,.jpeg,.png,.webp', 'video_max_kb' => VideoOptions::MAX_VIDEO_KB, 'video_accept' => VideoOptions::ACCEPT],
        ]);
    }

    /**
     * La plantilla vista con el catalogo de una sede: la plantilla no tiene articulos propios.
     */
    public function preview(Request $request, StorefrontTemplate $template): Response
    {
        $validated = PreviewController::validatePreview($request);
        $store = $this->previewStore($request);

        abort_if($store === null, 404, 'No hay ninguna sede para ver la plantilla.');

        return Inertia::render('Storefront/Preview', $this->preview->build(
            $this->renderer->templateSnapshot($template->draft ?? $this->templates->emptyDraft(), $store),
            $store,
            $validated['page'] ?? 'home',
            isset($validated['category']) ? (int) $validated['category'] : null,
            isset($validated['product']) ? (int) $validated['product'] : null,
            $validated['device'] ?? null,
            $validated['scheme'] ?? null,
        ));
    }

    public function publish(Request $request, StorefrontTemplate $template): RedirectResponse
    {
        $validated = $request->validate(['label' => ['nullable', 'string', 'max:80']]);
        $version = $this->templates->publish($template, $request->user(), $validated['label'] ?? null);

        AccessLog::log('storefront_template_published', $request->user()->id, [
            'template_id' => $template->id,
            'version' => $version->number,
            'followers' => $template->followers()->count(),
        ]);

        return back()->with('success', 'La plantilla quedó publicada (versión '.$version->number.'). Le llega a las tiendas que la siguen.');
    }

    public function history(StorefrontTemplate $template): JsonResponse
    {
        $versions = $template->versions()->with('author:id,name,last_name')->orderByDesc('number')->limit(30)->get();
        $current = $template->published;

        return response()->json([
            'versions' => $versions->values()->map(fn (StorefrontTemplateVersion $version, int $index) => [
                'id' => $version->id,
                'number' => $version->number,
                'label' => $version->label,
                'author' => $version->author?->full_name,
                'published_at' => $version->published_at?->toIso8601String(),
                'is_current' => $index === 0 && $version->snapshot == $current,
                'changes' => [count($version->snapshot['blocks'] ?? []).' bloques'],
            ]),
        ]);
    }

    public function restore(Request $request, StorefrontTemplate $template, StorefrontTemplateVersion $version): RedirectResponse
    {
        $copy = $this->templates->restore($template, $version, $request->user());

        return back()->with('success', 'La plantilla volvió a la versión '.$version->number.' (publicada como la '.$copy->number.').');
    }

    public function setDefault(StorefrontTemplate $template): RedirectResponse
    {
        $this->templates->setDefault($template);

        return back()->with('success', '«'.$template->name.'» es ahora la plantilla con que nacen las tiendas nuevas.');
    }

    public function toggle(Request $request, StorefrontTemplate $template): RedirectResponse
    {
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $this->templates->setActive($template, $active);

        return back()->with('success', $active ? 'La plantilla quedó activa.' : 'La plantilla quedó inactiva: ya no se puede aplicar.');
    }

    /**
     * La sede con cuyo catalogo se ve la plantilla: la elegida, o la primera que vende.
     */
    protected function previewStore(Request $request): ?Store
    {
        $id = $request->integer('store');

        return ($id ? Store::query()->withoutGlobalScopes()->with('company')->find($id) : null)
            ?? StorefrontContext::sellable()->with('company')->orderBy('stores.id')->first()
            ?? Store::query()->withoutGlobalScopes()->with('company')->orderBy('id')->first();
    }

    /**
     * «Ver con: Mercado El Vecino — Bello Niquía».
     *
     * @return list<array{value: int, label: string}>
     */
    protected function storeOptions(): array
    {
        $companies = Company::query()->pluck('name', 'id');

        return Store::query()->withoutGlobalScopes()->where('is_active', true)->orderBy('company_id')->orderBy('name')->get(['id', 'name', 'company_id'])
            ->map(fn (Store $store) => ['value' => $store->id, 'label' => ($companies[$store->company_id] ?? '').' — '.$store->name])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function endpoints(StorefrontTemplate $template): array
    {
        $settings = Str::beforeLast(route('platform.store_templates.settings', [$template, 'topbar']), '/');

        return [
            'theme' => route('platform.store_templates.theme', $template),
            'settings' => $settings,
            'structure' => $settings,
            'look' => $settings,
            'blocks_store' => route('platform.store_templates.blocks.store', $template),
            'block_update' => route('platform.store_templates.blocks.update', [$template, '__ID__']),
            'block_duplicate' => route('platform.store_templates.blocks.duplicate', [$template, '__ID__']),
            'block_destroy' => route('platform.store_templates.blocks.destroy', [$template, '__ID__']),
            'blocks_reorder' => route('platform.store_templates.blocks.reorder', $template),
            'publish' => route('platform.store_templates.publish', $template),
            'history' => route('platform.store_templates.history', $template),
            'restore' => route('platform.store_templates.restore', [$template, '__ID__']),
            'products' => '',
            'media_index' => route('platform.store_templates.media.index'),
            'media_store' => route('platform.store_templates.media.store'),
            'media_video_store' => route('platform.store_templates.media.store-video'),
            'detach' => '',
            'reattach' => '',
            'preview_base' => route('platform.store_templates.preview', $template),
        ];
    }
}
