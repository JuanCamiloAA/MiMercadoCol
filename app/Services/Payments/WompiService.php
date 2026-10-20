<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewaySetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Integracion con Wompi (Colombia). El contrato verificado esta en `config/wompi.php`.
 *
 * El mismo cliente sirve a la plataforma (cobrar membresias con sus llaves) y a cada empresa
 * (cobrar sus ventas con las suyas): lo que cambia son las credenciales (`WompiCredentials`).
 *
 * Tres cosas que no se negocian:
 *
 * 1. Los hosts salen de `config/wompi.php`, nunca de un formulario ni del entorno.
 * 2. Un evento no se cree por lo que dice: se valida su firma y ademas se relee la
 *    transaccion en la API. La firma prueba quien lo envia, no que siga siendo la verdad.
 * 3. Ni llaves, ni secretos, ni tokens de tarjeta entran en un log. Se registra la referencia
 *    y el id de transaccion, que es lo que sirve para rastrear.
 */
class WompiService
{
    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_DECLINED = 'DECLINED';

    public const STATUS_VOIDED = 'VOIDED';

    public const STATUS_ERROR = 'ERROR';

    public const STATUS_PENDING = 'PENDING';

    public function __construct(protected WompiCredentials $credentials) {}

    public function credentials(): WompiCredentials
    {
        return $this->credentials;
    }

    public function environment(): string
    {
        return $this->credentials->environment;
    }

    /** Cobros encendidos y con las cuatro llaves del entorno en uso. */
    public function isUsable(): bool
    {
        return $this->credentials->enabled && $this->credentials->isComplete();
    }

    public function publicKey(): string
    {
        return (string) $this->credentials->publicKey;
    }

    public function apiBase(): string
    {
        $hosts = config('wompi.hosts');

        return $hosts[$this->credentials->environment] ?? $hosts[PaymentGatewaySetting::ENV_SANDBOX];
    }

    /**
     * Firma de integridad: sin ella Wompi rechaza el cobro, y con ella nadie cambia el monto.
     */
    public function integritySignature(string $reference, int $amountInCents, string $currency, ?string $expiresAt = null): string
    {
        $secret = (string) $this->credentials->integritySecret;

        if ($secret === '') {
            throw new RuntimeException('Falta el secreto de integridad de la pasarela.');
        }

        return hash('sha256', $reference.$amountInCents.$currency.($expiresAt ?? '').$secret);
    }

    /**
     * El enlace del checkout web para un cobro.
     *
     * @param  array<string, string|null>  $customer  email, full-name, phone-number, legal-id, legal-id-type
     */
    public function checkoutUrl(string $reference, int $amountInCents, string $currency, string $redirectUrl, array $customer = [], ?string $expiresAt = null): string
    {
        $params = [
            'public-key' => $this->publicKey(),
            'currency' => $currency,
            'amount-in-cents' => (string) $amountInCents,
            'reference' => $reference,
            'signature:integrity' => $this->integritySignature($reference, $amountInCents, $currency, $expiresAt),
            'redirect-url' => $redirectUrl,
        ];

        if ($expiresAt !== null) {
            $params['expiration-time'] = $expiresAt;
        }

        foreach (['email', 'full-name', 'phone-number', 'legal-id', 'legal-id-type'] as $field) {
            if (filled($customer[$field] ?? null)) {
                $params['customer-data:'.$field] = (string) $customer[$field];
            }
        }

        return config('wompi.checkout_url').'?'.http_build_query($params);
    }

    /**
     * Verifica la firma de un evento.
     *
     * Wompi manda el checksum en mayusculas; se compara sin importar mayusculas y con
     * `hash_equals`, para no filtrar por tiempo cuanto se acerto.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyEventSignature(array $payload, ?string $headerChecksum = null): bool
    {
        $secret = (string) $this->credentials->eventsSecret;
        $checksum = $payload['signature']['checksum'] ?? $headerChecksum;
        $properties = $payload['signature']['properties'] ?? null;
        $timestamp = $payload['timestamp'] ?? null;

        if ($secret === '' || ! is_string($checksum) || ! is_array($properties) || $properties === [] || $timestamp === null) {
            return false;
        }

        $concatenated = '';

        foreach ($properties as $property) {
            if (! is_string($property)) {
                return false;
            }

            $value = data_get($payload['data'] ?? [], $property);

            if ($value === null || is_array($value)) {
                return false;
            }

            $concatenated .= is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $expected = hash('sha256', $concatenated.$timestamp.$secret);

        return hash_equals($expected, strtolower($checksum));
    }

    /**
     * Los tokens de aceptacion del comercio: reglamento y tratamiento de datos, con sus enlaces.
     *
     * @return array{acceptance_token: string, acceptance_permalink: ?string, personal_auth_token: string, personal_auth_permalink: ?string}|null
     */
    public function acceptanceTokens(): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withHeaders(['x-merchant-public-key' => $this->publicKey()])
                ->get($this->apiBase().'/merchants/info');

            if (! $response->successful()) {
                Log::warning('Wompi: no se pudieron leer los tokens de aceptacion.', ['status' => $response->status()]);

                return null;
            }

            $acceptance = $response->json('data.presigned_acceptance');
            $personal = $response->json('data.presigned_personal_data_auth');

            if (! is_string($acceptance['acceptance_token'] ?? null) || ! is_string($personal['acceptance_token'] ?? null)) {
                return null;
            }

            return [
                'acceptance_token' => $acceptance['acceptance_token'],
                'acceptance_permalink' => $acceptance['permalink'] ?? null,
                'personal_auth_token' => $personal['acceptance_token'],
                'personal_auth_permalink' => $personal['permalink'] ?? null,
            ];
        } catch (Throwable $e) {
            Log::warning('Wompi: fallo la lectura de los tokens de aceptacion.', ['error' => class_basename($e)]);

            return null;
        }
    }

    /**
     * Crea la fuente de pago de una tarjeta ya tokenizada en el navegador.
     *
     * Lo visible (marca, ultimos cuatro, vencimiento, titular) sale de lo que responde Wompi,
     * nunca de lo que diga el navegador. La marca se deduce del BIN que devuelve Wompi.
     *
     * @return array{id: string, status: ?string, brand: ?string, last4: ?string, exp_month: ?int, exp_year: ?int, holder: ?string}
     *
     * @throws RuntimeException con un mensaje apto para la persona
     */
    public function createPaymentSource(string $token, string $email, string $acceptanceToken, string $personalAuthToken): array
    {
        try {
            $response = $this->private()->post($this->apiBase().'/payment_sources', [
                'type' => 'CARD',
                'token' => $token,
                'customer_email' => $email,
                'acceptance_token' => $acceptanceToken,
                'accept_personal_auth' => $personalAuthToken,
            ]);
        } catch (Throwable $e) {
            Log::warning('Wompi: fallo la creacion de la fuente de pago.', ['error' => class_basename($e)]);

            throw new RuntimeException('No pudimos comunicarnos con la pasarela. Intenta de nuevo en un momento.');
        }

        $data = $response->json('data');

        if (! $response->successful() || ! is_array($data) || ! isset($data['id'])) {
            Log::warning('Wompi: la pasarela no acepto la fuente de pago.', [
                'status' => $response->status(),
                'error_type' => $response->json('error.type'),
            ]);

            throw new RuntimeException('La pasarela no aceptó la tarjeta. Revisa los datos o prueba con otra.');
        }

        $public = is_array($data['public_data'] ?? null) ? $data['public_data'] : [];

        // Si la respuesta de creacion no trae lo visible, se lee la fuente con la llave privada.
        if (! isset($public['last_four'])) {
            $public = array_merge($public, $this->fetchPaymentSource((string) $data['id']) ?? []);
        }

        $year = isset($public['exp_year']) ? (int) $public['exp_year'] : null;

        return [
            'id' => (string) $data['id'],
            'status' => $data['status'] ?? null,
            'brand' => self::brandFromBin(isset($public['bin']) ? (string) $public['bin'] : null),
            'last4' => isset($public['last_four']) ? substr((string) $public['last_four'], -4) : null,
            'exp_month' => isset($public['exp_month']) ? (int) $public['exp_month'] : null,
            // Wompi da el año en dos digitos.
            'exp_year' => $year !== null && $year < 100 ? 2000 + $year : $year,
            'holder' => isset($public['card_holder']) ? mb_substr((string) $public['card_holder'], 0, 120) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null el `public_data` de la fuente
     */
    public function fetchPaymentSource(string $id): ?array
    {
        try {
            $response = $this->private()->get($this->apiBase().'/payment_sources/'.rawurlencode($id));

            return $response->successful() && is_array($response->json('data.public_data'))
                ? $response->json('data.public_data')
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Cobra a una fuente de pago guardada. Devuelve la transaccion tal como la crea Wompi (casi
     * siempre `PENDING`; el resultado final se confirma releyendo o por el evento).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException si la pasarela no respondio o rechazo la peticion
     */
    public function chargePaymentSource(string $paymentSourceId, int $amountInCents, string $currency, string $reference, string $email, bool $recurrent): array
    {
        $body = [
            'amount_in_cents' => $amountInCents,
            'currency' => $currency,
            'signature' => $this->integritySignature($reference, $amountInCents, $currency),
            'customer_email' => $email,
            'payment_method' => ['installments' => 1],
            'reference' => $reference,
            'payment_source_id' => ctype_digit($paymentSourceId) ? (int) $paymentSourceId : $paymentSourceId,
        ];

        // Cobro iniciado por el comercio sobre una tarjeta guardada (COF): solo el automatico.
        if ($recurrent) {
            $body['recurrent'] = true;
        }

        try {
            $response = $this->private()->post($this->apiBase().'/transactions', $body);
        } catch (Throwable $e) {
            Log::warning('Wompi: fallo el cobro a la fuente de pago.', ['reference' => $reference, 'error' => class_basename($e)]);

            throw new RuntimeException('No pudimos comunicarnos con la pasarela.');
        }

        $data = $response->json('data');

        if (! $response->successful() || ! is_array($data)) {
            Log::warning('Wompi: la pasarela rechazo el cobro.', [
                'reference' => $reference,
                'status' => $response->status(),
                'error_type' => $response->json('error.type'),
            ]);

            throw new RuntimeException('La pasarela rechazó el cobro.');
        }

        return $data;
    }

    /**
     * Una transaccion por id: la fuente de verdad.
     *
     * @return array<string, mixed>|null
     */
    public function fetchTransaction(string $transactionId): ?array
    {
        try {
            $response = Http::withToken($this->publicKey())
                ->acceptJson()
                ->timeout(15)
                ->retry(2, 300, throw: false)
                ->get($this->apiBase().'/transactions/'.rawurlencode($transactionId));

            if (! $response->successful()) {
                Log::warning('Wompi: no se pudo consultar la transaccion.', ['transaction_id' => $transactionId, 'status' => $response->status()]);

                return null;
            }

            $data = $response->json('data');

            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            Log::warning('Wompi: fallo la consulta de la transaccion.', ['transaction_id' => $transactionId, 'error' => class_basename($e)]);

            return null;
        }
    }

    /**
     * La transaccion mas reciente con una referencia, para reconciliar un cobro que se creo pero
     * del que no alcanzo a llegar respuesta.
     *
     * @return array<string, mixed>|null
     */
    public function findTransactionByReference(string $reference): ?array
    {
        try {
            $response = $this->private()->get($this->apiBase().'/transactions', ['reference' => $reference]);

            if (! $response->successful()) {
                return null;
            }

            $rows = $response->json('data');

            if (! is_array($rows) || $rows === []) {
                return null;
            }

            // Si hubo varios intentos con la misma referencia, manda el aprobado.
            $approved = collect($rows)->firstWhere('status', self::STATUS_APPROVED);

            return is_array($approved) ? $approved : (is_array($rows[0] ?? null) ? $rows[0] : null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Prueba de conexion con la llave publica: lee los datos del comercio.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! filled($this->credentials->publicKey)) {
            return ['ok' => false, 'message' => 'Falta la llave pública de este entorno.'];
        }

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->withHeaders(['x-merchant-public-key' => $this->publicKey()])
                ->get($this->apiBase().'/merchants/info');
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'No hubo respuesta de la pasarela. Revisa la conexión del servidor.'];
        }

        if ($response->successful()) {
            $name = $response->json('data.name') ?? $response->json('data.legal_name');

            return ['ok' => true, 'message' => $name ? "Conexión correcta con el comercio «{$name}»." : 'Conexión correcta.'];
        }

        return ['ok' => false, 'message' => $response->status() === 404 || $response->status() === 401
            ? 'La pasarela no reconoce la llave pública de este entorno.'
            : "La pasarela respondió con el código {$response->status()}."];
    }

    /**
     * La marca a partir del BIN (primeros seis digitos) que devuelve Wompi.
     */
    public static function brandFromBin(?string $bin): ?string
    {
        if ($bin === null || ! ctype_digit($bin) || strlen($bin) < 2) {
            return null;
        }

        $two = (int) substr($bin, 0, 2);
        $four = strlen($bin) >= 4 ? (int) substr($bin, 0, 4) : 0;
        $three = strlen($bin) >= 3 ? (int) substr($bin, 0, 3) : 0;

        return match (true) {
            $bin[0] === '4' => 'VISA',
            ($two >= 51 && $two <= 55) || ($four >= 2221 && $four <= 2720) => 'MASTERCARD',
            $two === 34 || $two === 37 => 'AMEX',
            $two === 36 || $two === 38 || ($three >= 300 && $three <= 305) => 'DINERS',
            default => null,
        };
    }

    protected function private(): PendingRequest
    {
        $key = (string) $this->credentials->privateKey;

        if ($key === '') {
            throw new RuntimeException('Falta la llave privada de la pasarela.');
        }

        return Http::withToken($key)->acceptJson()->asJson()->timeout(20);
    }
}
