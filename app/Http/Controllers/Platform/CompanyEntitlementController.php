<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\EndCompanyEntitlementRequest;
use App\Http\Requests\Platform\StoreCompanyEntitlementRequest;
use App\Http\Requests\Platform\StoreCompanyPlanPriceRequest;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyPlanPrice;
use App\Services\Membership\CompanyEntitlementService;
use Illuminate\Http\RedirectResponse;

/**
 * Plataforma > Empresas > Plan y funciones: excepciones al plan y precio especial.
 */
class CompanyEntitlementController extends Controller
{
    public function __construct(protected CompanyEntitlementService $service) {}

    public function store(StoreCompanyEntitlementRequest $request, Company $company): RedirectResponse
    {
        $exception = $this->service->addException($company, $request->validated(), $request->user());

        return back()->with('success', $exception->effect === CompanyEntitlement::EFFECT_GRANT
            ? 'La función quedó sumada al plan de la empresa.'
            : 'La función quedó quitada del plan de la empresa.');
    }

    public function end(EndCompanyEntitlementRequest $request, Company $company, CompanyEntitlement $exception): RedirectResponse
    {
        abort_unless((int) $exception->company_id === (int) $company->id, 404);

        $this->service->endException($exception, $request->user(), $request->validated('reason'));

        return back()->with('success', 'La excepción quedó terminada.');
    }

    public function storePrice(StoreCompanyPlanPriceRequest $request, Company $company): RedirectResponse
    {
        $price = $this->service->setSpecialPrice($company, $request->validated(), $request->user());

        return back()->with('success', 'Precio especial guardado: '.$price->describe().'.');
    }

    public function endPrice(EndCompanyEntitlementRequest $request, Company $company, CompanyPlanPrice $price): RedirectResponse
    {
        abort_unless((int) $price->company_id === (int) $company->id, 404);

        $this->service->endSpecialPrice($price, $request->user(), $request->validated('reason'));

        return back()->with('success', 'El precio especial quedó terminado.');
    }
}
