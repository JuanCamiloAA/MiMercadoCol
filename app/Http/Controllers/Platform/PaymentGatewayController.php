<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateGatewayCredentialsRequest;
use App\Http\Requests\Platform\UpdateGatewaySettingsRequest;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentGatewaySetting;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\WompiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Pasarela: entorno, interruptor de cobros y llaves por entorno.
 *
 * Los secretos nunca vuelven a la pantalla: solo si estan y como terminan (`••••1a2b`). Al
 * guardar, un campo vacio conserva el valor.
 */
class PaymentGatewayController extends Controller
{
    public function __construct(protected PaymentGatewayResolver $gateways) {}

    public function index(): Response
    {
        $settings = $this->gateways->settings();

        return Inertia::render('Platform/Gateway/Index', [
            'settings' => [
                'environment' => $settings->environment,
                'is_enabled' => (bool) $settings->is_enabled,
                'is_usable' => $this->gateways->wompi()->isUsable(),
                'updated_at' => $settings->updated_at?->toIso8601String(),
            ],
            'credentials' => collect(PaymentGatewaySetting::ENVIRONMENTS)
                ->mapWithKeys(fn (string $environment) => [$environment => $this->credentialPayload($this->gateways->credential($environment))])
                ->all(),
            'environments' => [
                ['value' => PaymentGatewaySetting::ENV_SANDBOX, 'label' => 'Pruebas (sandbox)'],
                ['value' => PaymentGatewaySetting::ENV_PRODUCTION, 'label' => 'Producción'],
            ],
            'webhookUrl' => route('webhooks.wompi'),
        ]);
    }

    public function update(UpdateGatewaySettingsRequest $request): RedirectResponse
    {
        $settings = $this->gateways->settings();
        $data = $request->validated();

        // Encenderlo sin las cuatro llaves del entorno elegido dejaria cobros que fallan; se
        // avisa aqui y no en el primer pago perdido.
        if ($data['is_enabled'] && ! $this->gateways->credential($data['environment'])->isComplete()) {
            return back()->withErrors([
                'is_enabled' => 'Faltan llaves de '.$this->label($data['environment']).': carga la pública, la privada y los dos secretos antes de encender los cobros.',
            ]);
        }

        $settings->forceFill([
            'environment' => $data['environment'],
            'is_enabled' => (bool) $data['is_enabled'],
            'updated_by_user_id' => $request->user()->id,
        ])->save();

        return back()->with('success', 'La configuración de la pasarela quedó guardada.');
    }

    public function updateCredentials(UpdateGatewayCredentialsRequest $request, string $environment): RedirectResponse
    {
        $credential = $this->gateways->credential($environment);

        foreach (['public_key', ...PaymentGatewayCredential::SECRET_FIELDS] as $field) {
            $value = trim((string) $request->validated($field));

            if ($value !== '') {
                $credential->{$field} = $value;
            }
        }

        $credential->updated_by_user_id = $request->user()->id;
        $credential->save();

        return back()->with('success', 'Llaves de '.$this->label($environment).' guardadas.');
    }

    /**
     * Consulta real a la pasarela con la llave publica del entorno.
     */
    public function test(Request $request, string $environment): RedirectResponse
    {
        $settings = (new PaymentGatewaySetting)->forceFill([
            'provider' => PaymentGatewaySetting::PROVIDER_WOMPI,
            'environment' => $environment,
            'is_enabled' => true,
        ]);

        $result = (new WompiService($settings, $this->gateways->credential($environment)))->testConnection();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentialPayload(PaymentGatewayCredential $credential): array
    {
        return [
            'public_key' => $credential->public_key,
            'is_complete' => $credential->isComplete(),
            'expected_prefix' => config('wompi.public_key_prefixes.'.$credential->environment),
            // Solo si hay algo cargado: la fila vacia nace sola al abrir la pantalla.
            'updated_at' => filled($credential->public_key) || collect(PaymentGatewayCredential::SECRET_FIELDS)->contains(fn ($field) => filled($credential->{$field}))
                ? $credential->updated_at?->toIso8601String()
                : null,
            'secrets' => collect(PaymentGatewayCredential::SECRET_FIELDS)
                ->mapWithKeys(fn (string $field) => [$field => PaymentGatewayCredential::mask($credential->{$field})])
                ->all(),
        ];
    }

    protected function label(string $environment): string
    {
        return $environment === PaymentGatewaySetting::ENV_PRODUCTION ? 'producción' : 'pruebas';
    }
}
