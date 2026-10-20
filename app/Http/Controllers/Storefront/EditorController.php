<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Models\StorefrontTemplate;
use App\Services\Storefront\StorefrontEditorPayload;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPreviewBuilder;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontRenderer;
use App\Storefront\Blocks\BlockData;
use App\Support\SectionAppearance;
use App\Support\StoreContext;
use App\Support\TenantContext;
use App\Support\VideoOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El editor de la tienda.
 *
 * Una sola pantalla con tres pestañas —Aspecto, Contenido y Funciones— sobre el borrador de la
 * sede activa. Cambiar de sede en el selector cambia de borrador: aqui no hay nada compartido
 * entre sedes salvo el catalogo de articulos.
 *
 * Es el mismo editor que usa el super usuario para la plantilla base (contexto `template`,
 * `Platform\StoreTemplateController`): cambian las rutas y lo que se ofrece, no el editor.
 */
class EditorController extends Controller
{
    public function __construct(
        protected StorefrontManager $manager,
        protected StorefrontPublisher $publisher,
        protected StorefrontRenderer $renderer,
        protected StorefrontEditorPayload $payload,
        protected StorefrontPreviewBuilder $preview,
    ) {}

    public function index(Request $request): Response
    {
        $store = $this->activeStore();
        $storefront = $this->manager->for($store);
        $follows = $storefront->usesTemplate();

        // Si sigue la plantilla, el editor muestra el diseño de la plantilla (sin poder tocarlo
        // hasta personalizar) y lo propio de la sede.
        $composed = $follows ? $this->renderer->snapshotFor($storefront, draft: true) : null;

        return Inertia::render('Storefront/Editor/Editor', [
            'context' => BlockData::CONTEXT_STORE,
            'storefront' => $this->storefrontPayload($storefront, $store),
            'blocks' => $follows
                ? collect($composed['blocks'] ?? [])->map(fn (array $block) => $this->payload->blockRow($block, (string) ($block['uuid'] ?? '')))->values()->all()
                : $storefront->blocks->map(fn (StorefrontBlock $block) => $this->payload->blockRow([
                    ...$block->only(['uuid', 'type', 'page', 'sort_order', 'is_visible', 'devices', 'payload']),
                    'visible_from' => $block->visible_from?->format('Y-m-d\TH:i'),
                    'visible_to' => $block->visible_to?->format('Y-m-d\TH:i'),
                ], $block->id))->values()->all(),
            'catalogue' => $this->payload->catalogue(BlockData::CONTEXT_STORE),
            // Las opciones de la pestaña «Diseño» de cada bloque (tamaño, fondo, animacion).
            'appearanceCatalogue' => SectionAppearance::groups(SectionAppearance::SURFACE_STORE),
            'theme' => $follows ? array_merge($this->manager->themePayload($storefront), $composed['theme'] ?? []) : $this->manager->themePayload($storefront),
            'settings' => $this->settingsPayload($storefront, $follows ? ($composed['settings'] ?? []) : null),
            'zones' => $storefront->zones->map(fn ($zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'fee' => $zone->fee,
                'eta_minutes' => $zone->eta_minutes,
                'is_active' => $zone->is_active,
            ])->values(),
            'slots' => $storefront->slots->map(fn ($slot) => [
                'id' => $slot->id,
                'weekday' => $slot->weekday,
                'weekday_label' => $slot->weekdayLabel(),
                'starts_at' => substr((string) $slot->starts_at, 0, 5),
                'ends_at' => substr((string) $slot->ends_at, 0, 5),
                'capacity' => $slot->capacity,
                'is_active' => $slot->is_active,
            ])->values(),
            'checks' => $this->publisher->validate($storefront),
            'fieldOptions' => $this->fieldOptions($store),
            'structureOptions' => $this->payload->structureOptions(BlockData::CONTEXT_STORE),
            'previewOptions' => $this->preview->options($store),
            'icons' => config('storefront_icons'),
            'stores' => $this->otherStores($store),
            'template' => [
                'follows' => $follows,
                'id' => $storefront->template_id,
                'name' => $storefront->template?->name,
                // Los colores que sugiere su plantilla: Aspecto ofrece aplicarlos, nunca solos.
                'palette' => $storefront->template?->paletteSuggestion() ?: null,
                // La galeria del selector: miniatura, para quien y sus colores sugeridos.
                'available' => StorefrontTemplate::query()
                    ->with('previewMedia')
                    ->where('is_active', true)
                    ->whereNotNull('published')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (StorefrontTemplate $template) => [
                        ...$template->only(['id', 'name', 'description', 'is_default', 'audience', 'audience_group']),
                        'preview' => $template->previewThumbnail(),
                        'palette' => $template->paletteSuggestion() ?: null,
                    ])
                    ->values(),
            ],
            'endpoints' => $this->endpoints(),
            'permissions' => [
                'content' => $request->user()->can('storefront.editor.content'),
                'appearance' => $request->user()->can('storefront.editor.appearance'),
                'settings' => $request->user()->can('storefront.editor.settings'),
                'publish' => $request->user()->can('storefront.editor.publish'),
                'history' => $request->user()->can('storefront.editor.history'),
                'copy_store' => $request->user()->can('storefront.editor.copy_store'),
                // Los cupones van con las promociones del plan: sin ellas no se ofrece el enlace.
                'coupons' => $request->user()->can('coupons.index.view') && (bool) TenantContext::company()?->entitlement()->allowsNode('coupons.*'),
            ],
            'media' => ['max_kb' => 4096, 'accept' => '.jpg,.jpeg,.png,.webp', 'video_max_kb' => VideoOptions::MAX_VIDEO_KB, 'video_accept' => VideoOptions::ACCEPT],
        ]);
    }

    /**
     * Buscador de articulos del armador de estantes.
     */
    public function products(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $search = $request->string('search')->toString();

        return response()->json(
            Product::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->when($search, fn ($query) => $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
                }))
                ->orderBy('name')
                ->limit(25)
                ->get(['id', 'name', 'sku'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ])
        );
    }

    /**
     * Las rutas que usa el editor en este contexto. `__ID__` y `__KEY__` los reemplaza el
     * navegador con el bloque, la version o el ajuste.
     *
     * @return array<string, string>
     */
    protected function endpoints(): array
    {
        return [
            'theme' => route('storefront.editor.theme'),
            // Estas tres terminan en la clave del ajuste: el navegador le pega `/{clave}`.
            'settings' => Str::beforeLast(route('storefront.editor.settings', 'delivery'), '/'),
            'structure' => Str::beforeLast(route('storefront.editor.structure', 'topbar'), '/'),
            'look' => Str::beforeLast(route('storefront.editor.look', 'card'), '/'),
            'blocks_store' => route('storefront.editor.blocks.store'),
            'block_update' => route('storefront.editor.blocks.update', '__ID__'),
            'block_duplicate' => route('storefront.editor.blocks.duplicate', '__ID__'),
            'block_destroy' => route('storefront.editor.blocks.destroy', '__ID__'),
            'blocks_reorder' => route('storefront.editor.blocks.reorder'),
            'publish' => route('storefront.editor.publish'),
            'history' => route('storefront.editor.history'),
            'restore' => route('storefront.editor.restore', '__ID__'),
            'products' => route('storefront.editor.products'),
            'media_index' => route('media.index'),
            'media_store' => route('media.store'),
            'media_video_store' => route('media.store-video'),
            'detach' => route('storefront.editor.template.detach'),
            'reattach' => route('storefront.editor.template.reattach'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function storefrontPayload(Storefront $storefront, Store $store): array
    {
        $published = $storefront->publishedVersion;

        return [
            'id' => $storefront->id,
            'slug' => $storefront->slug,
            'is_online' => $storefront->is_online,
            'store' => ['id' => $store->id, 'name' => $store->name],
            'has_unpublished_changes' => $this->manager->hasUnpublishedChanges($storefront),
            'last_saved_at' => $this->manager->lastSavedAt($storefront),
            'published_at' => $storefront->published_at?->toIso8601String(),
            'published_by' => $storefront->publishedBy?->full_name,
            'published_version' => $published?->number,
            // Enlace firmado: nadie mas puede ver el borrador. La pagina, la categoria y el
            // articulo que se ven los cambia el editor sin volver a firmar.
            'preview_url' => URL::temporarySignedRoute(
                'storefront.preview',
                now()->addMinutes(30),
                ['store' => $store->id]
            ),
        ];
    }

    /**
     * Los ajustes del borrador, completos con sus valores por defecto. Con la plantilla, el
     * diseño que se muestra es el de la plantilla.
     *
     * @param  array<string, mixed>|null  $composed
     * @return array<string, mixed>
     */
    protected function settingsPayload(Storefront $storefront, ?array $composed = null): array
    {
        $defaults = $this->manager->defaultSettings();
        $saved = $composed ?? $storefront->settings->mapWithKeys(fn ($setting) => [$setting->key => $setting->value])->all();

        return collect($defaults)
            ->map(fn (array $default, string $key) => array_merge($default, is_array($saved[$key] ?? null) ? $saved[$key] : []))
            ->all();
    }

    /**
     * @return array<string, list<array{value: string|int, label: string}>>
     */
    protected function fieldOptions(Store $store): array
    {
        return [
            'categories' => Category::query()
                ->where('company_id', $store->company_id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name'])
                ->map(fn (Category $category) => ['value' => (string) $category->id, 'label' => $category->name])
                ->all(),
            'promotions' => Promotion::query()
                ->where('company_id', $store->company_id)
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Promotion $promotion) => ['value' => (string) $promotion->id, 'label' => $promotion->name])
                ->all(),
        ];
    }

    /**
     * Las otras sedes, para copiar configuracion entre ellas.
     *
     * @return list<array{value: int, label: string}>
     */
    protected function otherStores(Store $store): array
    {
        return Store::query()
            ->where('company_id', $store->company_id)
            ->where('is_active', true)
            ->whereKeyNot($store->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Store $other) => ['value' => $other->id, 'label' => $other->name])
            ->all();
    }

    protected function activeStore(): Store
    {
        return Store::query()->findOrFail(StoreContext::requireStoreId());
    }
}
