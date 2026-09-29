<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayCredential;
use App\Models\PaymentGatewaySetting;

/**
 * De donde salen la configuracion y las llaves de la pasarela.
 *
 * `payment_gateway_settings` dice que entorno esta en uso y si los cobros estan encendidos;
 * `payment_gateway_credentials` guarda las cuatro llaves de cada entorno por separado. El
 * `.env` solo siembra la primera vez. Nace apagada: sin llaves no hay cobro que ofrecer.
 */
class PaymentGatewayResolver
{
    public function settings(): PaymentGatewaySetting
    {
        $row = PaymentGatewaySetting::query()->firstOrNew(['provider' => PaymentGatewaySetting::PROVIDER_WOMPI]);

        if (! $row->exists) {
            $environment = (string) config('wompi.environment', PaymentGatewaySetting::ENV_SANDBOX);
            $row->environment = in_array($environment, PaymentGatewaySetting::ENVIRONMENTS, true) ? $environment : PaymentGatewaySetting::ENV_SANDBOX;
            $row->is_enabled = false;
            $row->save();

            $seeded = $this->credential($row->environment);

            if (! $seeded->isComplete() && filled(config('wompi.public_key'))) {
                $seeded->forceFill([
                    'public_key' => config('wompi.public_key'),
                    'private_key' => config('wompi.private_key'),
                    'events_secret' => config('wompi.events_secret'),
                    'integrity_secret' => config('wompi.integrity_secret'),
                ])->save();
            }
        }

        return $row;
    }

    /** Las llaves de un entorno; se crean vacias si aun no existen. */
    public function credential(string $environment): PaymentGatewayCredential
    {
        return PaymentGatewayCredential::query()->firstOrCreate([
            'provider' => PaymentGatewaySetting::PROVIDER_WOMPI,
            'environment' => $environment,
        ]);
    }

    public function wompi(): WompiService
    {
        $settings = $this->settings();

        return new WompiService($settings, $this->credential($settings->environment));
    }
}
