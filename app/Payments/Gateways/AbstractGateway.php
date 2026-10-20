<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTerminal;
use App\Models\PaymentTransaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Lo comun de los conectores: lo que no se ofrece, el saneado de lo que se guarda y el cliente
 * HTTP con tiempos de espera. Cada conector solo implementa lo que su pasarela de verdad tiene.
 */
abstract class AbstractGateway implements PaymentGateway
{
    /** Claves que nunca se guardan en `payload`: datos de tarjeta, llaves y firmas. */
    protected const SENSITIVE_KEYS = [
        'number', 'card_number', 'cardnumber', 'pan', 'cvc', 'cvv', 'cvv2', 'security_code', 'token', 'card_token',
        'private_key', 'public_key', 'secret', 'secret_key', 'events_secret', 'integrity_secret', 'signature', 'sign',
        'x_signature', 'api_key', 'apikey', 'api_login', 'apilogin', 'access_token', 'authorization', 'p_key', 'password',
        'identity_key', 'expiration_month', 'expiration_year', 'exp_month', 'exp_year', 'card_holder',
    ];

    public function createCheckout(PaymentTransaction $transaction, array $context): CheckoutSession
    {
        throw $this->unsupported('el pago en línea');
    }

    public function createLink(PaymentTransaction $transaction): LinkResult
    {
        throw $this->unsupported('links de pago');
    }

    public function createQr(PaymentTransaction $transaction): LinkResult
    {
        throw $this->unsupported('cobros con QR');
    }

    public function createTerminalIntent(PaymentTransaction $transaction, PaymentTerminal $terminal): TerminalResult
    {
        throw $this->unsupported('datáfonos integrados');
    }

    public function cancel(PaymentTransaction $transaction): bool
    {
        // Sin anulacion en la pasarela: el link o el intento caducan solos y se marcan aqui.
        return false;
    }

    public function refund(PaymentTransaction $transaction, int $amount): bool
    {
        throw $this->unsupported('reembolsos');
    }

    public function lookup(PaymentGatewayAccount $account, GatewayEvent $event): ?GatewayStatus
    {
        return null;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    protected function unsupported(string $what): GatewayException
    {
        return new GatewayException("{$this->label()} no ofrece {$what}.");
    }

    protected function account(PaymentTransaction $transaction): PaymentGatewayAccount
    {
        $account = $transaction->gatewayAccount ?? PaymentGatewayAccount::query()->withoutGlobalScopes()->find($transaction->gateway_account_id);

        if (! $account) {
            throw new GatewayException('La transacción no tiene cuenta de pasarela.');
        }

        return $account;
    }

    protected function http(int $timeout = 20): PendingRequest
    {
        return Http::acceptJson()->timeout($timeout)->connectTimeout(10);
    }

    /** El host de la pasarela segun el entorno de la cuenta, del config y nunca de un formulario. */
    protected function host(PaymentGatewayAccount $account, string $configKey): string
    {
        $hosts = (array) config($configKey);

        return (string) ($hosts[$account->environment] ?? $hosts[PaymentGatewayAccount::ENV_SANDBOX] ?? '');
    }

    /**
     * Lo que se guarda de una respuesta: sin datos de tarjeta, llaves ni firmas, y con cualquier
     * cosa que parezca un numero de tarjeta tapada.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function sanitize(array $data, int $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitize($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr(preg_replace('/\b\d{13,19}\b/', '[oculto]', $value) ?? '', 0, 500);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** Un mensaje de la pasarela apto para guardar: corto y sin nada que parezca una llave. */
    protected function safeMessage(mixed $message, string $fallback): string
    {
        if (! is_string($message) || trim($message) === '') {
            return $fallback;
        }

        $clean = preg_replace('/\b(pub|prv|prod|test|sk|pk|APP_USR|TEST)[_-][A-Za-z0-9_-]{6,}\b/', '[llave]', $message) ?? $fallback;

        return mb_substr($clean, 0, 200);
    }
}
