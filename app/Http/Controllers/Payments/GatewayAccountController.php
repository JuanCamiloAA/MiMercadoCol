<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Concerns\AuthorizesCompanyRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StoreGatewayAccountRequest;
use App\Http\Requests\Payments\UpdateGatewayAccountRequest;
use App\Models\PaymentGatewayAccount;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\PaymentGateway;
use App\Payments\Gateways\TestResult;
use App\Services\Payments\PaymentMethodCatalog;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Medios de pago > Pasarelas: las cuentas de la EMPRESA en Wompi, Mercado Pago, PayU, ePayco o
 * Bold. La plata de sus ventas va a esas cuentas, no a la plataforma.
 *
 * Las llaves secretas se ven enmascaradas y solo se reemplazan: nunca viajan al navegador.
 */
class GatewayAccountController extends Controller
{
    use AuthorizesCompanyRecords;

    public function __construct(
        protected GatewayRegistry $gateways,
        protected PaymentMethodCatalog $catalog,
    ) {}

    public function index(Request $request): Response
    {
        TenantContext::requireCompanyId();

        return Inertia::render('PaymentMethods/Gateways', [
            'accounts' => PaymentGatewayAccount::query()->withCount('paymentMethods')->orderBy('provider')->orderBy('environment')->get()
                ->map(fn (PaymentGatewayAccount $account) => $this->present($account))
                ->values(),
            // Lo que pide cada pasarela, de su conector. Solo las que la plataforma deja conectar.
            'providers' => $this->gateways->catalogue(),
            'environments' => collect(PaymentGatewayAccount::ENVIRONMENT_LABELS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'capabilityLabels' => PaymentGateway::CAPABILITY_LABELS,
        ]);
    }

    public function store(StoreGatewayAccountRequest $request): RedirectResponse
    {
        $keys = $request->gatewayKeys();

        PaymentGatewayAccount::query()->create([
            'company_id' => TenantContext::requireCompanyId(),
            'provider' => $request->validated('provider'),
            'environment' => $request->validated('environment'),
            'label' => $request->validated('label'),
            'public_fields' => $keys['public'],
            'secret_fields' => $keys['secret'],
            'is_enabled' => false,
            'created_by_user_id' => $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'La cuenta quedó guardada y apagada. Pruébala, pega la URL del webhook en la pasarela y enciéndela.');
    }

    public function update(UpdateGatewayAccountRequest $request, PaymentGatewayAccount $account): RedirectResponse
    {
        $this->authorizeCompany($account);
        $keys = $request->gatewayKeys($account);

        $account->forceFill([
            'label' => $request->validated('label'),
            'public_fields' => $keys['public'],
            'secret_fields' => $keys['secret'],
            // Llaves nuevas: hay que volver a probar.
            'verified_at' => null,
            'last_error' => null,
            'updated_by_user_id' => $request->user()->id,
        ])->save();

        $this->catalog->forget((int) $account->company_id);

        return back()->with('success', 'Las llaves quedaron guardadas. Vuelve a probar la conexión.');
    }

    /** «Probar conexión»: llena `verified_at` o `last_error`. */
    public function test(PaymentGatewayAccount $account): RedirectResponse
    {
        $this->authorizeCompany($account);

        try {
            $result = $this->gateways->for($account->provider)->test($account);
        } catch (Throwable) {
            $result = new TestResult(false, 'No se pudo probar la conexión.');
        }

        $account->forceFill([
            'verified_at' => $result->ok ? now() : null,
            'last_error' => $result->ok ? null : mb_substr($result->message, 0, 250),
        ])->save();

        return back()->with($result->ok ? 'success' : 'error', $result->message);
    }

    /** Un token nuevo para la URL del webhook: la vieja deja de funcionar en el acto. */
    public function rotate(Request $request, PaymentGatewayAccount $account): RedirectResponse
    {
        $this->authorizeCompany($account);

        $account->forceFill([
            'webhook_token' => PaymentGatewayAccount::newWebhookToken(),
            'updated_by_user_id' => $request->user()->id,
        ])->save();

        return back()->with('success', 'La URL del webhook cambió: pégala de nuevo en el panel de la pasarela.');
    }

    /** Encender o apagar. Apagada, sus medios de pago dejan de aparecer en la caja y la tienda. */
    public function toggle(Request $request, PaymentGatewayAccount $account): RedirectResponse
    {
        $this->authorizeCompany($account);

        if (! $account->is_enabled) {
            if (! $this->gateways->isEnabled($account->provider)) {
                return back()->with('error', 'La plataforma tiene esta pasarela apagada por ahora.');
            }

            foreach ($this->gateways->for($account->provider)->fields() as $field) {
                $value = $field['secret'] ? $account->secretField($field['key']) : $account->publicField($field['key']);

                if ($field['required'] && ! $value) {
                    return back()->with('error', "Falta «{$field['label']}»: completa las llaves antes de encenderla.");
                }
            }
        }

        $account->forceFill(['is_enabled' => ! $account->is_enabled, 'updated_by_user_id' => $request->user()->id])->save();
        $this->catalog->forget((int) $account->company_id);

        return back()->with('success', $account->is_enabled ? 'La cuenta quedó encendida.' : 'La cuenta quedó apagada: sus medios de pago ya no aparecen.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(PaymentGatewayAccount $account): array
    {
        $gateway = $this->gateways->exists($account->provider) ? $this->gateways->for($account->provider) : null;

        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'provider_label' => $gateway?->label() ?? $account->provider,
            'environment' => $account->environment,
            'environment_label' => PaymentGatewayAccount::ENVIRONMENT_LABELS[$account->environment] ?? $account->environment,
            'label' => $account->label,
            'public_fields' => (object) ($account->public_fields ?? []),
            // Solo enmascaradas: `••••1a2b`. Nunca el valor.
            'secret_fields' => (object) $account->maskedSecrets(),
            'webhook_url' => $account->webhookUrl(),
            'webhook_instructions' => $gateway?->webhookInstructions(),
            'capabilities' => $gateway?->capabilities() ?? [],
            'is_enabled' => $account->is_enabled,
            'provider_enabled' => $this->gateways->isEnabled($account->provider),
            'verified_at' => $account->verified_at?->toIso8601String(),
            'last_error' => $account->last_error,
            'methods_count' => (int) ($account->payment_methods_count ?? 0),
        ];
    }
}
