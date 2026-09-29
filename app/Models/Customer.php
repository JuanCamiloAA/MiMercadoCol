<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable implements AuthenticatableContract
{
    use BelongsToCompany, HasFactory, Notifiable;

    /** @var list<string> */
    public const DOCUMENT_TYPES = ['CC', 'CE', 'NIT', 'PA'];

    protected $fillable = [
        'company_id', 'preferred_store_id', 'document_type', 'document_number', 'name', 'business_name', 'email', 'phone',
        'address', 'neighborhood', 'delivery_notes', 'credit_limit', 'credit_term_days', 'payment_term_id',
        'credit_enabled', 'credit_blocked', 'blocked_reason', 'is_online_user', 'accepted_terms_at', 'password', 'is_active',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'credit_limit' => 'integer',
            'credit_term_days' => 'integer',
            'store_credit_balance' => 'integer',
            'credit_enabled' => 'boolean',
            'credit_blocked' => 'boolean',
            'is_online_user' => 'boolean',
            'is_active' => 'boolean',
            'accepted_terms_at' => 'datetime',
        ];
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function preferredStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'preferred_store_id');
    }

    public function balance(): HasOne
    {
        return $this->hasOne(CustomerBalance::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /**
     * El «consumidor final» generico: la ficha que se usa para vender sin identificar a nadie (la
     * DIAN usa el documento 222222222222). No se le abona saldo a favor: lo gastaria cualquiera.
     */
    public function isGenericConsumer(): bool
    {
        return (bool) preg_match('/^2{9,}$/', (string) $this->document_number);
    }

    public function storeCreditMovements(): HasMany
    {
        return $this->hasMany(StoreCreditMovement::class)->latest('occurred_at')->latest('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(CustomerReturn::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function currentBalance(): int
    {
        return (int) ($this->balance?->balance ?? 0);
    }

    /** Cupo disponible: nunca se calcula a mano en una vista. */
    public function availableCredit(): int
    {
        return max(0, $this->credit_limit - $this->currentBalance());
    }

    public function scopeWithCredit(Builder $query): Builder
    {
        return $query->where('credit_enabled', true);
    }
}
