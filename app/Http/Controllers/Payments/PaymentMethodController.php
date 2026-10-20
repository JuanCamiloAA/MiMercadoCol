<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Concerns\AuthorizesCompanyRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ReorderPaymentMethodsRequest;
use App\Http\Requests\Payments\StorePaymentMethodRequest;
use App\Http\Requests\Payments\UpdatePaymentMethodRequest;
use App\Models\Order;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\SalePayment;
use App\Models\Store;
use App\Payments\Gateways\GatewayRegistry;
use App\Realtime\ChangeCollector;
use App\Services\Payments\PaymentContext;
use App\Services\Payments\PaymentMethodAvailability;
use App\Services\Payments\PaymentMethodCatalog;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Medios de pago > Medios: lo que la empresa ofrece para cobrar, como se ve y cuando aparece.
 * Lo que vale en cada caja y en la tienda lo decide `PaymentMethodAvailability` con estas reglas.
 */
class PaymentMethodController extends Controller
{
    use AuthorizesCompanyRecords;

    public function __construct(
        protected PaymentMethodCatalog $catalog,
        protected PaymentMethodAvailability $availability,
        protected GatewayRegistry $gateways,
    ) {}

    public function index(): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $this->catalog->ensureDefaults($companyId);
        $entitlement = TenantContext::company()?->entitlement();
        $usage = SalePayment::query()
            ->whereIn('payment_method_id', PaymentMethod::query()->select('id'))
            ->groupBy('payment_method_id')
            ->selectRaw('payment_method_id, COUNT(*) as total')
            ->pluck('total', 'payment_method_id');

        $methods = PaymentMethod::query()->with('gatewayAccount')->ordered()->get()
            // El cambio por devolucion no se configura: lo arma la devolucion y nunca se muestra.
            ->reject(fn (PaymentMethod $method) => $method->kind === PaymentMethod::KIND_EXCHANGE);

        return Inertia::render('PaymentMethods/Index', [
            'methods' => $methods->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'kind' => $method->kind,
                'kind_label' => $method->kindLabel(),
                'kind_channels' => PaymentMethod::KIND_CHANNELS[$method->kind] ?? [],
                'online_group' => $method->onlineGroup(),
                'system_key' => $method->system_key,
                'is_system' => $method->is_system,
                'gateway_account_id' => $method->gateway_account_id,
                'gateway_label' => $method->gatewayAccount ? $this->accountLabel($method->gatewayAccount) : null,
                'icon' => $method->iconName(),
                'display' => [
                    'description_pos' => $method->description(PaymentMethod::CHANNEL_POS) ?? '',
                    'description_online' => $method->description(PaymentMethod::CHANNEL_ONLINE) ?? '',
                    'instructions' => $method->instructions() ?? '',
                    'highlight' => (bool) (($method->display ?? [])['highlight'] ?? false),
                ],
                'requires_reference' => (bool) $method->requires_reference,
                'reference_label' => $method->reference_label ?? '',
                'fee_percent' => (float) $method->fee_percent,
                'fee_fixed' => (int) $method->fee_fixed,
                'is_active' => (bool) $method->is_active,
                'availability' => (object) ($method->availability ?? []),
                'used' => (int) ($usage[$method->id] ?? 0),
                // Lo que impide que aparezca, aunque las reglas digan que si (la cuenta apagada...).
                'warning' => $method->isGateway() && $method->is_active
                    ? $this->availability->reason($method, new PaymentContext(PaymentMethod::KIND_CHANNELS[$method->kind][0] ?? PaymentMethod::CHANNEL_POS), static: true)
                    : null,
            ])->values(),
            'kinds' => collect(PaymentMethod::CONFIGURABLE_KINDS)->map(fn (string $kind) => [
                'value' => $kind,
                'label' => PaymentMethod::KIND_LABELS[$kind],
                'channels' => PaymentMethod::KIND_CHANNELS[$kind],
                'gateway' => in_array($kind, PaymentMethod::GATEWAY_KINDS, true),
                // Sin pasarelas en el plan, los de pasarela no se ofrecen.
                'available' => ! in_array($kind, PaymentMethod::GATEWAY_KINDS, true)
                    || (($entitlement?->allowsNode('payment_methods.gateways.*') ?? true)
                        && ($kind !== PaymentMethod::KIND_GATEWAY_TERMINAL || ($entitlement?->allowsNode('payment_methods.terminals.*') ?? true))),
            ])->values(),
            'accounts' => PaymentGatewayAccount::query()->orderBy('provider')->get()->map(fn (PaymentGatewayAccount $account) => [
                'id' => $account->id,
                'label' => $this->accountLabel($account),
                'provider' => $account->provider,
                'is_enabled' => $account->is_enabled,
                'capabilities' => $this->gateways->exists($account->provider) ? $this->gateways->for($account->provider)->capabilities() : [],
            ])->values(),
            'stores' => Store::query()->with(['registers' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get()
                ->map(fn (Store $store) => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'registers' => $store->registers->map(fn ($register) => ['id' => $register->id, 'name' => $register->name, 'code' => $register->code])->values(),
                ])->values(),
            'icons' => PaymentMethod::ICONS,
            'groupLabels' => PaymentMethod::GROUP_LABELS,
        ]);
    }

    public function store(StorePaymentMethodRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $data = $request->methodData();

        $method = PaymentMethod::query()->create([
            ...$data,
            'company_id' => $companyId,
            'is_system' => false,
            'sort_order' => (int) PaymentMethod::query()->max('sort_order') + 10,
        ]);

        $this->catalog->forget($companyId);

        return back()->with('success', "«{$method->name}» quedó creado.");
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $method): RedirectResponse
    {
        $this->authorizeCompany($method);
        abort_if($method->kind === PaymentMethod::KIND_EXCHANGE, 403, 'El cambio por devolución no se edita.');

        $method->update($request->methodData());
        $this->catalog->forget((int) $method->company_id);

        return back()->with('success', "«{$method->name}» quedó guardado.");
    }

    /** Encender o apagar. Apagado no aparece en ningun lado; sus ventas pasadas no cambian. */
    public function toggle(PaymentMethod $method): RedirectResponse
    {
        $this->authorizeCompany($method);
        abort_if($method->kind === PaymentMethod::KIND_EXCHANGE, 403, 'El cambio por devolución no se apaga.');

        $method->update(['is_active' => ! $method->is_active]);
        $this->catalog->forget((int) $method->company_id);

        return back()->with('success', $method->is_active ? "«{$method->name}» quedó encendido." : "«{$method->name}» quedó apagado.");
    }

    public function reorder(ReorderPaymentMethodsRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        DB::transaction(function () use ($request) {
            foreach ($request->validated('ids') as $position => $id) {
                PaymentMethod::query()->whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }
        });

        // El orden se escribe sin eventos de modelo: se avisa a mano a las pestañas abiertas.
        app(ChangeCollector::class)->record($companyId, 'payments');

        $this->catalog->forget($companyId);

        return back()->with('success', 'El orden quedó guardado.');
    }

    /**
     * Borrar solo lo que nunca se uso. Uno del sistema no se borra; uno con ventas, pedidos o
     * transacciones se apaga en vez de borrarse.
     */
    public function destroy(PaymentMethod $method): RedirectResponse
    {
        $this->authorizeCompany($method);

        if ($method->is_system) {
            return back()->with('error', "«{$method->name}» es del sistema: se puede apagar, no borrar.");
        }

        $used = SalePayment::query()->where('payment_method_id', $method->id)->exists()
            || Order::query()->withoutGlobalScopes()->where('payment_method_id', $method->id)->exists()
            || PaymentTransaction::query()->withoutGlobalScopes()->where('payment_method_id', $method->id)->exists();

        if ($used) {
            return back()->with('error', "«{$method->name}» ya se usó en ventas o pedidos: apágalo en vez de borrarlo.");
        }

        $method->delete();
        $this->catalog->forget((int) $method->company_id);

        return back()->with('success', 'El medio de pago quedó borrado.');
    }

    protected function accountLabel(PaymentGatewayAccount $account): string
    {
        $provider = $this->gateways->exists($account->provider) ? $this->gateways->for($account->provider)->label() : $account->provider;

        return ($account->label ?: $provider).' · '.(PaymentGatewayAccount::ENVIRONMENT_LABELS[$account->environment] ?? $account->environment);
    }
}
