<?php

namespace Tests\Concerns;

use App\Models\PaymentGatewayCredential;
use App\Models\PaymentGatewaySetting;

/**
 * La pasarela encendida en pruebas (sandbox) con llaves de mentira. Las llamadas reales se
 * reemplazan con `Http::fake()` en cada prueba.
 */
trait ConfiguresGateway
{
    protected const EVENTS_SECRET = 'test_events_Secreto123';

    protected const INTEGRITY_SECRET = 'test_integrity_Secreto456';

    protected const WOMPI = 'https://sandbox.wompi.co/v1';

    protected function enableGateway(bool $enabled = true): void
    {
        PaymentGatewaySetting::query()->updateOrCreate(
            ['provider' => PaymentGatewaySetting::PROVIDER_WOMPI],
            ['environment' => PaymentGatewaySetting::ENV_SANDBOX, 'is_enabled' => $enabled],
        );

        PaymentGatewayCredential::query()->updateOrCreate(
            ['provider' => PaymentGatewaySetting::PROVIDER_WOMPI, 'environment' => PaymentGatewaySetting::ENV_SANDBOX],
            [
                'public_key' => 'pub_test_Llave123',
                'private_key' => 'prv_test_Privada123',
                'events_secret' => self::EVENTS_SECRET,
                'integrity_secret' => self::INTEGRITY_SECRET,
            ],
        );
    }

    /**
     * Un evento `transaction.updated` firmado como lo firma Wompi (checksum en mayusculas).
     *
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    protected function signedEvent(array $transaction, ?string $secret = null): array
    {
        $timestamp = 1_760_000_000;
        $properties = ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'];
        $concatenated = $transaction['id'].$transaction['status'].$transaction['amount_in_cents'];

        return [
            'event' => 'transaction.updated',
            'data' => ['transaction' => $transaction],
            'environment' => 'test',
            'signature' => [
                'properties' => $properties,
                'checksum' => strtoupper(hash('sha256', $concatenated.$timestamp.($secret ?? self::EVENTS_SECRET))),
            ],
            'timestamp' => $timestamp,
            'sent_at' => '2026-09-25T10:00:00.000Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transaction(string $reference, int $amountInCents, string $status = 'APPROVED', string $id = '1234-1760000000-49201'): array
    {
        return [
            'id' => $id,
            'amount_in_cents' => $amountInCents,
            'reference' => $reference,
            'customer_email' => 'pagos@lacanasta.co',
            'currency' => 'COP',
            'payment_method_type' => 'CARD',
            'status' => $status,
            'status_message' => $status === 'DECLINED' ? 'Fondos insuficientes' : null,
        ];
    }
}
