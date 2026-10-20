<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Concerns\AuthorizesCompanyRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StoreTerminalRequest;
use App\Http\Requests\Payments\UpdateTerminalRequest;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTerminal;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Payments\Gateways\GatewayException;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\PaymentGateway;
use App\Services\Payments\GatewayPaymentService;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Medios de pago > Datafonos: los datafonos integrados con la pasarela, por sede o por caja, su
 * ultima conexion y un cobro de prueba (solo en sandbox).
 */
class TerminalController extends Controller
{
    use AuthorizesCompanyRecords;

    /** Lo que se cobra en la prueba: dentro de lo que el sandbox de Bold aprueba. */
    public const TEST_AMOUNT = 1000;

    public function __construct(
        protected GatewayRegistry $gateways,
        protected GatewayPaymentService $payments,
    ) {}

    public function index(): Response
    {
        TenantContext::requireCompanyId();

        $accounts = PaymentGatewayAccount::query()->orderBy('provider')->get()
            ->filter(fn (PaymentGatewayAccount $account) => $this->gateways->exists($account->provider)
                && in_array(PaymentGateway::CAPABILITY_TERMINAL, $this->gateways->for($account->provider)->capabilities(), true));

        return Inertia::render('PaymentMethods/Terminals', [
            'terminals' => PaymentTerminal::query()->with(['store:id,name', 'register:id,name', 'gatewayAccount'])->orderBy('store_id')->orderBy('name')->get()
                ->map(fn (PaymentTerminal $terminal) => [
                    'id' => $terminal->id,
                    'name' => $terminal->name,
                    'device_id' => $terminal->device_id,
                    'model' => $terminal->model,
                    'store_id' => $terminal->store_id,
                    'store' => $terminal->store?->name,
                    'register_id' => $terminal->register_id,
                    'register' => $terminal->register?->name,
                    'gateway_account_id' => $terminal->gateway_account_id,
                    'account' => $terminal->gatewayAccount ? $this->accountLabel($terminal->gatewayAccount) : null,
                    'sandbox' => (bool) $terminal->gatewayAccount?->isSandbox(),
                    'is_active' => $terminal->is_active,
                    'last_seen_at' => $terminal->last_seen_at?->toIso8601String(),
                ])->values(),
            'accounts' => $accounts->map(fn (PaymentGatewayAccount $account) => ['value' => $account->id, 'label' => $this->accountLabel($account)])->values(),
            'stores' => Store::query()->with(['registers' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get()
                ->map(fn (Store $store) => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'registers' => $store->registers->map(fn ($register) => ['id' => $register->id, 'name' => $register->name])->values(),
                ])->values(),
        ]);
    }

    public function store(StoreTerminalRequest $request): RedirectResponse
    {
        try {
            PaymentTerminal::query()->create([...$request->validated(), 'company_id' => TenantContext::requireCompanyId(), 'is_active' => $request->boolean('is_active', true)]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['device_id' => 'Ese datáfono ya está registrado en esa cuenta.']);
        }

        return back()->with('success', 'El datáfono quedó registrado.');
    }

    public function update(UpdateTerminalRequest $request, PaymentTerminal $terminal): RedirectResponse
    {
        $this->authorizeCompany($terminal);

        try {
            $terminal->update([...$request->validated(), 'is_active' => $request->boolean('is_active', $terminal->is_active)]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['device_id' => 'Ese datáfono ya está registrado en esa cuenta.']);
        }

        return back()->with('success', 'El datáfono quedó guardado.');
    }

    /**
     * Un cobro de prueba en el datafono, solo con una cuenta de sandbox. No crea venta: queda como
     * transaccion de prueba y se aplica sola al aprobarse.
     */
    public function test(Request $request, PaymentTerminal $terminal): RedirectResponse
    {
        $this->authorizeCompany($terminal);
        $terminal->loadMissing('gatewayAccount');
        $account = $terminal->gatewayAccount;

        if (! $account || ! $account->isSandbox()) {
            return back()->with('error', 'El cobro de prueba solo se hace con una cuenta de pruebas (sandbox).');
        }

        $transaction = PaymentTransaction::query()->create([
            'company_id' => $terminal->company_id,
            'store_id' => $terminal->store_id,
            'gateway_account_id' => $account->id,
            'payment_terminal_id' => $terminal->id,
            'provider' => $account->provider,
            'environment' => $account->environment,
            'reference' => PaymentTransaction::newReference(),
            'amount' => self::TEST_AMOUNT,
            'currency' => 'COP',
            'channel' => PaymentTransaction::CHANNEL_POS,
            'mode' => PaymentTransaction::MODE_TERMINAL,
            'status' => PaymentTransaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(5),
            'context' => ['test' => true],
            'created_by_user_id' => $request->user()->id,
        ]);

        try {
            $result = $this->gateways->for($account->provider)->createTerminalIntent($transaction->setRelation('gatewayAccount', $account), $terminal);
        } catch (GatewayException $exception) {
            $this->payments->failToOpen($transaction, $exception);

            return back()->with('error', $exception->getMessage());
        }

        $transaction->forceFill(['terminal_id' => $result->intentId])->save();
        $terminal->forceFill(['last_seen_at' => now()])->save();

        return back()->with('success', "Cobro de prueba de $ 1.000 enviado al datáfono ({$transaction->reference}). Su resultado aparece en Transacciones.");
    }

    protected function accountLabel(PaymentGatewayAccount $account): string
    {
        return ($account->label ?: $this->gateways->for($account->provider)->label()).' · '.(PaymentGatewayAccount::ENVIRONMENT_LABELS[$account->environment] ?? $account->environment);
    }
}
