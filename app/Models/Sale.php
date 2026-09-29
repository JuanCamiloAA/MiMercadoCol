<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUS_PAID = 'paid';

    public const STATUS_CREDIT = 'credit';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const CHANNEL_POS = 'pos';

    public const CHANNEL_ONLINE = 'online';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PAID => 'Pagada',
        self::STATUS_CREDIT => 'A credito',
        self::STATUS_CANCELLED => 'Anulada',
        self::STATUS_REFUNDED => 'Devuelta',
    ];

    protected $fillable = [
        'company_id', 'store_id', 'register_session_id', 'customer_id', 'user_id', 'seller_employee_id',
        'authorized_by_user_id', 'number',
        'channel', 'status', 'subtotal', 'tax_total', 'discount_total', 'rounding_total', 'total', 'paid_total',
        'due_total', 'due_date', 'note', 'sold_at', 'cancelled_at', 'cancelled_by_user_id', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'discount_total' => 'integer',
            'rounding_total' => 'integer',
            'total' => 'integer',
            'paid_total' => 'integer',
            'due_total' => 'integer',
            'due_date' => 'date',
            'sold_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /** Las devoluciones que se le han hecho a esta venta (no la venta en negativo). */
    public function customerReturns(): HasMany
    {
        return $this->hasMany(CustomerReturn::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Quien cobro: el que tenia la sesion abierta en el sistema. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quien vendio.
     *
     * Puede no ser el mismo que cobro: la caja la abre una persona y la venta la puede hacer
     * otra. Es la ficha de empleado, no el usuario, porque un asesor puede vender sin tener
     * cuenta para entrar al sistema.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'seller_employee_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(RegisterSession::class, 'register_session_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PAID, self::STATUS_CREDIT, self::STATUS_REFUNDED]);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CREDIT)->where('due_total', '>', 0);
    }
}
