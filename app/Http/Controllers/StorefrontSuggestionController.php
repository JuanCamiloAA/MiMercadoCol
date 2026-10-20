<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesStorefrontAssortment;
use App\Http\Requests\Storefront\DismissSuggestionRequest;
use App\Models\StorefrontSuggestionDismissal;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Que conviene publicar: articulos con disponible en la bodega de la tienda que todavia no estan
 * en linea, agrupados por la razon para publicarlos. Los umbrales viven en Ajustes
 * (`storefront_catalog.*`), no en la pantalla.
 */
class StorefrontSuggestionController extends Controller
{
    use ManagesStorefrontAssortment;

    public function index(Request $request): Response
    {
        $this->freshAssortment();
        $companyId = TenantContext::requireCompanyId();
        $store = $this->assortmentStore($request);
        $context = $this->assortment->context($companyId, $store);
        $suggestions = $this->assortment->suggestions($companyId, $store);
        $dismissed = $this->assortment->dismissals($companyId, $store);

        return Inertia::render('Storefront/Suggestions', [
            'stores' => $this->assortmentStores($request),
            'store_id' => $store->id,
            'warehouse' => ['id' => $context['warehouse_id'], 'name' => $context['warehouse']->name],
            'groups' => $suggestions['groups'],
            'total' => $suggestions['total'],
            'top_seller_ids' => $suggestions['top_seller_ids'],
            'coverage' => $this->assortment->categoryCoverage($companyId, $store, null),
            'dismissed' => $dismissed,
            'can' => $this->assortmentCan($request->user()),
        ]);
    }

    public function dismiss(DismissSuggestionRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $request->validated();

        StorefrontSuggestionDismissal::query()->withoutGlobalScopes()->updateOrCreate(
            ['store_id' => (int) $validated['store_id'], 'product_id' => (int) $validated['product_id'], 'group' => $validated['group']],
            ['company_id' => $companyId, 'dismissed_by_user_id' => $request->user()->id, 'dismissed_at' => now()],
        );

        return back()->with('success', 'Sugerencia descartada en esta sede. La encuentras en «Ver descartados».');
    }

    public function restore(DismissSuggestionRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $request->validated();

        StorefrontSuggestionDismissal::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', (int) $validated['store_id'])
            ->where('product_id', (int) $validated['product_id'])
            ->where('group', $validated['group'])
            ->delete();

        return back()->with('success', 'La sugerencia volvió a la lista.');
    }
}
