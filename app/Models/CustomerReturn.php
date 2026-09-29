<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una devolucion de venta: lo que el cliente trae de vuelta de una venta cobrada.
 *
 * En caja nace resuelta en el mismo acto. En la tienda en linea el cliente la solicita y pasa por
 * aprobada (por recibir), recibida (la mercancia ya esta en la sede y se decidio a donde va cada
 * articulo) y resuelta. Mientras esta abierta aparta sus cantidades: dos solicitudes no pueden
 * devolver mas de lo vendido. El estado lo mueve `CustomerReturnService`, nunca un update suelto.
 */
class CustomerReturn extends Model
{
    use BelongsToCompany;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_REQUESTED => 'Solicitada',
        self::STATUS_APPROVED => 'Aprobada, por recibir',
        self::STATUS_RECEIVED => 'Recibida, por resolver',
        self::STATUS_RESOLVED => 'Resuelta',
        self::STATUS_REJECTED => 'Rechazada',
        self::STATUS_CANCELLED => 'Anulada',
    ];

    /** Las que todavia apartan cantidades de la venta. */
    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_RECEIVED];

    public const RESOLUTION_REFUND = 'refund';

    public const RESOLUTION_STORE_CREDIT = 'store_credit';

    public const RESOLUTION_EXCHANGE = 'exchange';

    /** @var array<string, string> */
    public const RESOLUTION_LABELS = [
        self::RESOLUTION_REFUND => 'Reembolso',
        self::RESOLUTION_STORE_CREDIT => 'Saldo a favor',
        self::RESOLUTION_EXCHANGE => 'Cambio por otro artículo',
    ];

    /** Medios con que se devuelve plata. Tarjeta y transferencia se reversan por fuera. */
    public const REFUND_METHODS = [SalePayment::METHOD_CASH, SalePayment::METHOD_CARD, SalePayment::METHOD_TRANSFER];

    public const ORIGIN_POS = 'pos';

    public const ORIGIN_PANEL = 'panel';

    public const ORIGIN_STOREFRONT = 'storefront';

    /** @var array<string, string> */
    public const ORIGIN_LABELS = [
        self::ORIGIN_POS => 'Caja',
        self::ORIGIN_PANEL => 'Panel',
        self::ORIGIN_STOREFRONT => 'Tienda en línea',
    ];

    /** Fotos que puede adjuntar el cliente al pedir la devolucion. */
    public const MAX_PHOTOS = 3;

    protected $fillable = [
        'company_id', 'store_id', 'number', 'sale_id', 'order_id', 'customer_id', 'channel', 'origin', 'status',
        'resolution', 'refund_method', 'total', 'refunded_total', 'credited_total', 'exchanged_total', 'debt_relief_total',
        'refund_sale_id', 'exchange_sale_id', 'reason', 'customer_note', 'reject_reason', 'photos', 'out_of_window',
        'window_approved_by_user_id', 'requested_at', 'approved_at', 'approved_by_user_id', 'received_at',
        'received_by_user_id', 'resolved_at', 'resolved_by_user_id', 'rejected_at', 'rejected_by_user_id',
        'cancelled_at', 'cancelled_by_user_id', 'cancelled_by_customer', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'refunded_total' => 'integer',
            'credited_total' => 'integer',
            'exchanged_total' => 'integer',
            'debt_relief_total' => 'integer',
            'photos' => 'array',
            'out_of_window' => 'boolean',
            'cancelled_by_customer' => 'boolean',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'resolved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerReturnItem::class)->orderBy('id');
    }

    public function refundSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'refund_sale_id');
    }

    public function exchangeSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'exchange_sale_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function windowApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'window_approved_by_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function resolutionLabel(): ?string
    {
        return $this->resolution ? (self::RESOLUTION_LABELS[$this->resolution] ?? $this->resolution) : null;
    }

    /** El cliente puede retirar su solicitud mientras nadie haya recibido la mercancia. */
    public function customerCanCancel(): bool
    {
        return in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_APPROVED], true);
    }
}
