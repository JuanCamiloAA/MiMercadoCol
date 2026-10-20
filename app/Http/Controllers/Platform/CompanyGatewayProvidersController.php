<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateCompanyGatewayProvidersRequest;
use App\Models\PaymentGatewayAccount;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\PaymentGateway;
use App\Services\PlatformSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Pasarelas para empresas: que proveedores pueden conectar las empresas a SU cuenta,
 * para cobrar sus ventas. Apagar uno (porque falla) lo saca de todas las cajas y tiendas sin
 * tocar las cuentas de nadie.
 *
 * No es «Pasarela de pagos», que es la cuenta con que la plataforma cobra las membresias.
 */
class CompanyGatewayProvidersController extends Controller
{
    public function __construct(
        protected GatewayRegistry $gateways,
        protected PlatformSettingsService $platform,
    ) {}

    public function index(): Response
    {
        $counts = PaymentGatewayAccount::query()->withoutGlobalScopes()
            ->groupBy('provider')
            ->select('provider', DB::raw('COUNT(*) as accounts'), DB::raw('SUM(is_enabled) as enabled'))
            ->get()
            ->keyBy('provider');

        return Inertia::render('Platform/CompanyGateways/Index', [
            'providers' => collect($this->gateways->catalogue(onlyEnabled: false))->map(fn (array $row) => [
                'provider' => $row['provider'],
                'label' => $row['label'],
                'capabilities' => $row['capabilities'],
                'enabled' => $row['enabled'],
                'accounts' => (int) ($counts[$row['provider']]->accounts ?? 0),
                'accounts_enabled' => (int) ($counts[$row['provider']]->enabled ?? 0),
            ])->values(),
            'capabilityLabels' => PaymentGateway::CAPABILITY_LABELS,
        ]);
    }

    public function update(UpdateCompanyGatewayProvidersRequest $request): RedirectResponse
    {
        $this->platform->set('payments.company_providers', array_values($request->validated('providers')));

        return back()->with('success', 'Las pasarelas para empresas quedaron guardadas.');
    }
}
