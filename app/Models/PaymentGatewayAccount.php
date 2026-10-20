<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * La cuenta de una EMPRESA en una pasarela (Wompi, Mercado Pago, PayU, ePayco, Bold): la plata
 * de sus ventas va a ella, no a la plataforma.
 *
 * Es otra cosa que `PaymentGatewaySetting` / `PaymentGatewayCredential`, que son la cuenta con
 * que la plataforma cobra las membresias.
 *
 * Las llaves secretas viven en `secret_fields`, un JSON cifrado en reposo. Nunca viajan al
 * navegador, ni enmascaradas dentro de una prop que las contenga enteras: para mostrarlas se usa
 * `maskedSecrets()`.
 */
class PaymentGatewayAccount extends Model
{
    use BelongsToCompany;

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    public const ENVIRONMENTS = [self::ENV_SANDBOX, self::ENV_PRODUCTION];

    public const ENVIRONMENT_LABELS = [
        self::ENV_SANDBOX => 'Pruebas (sandbox)',
        self::ENV_PRODUCTION => 'Producción',
    ];

    protected $fillable = [
        'company_id', 'provider', 'environment', 'label', 'public_fields', 'secret_fields', 'webhook_token',
        'is_enabled', 'verified_at', 'last_error', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected $hidden = ['secret_fields', 'webhook_token'];

    protected function casts(): array
    {
        return [
            'public_fields' => 'array',
            'secret_fields' => 'encrypted:array',
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentGatewayAccount $account) {
            if (empty($account->webhook_token)) {
                $account->webhook_token = self::newWebhookToken();
            }
        });
    }

    public static function newWebhookToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::query()->withoutGlobalScopes()->where('webhook_token', $token)->exists());

        return $token;
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'gateway_account_id');
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(PaymentTerminal::class, 'gateway_account_id');
    }

    /** Un valor publico (llave publica, id de comercio). */
    public function publicField(string $key): ?string
    {
        $value = ($this->public_fields ?? [])[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** Un valor secreto. Solo lo leen el conector y la verificacion del webhook. */
    public function secretField(string $key): ?string
    {
        $value = ($this->secret_fields ?? [])[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Las secretas enmascaradas (`••••1a2b`), para que se sepa cual esta puesta sin revelarla.
     *
     * @return array<string, ?string>
     */
    public function maskedSecrets(): array
    {
        $masked = [];

        foreach (($this->secret_fields ?? []) as $key => $value) {
            $masked[$key] = PaymentGatewayCredential::mask(is_scalar($value) ? (string) $value : null);
        }

        return $masked;
    }

    public function isSandbox(): bool
    {
        return $this->environment === self::ENV_SANDBOX;
    }

    /** La URL que se pega en el panel de la pasarela para recibir sus eventos. */
    public function webhookUrl(): string
    {
        return route('webhooks.payments', ['provider' => $this->provider, 'token' => $this->webhook_token]);
    }
}
