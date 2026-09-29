<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El codigo que activa la cuenta en linea de un cliente que ya existia en la caja. Se guarda con
 * hash, vence y admite pocos intentos.
 */
class CustomerActivation extends Model
{
    public const TTL_MINUTES = 30;

    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'customer_id', 'store_id', 'code_hash', 'password_hash', 'profile', 'attempts', 'expires_at', 'consumed_at',
    ];

    protected $hidden = ['code_hash', 'password_hash'];

    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture() && $this->attempts < self::MAX_ATTEMPTS;
    }
}
