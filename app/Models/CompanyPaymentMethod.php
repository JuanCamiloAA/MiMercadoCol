<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La tarjeta con que se cobra la membresia de una empresa: una por empresa.
 *
 * Solo lo que devuelve la pasarela al crear la fuente de pago. Nunca el numero completo, el
 * CVC ni el token de un solo uso: esos no llegan a este servidor.
 */
class CompanyPaymentMethod extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'provider', 'environment', 'payment_source_id', 'brand', 'last4',
        'exp_month', 'exp_year', 'holder_name', 'customer_email', 'status', 'updated_by_user_id',
    ];

    protected $hidden = ['payment_source_id'];

    protected function casts(): array
    {
        return ['exp_month' => 'integer', 'exp_year' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Si la tarjeta ya vencio o vence antes de una fecha (la del proximo cobro).
     */
    public function expiresBefore(\DateTimeInterface $date): bool
    {
        if (! $this->exp_month || ! $this->exp_year) {
            return false;
        }

        // La tarjeta sirve hasta el ultimo dia de su mes de vencimiento.
        $expiry = \Illuminate\Support\Carbon::create($this->exp_year, $this->exp_month, 1)->endOfMonth();

        return $expiry->lt($date);
    }
}
