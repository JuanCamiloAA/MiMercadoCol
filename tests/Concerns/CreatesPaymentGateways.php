<?php

namespace Tests\Concerns;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cuentas de pasarela de la empresa y medios que cobran con ellas, para las pruebas. Las llaves son
 * de mentira: toda llamada a la pasarela se finge con `Http::fake`.
 */
trait CreatesPaymentGateways
{
    protected const WOMPI_EVENTS_SECRET = 'test_events_secreto';

    /** Lo que responde la pasarela al releer; null es «sin intentos con esa referencia». */
    protected ?array $gatewayReply = null;

    /** Un solo fingido para toda la prueba: `Http::fake` acumula y el primero gana. */
    protected function fakeWompi(?array $transaction): void
    {
        $this->gatewayReply = $transaction;

        if ($this->faked) {
            return;
        }

        $this->faked = true;

        // Por id (`/transactions/{id}`) responde la transaccion; por referencia, la lista.
        Http::fake(['sandbox.wompi.co/v1/transactions*' => function (ClientRequest $request) {
            if (str_contains($request->url(), '/transactions/')) {
                return $this->gatewayReply === null ? Http::response(['error' => 'NOT_FOUND'], 404) : Http::response(['data' => $this->gatewayReply]);
            }

            return Http::response(['data' => $this->gatewayReply === null ? [] : [$this->gatewayReply]]);
        }]);
    }

    protected bool $faked = false;

    protected function gatewayAccount(string $provider, array $public, array $secret, string $environment = PaymentGatewayAccount::ENV_SANDBOX, bool $enabled = true): PaymentGatewayAccount
    {
        return PaymentGatewayAccount::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'provider' => $provider,
            'environment' => $environment,
            'public_fields' => $public,
            'secret_fields' => $secret,
            'is_enabled' => $enabled,
        ]);
    }

    protected function wompiAccount(): PaymentGatewayAccount
    {
        return $this->gatewayAccount('wompi', ['public_key' => 'pub_test_llave'], [
            'private_key' => 'prv_test_llave',
            'events_secret' => self::WOMPI_EVENTS_SECRET,
            'integrity_secret' => 'test_integrity_secreto',
        ]);
    }

    protected function gatewayMethod(string $kind, PaymentGatewayAccount $account, array $attributes = []): PaymentMethod
    {
        $method = PaymentMethod::query()->withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'name' => match ($kind) {
                PaymentMethod::KIND_GATEWAY_CHECKOUT => 'Paga en línea',
                PaymentMethod::KIND_GATEWAY_LINK => 'QR de pago',
                default => 'Datáfono integrado',
            },
            'kind' => $kind,
            'gateway_account_id' => $account->id,
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 70,
        ], $attributes));

        app(PaymentMethodCatalog::class)->forget();

        return $method;
    }

    protected function manualMethod(string $name, string $kind, array $attributes = []): PaymentMethod
    {
        $method = PaymentMethod::query()->withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'name' => $name,
            'kind' => $kind,
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 35,
        ], $attributes));

        app(PaymentMethodCatalog::class)->forget();

        return $method;
    }

    protected function systemMethod(string $key): PaymentMethod
    {
        return PaymentMethod::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('system_key', $key)
            ->firstOrFail();
    }

    /**
     * Un evento de Wompi firmado como lo firma Wompi: sha256 de las propiedades, el timestamp y el
     * secreto de eventos.
     *
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    protected function wompiEvent(array $transaction, string $secret = self::WOMPI_EVENTS_SECRET): array
    {
        $properties = ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'];
        $timestamp = 1760000000;
        $data = ['transaction' => $transaction];
        $concatenated = implode('', array_map(fn (string $property) => (string) data_get($data, $property), $properties));

        return [
            'event' => 'transaction.updated',
            'data' => $data,
            'environment' => 'test',
            'signature' => ['properties' => $properties, 'checksum' => hash('sha256', $concatenated.$timestamp.$secret)],
            'timestamp' => $timestamp,
            'sent_at' => '2026-10-07T15:00:00.000Z',
        ];
    }

    /**
     * La transaccion de Wompi como la devuelve `GET /transactions/{id}`.
     *
     * @return array<string, mixed>
     */
    protected function wompiTransaction(string $id, string $reference, int $pesos, string $status = 'APPROVED'): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'reference' => $reference,
            'amount_in_cents' => $pesos * 100,
            'currency' => 'COP',
            'payment_method_type' => 'NEQUI',
            'status_message' => null,
        ];
    }
}
