<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePromotionRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Services\PromotionService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Promociones de la tienda.
 *
 * Cuatro tipos con la misma ficha: a quien aplica, desde cuando, en que canal y con que
 * etiqueta se le muestra al cliente. Lo que cambia entre uno y otro son dos o tres campos,
 * asi que hay una sola pantalla y no cuatro.
 */
class PromotionController extends Controller
{
    public function __construct(protected PromotionService $promotions) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'type' => $request->string('type')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $promotions = Promotion::query()
            ->where('company_id', $companyId)
            ->with('targets')
            ->withCount('targets')
            ->when($filters['search'], fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($filters['type'], fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] === 'running', fn ($query) => $query->running())
            ->when($filters['status'] === 'off', fn ($query) => $query->where('is_active', false))
            ->when($filters['status'] === 'ended', fn ($query) => $query->whereNotNull('ends_at')->where('ends_at', '<', now()))
            ->orderByDesc('is_active')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Promotion $promotion) => $this->present($promotion));

        return Inertia::render('Promotions/Index', [
            'promotions' => $promotions,
            'filters' => $filters,
            'metrics' => [
                'running' => Promotion::query()->where('company_id', $companyId)->running()->count(),
                'scheduled' => Promotion::query()->where('company_id', $companyId)
                    ->where('is_active', true)->whereNotNull('starts_at')->where('starts_at', '>', now())->count(),
                'ended' => Promotion::query()->where('company_id', $companyId)
                    ->whereNotNull('ends_at')->where('ends_at', '<', now())->count(),
            ],
            'types' => collect(Promotion::TYPE_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'categories' => Category::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
                ->all(),
        ]);
    }

    /**
     * Buscador del armador de promociones: articulos y, primero, los articulos con subarticulos,
     * que alcanzan a todos sus subarticulos en una sola entrada.
     */
    public function products(Request $request): \Illuminate\Http\JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $search = $request->string('search')->toString();

        $families = ProductFamily::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($search, fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->withMin(['variants as price_from' => fn ($query) => $query->where('is_active', true)], 'price')
            ->withCount('variants')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (ProductFamily $family) => [
                'target_type' => PromotionTarget::TYPE_FAMILY,
                'id' => $family->id,
                'name' => $family->name,
                'sku' => $family->code ?? $family->variants_count.' subartículos',
                'price' => (int) $family->price_from,
                'online_price' => null,
            ]);

        return response()->json($families->concat(
            Product::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->when($search, fn ($query) => $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
                }))
                ->orderBy('name')
                ->limit(25)
                ->get(['id', 'name', 'sku', 'price', 'online_price'])
                ->map(fn (Product $product) => [
                    'target_type' => PromotionTarget::TYPE_PRODUCT,
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'price' => (int) $product->price,
                    'online_price' => $product->online_price ? (int) $product->online_price : null,
                ])
        )->values());
    }

    public function store(StorePromotionRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $promotion = DB::transaction(function () use ($request, $companyId) {
            $promotion = Promotion::create(array_merge($request->promotionData(), [
                'company_id' => $companyId,
                'user_id' => $request->user()->id,
            ]));

            $this->syncTargets($promotion, $request->targets());

            return $promotion;
        });

        return redirect()->route('promotions.index')
            ->with('success', "La promocion «{$promotion->name}» quedo creada.");
    }

    public function update(StorePromotionRequest $request, Promotion $promotion): RedirectResponse
    {
        $this->authorizeCompany($promotion);

        DB::transaction(function () use ($request, $promotion) {
            $promotion->update($request->promotionData());
            $this->syncTargets($promotion, $request->targets());
        });

        return back()->with('success', 'La promocion quedo actualizada.');
    }

    public function toggle(Request $request, Promotion $promotion): RedirectResponse
    {
        $this->authorizeCompany($promotion);

        $promotion->update(['is_active' => ! $promotion->is_active]);

        return back()->with('success', $promotion->is_active ? 'La promocion quedo encendida.' : 'La promocion quedo apagada.');
    }

    public function destroy(Promotion $promotion): RedirectResponse
    {
        $this->authorizeCompany($promotion);

        $promotion->delete();

        return back()->with('success', 'La promocion quedo eliminada.');
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    protected function syncTargets(Promotion $promotion, array $targets): void
    {
        $promotion->targets()->delete();

        foreach ($targets as $target) {
            $promotion->targets()->create([
                'target_type' => $target['target_type'],
                'target_id' => (int) $target['target_id'],
                'value' => isset($target['value']) ? (int) $target['value'] : null,
                'quantity' => (float) ($target['quantity'] ?? 1),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Promotion $promotion): array
    {
        $productIds = $promotion->targets
            ->where('target_type', PromotionTarget::TYPE_PRODUCT)
            ->pluck('target_id');

        $products = Product::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'sku', 'price', 'online_price'])
            ->keyBy('id');

        $categories = Category::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $promotion->targets->where('target_type', PromotionTarget::TYPE_CATEGORY)->pluck('target_id'))
            ->get(['id', 'name'])
            ->keyBy('id');

        $families = ProductFamily::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $promotion->targets->where('target_type', PromotionTarget::TYPE_FAMILY)->pluck('target_id'))
            ->get(['id', 'name'])
            ->keyBy('id');

        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'type' => $promotion->type,
            'type_label' => $promotion->typeLabel(),
            'badge' => $promotion->badge(),
            'label' => $promotion->label,
            'color' => $promotion->color,
            'starts_at' => $promotion->starts_at?->toDateString(),
            'ends_at' => $promotion->ends_at?->toDateString(),
            'applies_online' => $promotion->applies_online,
            'applies_pos' => $promotion->applies_pos,
            'priority' => $promotion->priority,
            'config' => $promotion->config ?? [],
            'is_active' => $promotion->is_active,
            'is_running' => $promotion->is_active
                && ($promotion->starts_at === null || $promotion->starts_at->isPast())
                && ($promotion->ends_at === null || $promotion->ends_at->isFuture()),
            'targets_count' => $promotion->targets_count,
            'targets' => $promotion->targets->map(fn (PromotionTarget $target) => [
                'target_type' => $target->target_type,
                'target_id' => $target->target_id,
                'value' => $target->value,
                'quantity' => (float) $target->quantity,
                'label' => match ($target->target_type) {
                    PromotionTarget::TYPE_PRODUCT => $products[$target->target_id]->name ?? 'Articulo eliminado',
                    PromotionTarget::TYPE_FAMILY => $families[$target->target_id]->name ?? 'Articulo eliminado',
                    default => $categories[$target->target_id]->name ?? 'Categoria eliminada',
                },
                'price' => $target->target_type === PromotionTarget::TYPE_PRODUCT
                    ? (int) ($products[$target->target_id]->online_price ?: $products[$target->target_id]->price ?? 0)
                    : null,
            ])->values()->all(),
        ];
    }

    protected function authorizeCompany(Promotion $promotion): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $promotion->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa promocion no pertenece a tu empresa.'
        );
    }
}
