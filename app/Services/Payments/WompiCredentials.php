<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentGatewaySetting;

/**
 * Las llaves con que habla `WompiService`: las de la plataforma (membresias) o las de una
 * empresa (sus ventas). El cliente es el mismo; lo que cambia es de quien es la cuenta, y por eso
 * la logica de firmas y de tokens de aceptacion no se duplica.
 */
final class WompiCredentials
{
    public function __construct(
        public readonly string $environment,
        public readonly ?string $publicKey,
        public readonly ?string $privateKey,
        public readonly ?string $eventsSecret,
        public readonly ?string $integritySecret,
        public readonly bool $enabled,
    ) {}

    /** Las de la plataforma: entorno y encendido de `PaymentGatewaySetting`, llaves por entorno. */
    public static function fromPlatform(PaymentGatewaySetting $settings, PaymentGatewayCredential $credential): self
    {
        return new self(
            (string) $settings->environment,
            $credential->public_key,
            $credential->private_key,
            $credential->events_secret,
            $credential->integrity_secret,
            (bool) $settings->is_enabled,
        );
    }

    /** Las de la cuenta de una empresa. */
    public static function fromAccount(PaymentGatewayAccount $account): self
    {
        return new self(
            (string) $account->environment,
            $account->publicField('public_key'),
            $account->secretField('private_key'),
            $account->secretField('events_secret'),
            $account->secretField('integrity_secret'),
            (bool) $account->is_enabled,
        );
    }

    /** Si estan las cuatro llaves: sin todas no se puede cobrar ni verificar un evento. */
    public function isComplete(): bool
    {
        return filled($this->publicKey) && filled($this->privateKey) && filled($this->eventsSecret) && filled($this->integritySecret);
    }
}
