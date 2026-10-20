<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un datafono integrado con la pasarela: la caja le manda el cobro y el aparato lo cobra.
 * Sin `register_id` sirve a cualquier caja de su sede.
 */
class PaymentTerminal extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'store_id', 'register_id', 'gateway_account_id', 'device_id', 'model', 'name', 'is_active', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function gatewayAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayAccount::class, 'gateway_account_id');
    }

    /** Los que atienden una caja: los de esa caja y los de su sede sin caja fija. */
    public function scopeServing(Builder $query, int $storeId, ?int $registerId): Builder
    {
        return $query->where('store_id', $storeId)
            ->where('is_active', true)
            ->where(fn (Builder $inner) => $inner->whereNull('register_id')->when($registerId, fn ($q) => $q->orWhere('register_id', $registerId)));
    }
}
