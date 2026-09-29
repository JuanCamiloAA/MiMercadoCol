<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo global de la pasarela: que entorno esta en uso y si los cobros estan encendidos. Las
 * llaves van por entorno en `PaymentGatewayCredential`.
 */
class PaymentGatewaySetting extends Model
{
    public const PROVIDER_WOMPI = 'wompi';

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    public const ENVIRONMENTS = [self::ENV_SANDBOX, self::ENV_PRODUCTION];

    protected $fillable = ['provider', 'environment', 'is_enabled', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public static function wompi(): self
    {
        return self::query()->firstOrCreate(
            ['provider' => self::PROVIDER_WOMPI],
            ['environment' => self::ENV_SANDBOX, 'is_enabled' => false],
        );
    }
}
