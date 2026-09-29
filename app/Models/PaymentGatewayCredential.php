<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Las llaves de la pasarela de un entorno. Las secretas van cifradas en reposo (cast
 * `encrypted`) y nunca salen enmascaradas hacia el navegador.
 */
class PaymentGatewayCredential extends Model
{
    public const SECRET_FIELDS = ['private_key', 'events_secret', 'integrity_secret'];

    protected $fillable = [
        'provider', 'environment', 'public_key', 'private_key', 'events_secret', 'integrity_secret', 'updated_by_user_id',
    ];

    protected $hidden = ['private_key', 'events_secret', 'integrity_secret'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'events_secret' => 'encrypted',
            'integrity_secret' => 'encrypted',
        ];
    }

    /**
     * Si estan las cuatro llaves: sin todas no se puede cobrar ni verificar un evento.
     */
    public function isComplete(): bool
    {
        return filled($this->public_key) && filled($this->private_key) && filled($this->events_secret) && filled($this->integrity_secret);
    }

    /**
     * `••••1a2b`: lo justo para reconocer cual llave esta puesta sin revelarla.
     */
    public static function mask(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return '••••'.substr((string) $value, -4);
    }
}
