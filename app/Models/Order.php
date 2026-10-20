<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * Pagado en linea y esperando a la pasarela. No esta en la bandeja de alistamiento ni cuenta
     * como pedido nuevo: entra a `new` cuando la pasarela aprueba, o se cancela si vence.
     */
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_NEW = 'new';

    public const STATUS_PICKING = 'picking';

    public const STATUS_READY = 'ready';

    public const STATUS_ON_ROUTE = 'on_route';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_RETURNED = 'returned';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING_PAYMENT => 'Esperando el pago',
        self::STATUS_NEW => 'Nuevo',
        self::STATUS_PICKING => 'En alistamiento',
        self::STATUS_READY => 'Listo',
        self::STATUS_ON_ROUTE => 'En ruta',
        self::STATUS_DELIVERED => 'Entregado',
        self::STATUS_CANCELLED => 'Cancelado',
        self::STATUS_RETURNED => 'Devuelto',
    ];

    /** Transiciones validas; cualquier otro salto lo rechaza OrderService. */
    public const ALLOWED_TRANSITIONS = [
        // Lo mueve solo la pasarela (`OnlinePaymentService`): nadie lo pasa a mano.
        self::STATUS_PENDING_PAYMENT => [],
        self::STATUS_NEW => [self::STATUS_PICKING, self::STATUS_CANCELLED],
        self::STATUS_PICKING => [self::STATUS_READY, self::STATUS_CANCELLED],
        self::STATUS_READY => [self::STATUS_ON_ROUTE, self::STATUS_CANCELLED],
        self::STATUS_ON_ROUTE => [self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_DELIVERED => [self::STATUS_RETURNED],
        self::STATUS_CANCELLED => [],
        self::STATUS_RETURNED => [],
    ];

    public const CHANNEL_APP = 'app';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_COUNTER = 'counter';

    /** @var array<string, string> */
    public const CHANNEL_LABELS = [
        self::CHANNEL_APP => 'Tienda en linea',
        self::CHANNEL_WHATSAPP => 'WhatsApp',
        self::CHANNEL_COUNTER => 'Mostrador',
    ];

    public const PAYMENT_CASH_ON_DELIVERY = 'cash_on_delivery';

    public const PAYMENT_CARD = 'card';

    public const PAYMENT_TRANSFER = 'transfer';

    public const PAYMENT_CREDIT = 'credit';

    /** Pagado en linea por la pasarela de la empresa. */
    public const PAYMENT_GATEWAY = 'gateway_checkout';

    public const PAYMENT_OTHER = 'other';

    /** @var array<string, string> */
    public const PAYMENT_LABELS = [
        self::PAYMENT_CASH_ON_DELIVERY => 'Contraentrega',
        self::PAYMENT_CARD => 'Tarjeta',
        self::PAYMENT_TRANSFER => 'Transferencia',
        self::PAYMENT_CREDIT => 'Credito',
        self::PAYMENT_GATEWAY => 'Pago en línea',
        self::PAYMENT_OTHER => 'Otro',
    ];

    /**
     * `payment_method` guarda el `kind` del medio configurado, con el nombre de siempre para el
     * efectivo (`cash_on_delivery`): asi los pedidos de antes y los de ahora se leen igual.
     */
    public const PAYMENT_KEY_FOR_KIND = [
        PaymentMethod::KIND_CASH => self::PAYMENT_CASH_ON_DELIVERY,
        PaymentMethod::KIND_CARD => self::PAYMENT_CARD,
        PaymentMethod::KIND_TRANSFER => self::PAYMENT_TRANSFER,
        PaymentMethod::KIND_CREDIT => self::PAYMENT_CREDIT,
        PaymentMethod::KIND_GATEWAY_CHECKOUT => self::PAYMENT_GATEWAY,
        PaymentMethod::KIND_OTHER => self::PAYMENT_OTHER,
    ];

    protected $fillable = [
        'company_id', 'store_id', 'warehouse_id', 'customer_id', 'number', 'channel', 'status', 'payment_method', 'payment_method_id',
        'payment_status', 'delivery_address', 'delivery_notes', 'delivery_slot_start', 'delivery_slot_end',
        'courier_user_id', 'subtotal', 'delivery_fee', 'discount_total', 'tax_total', 'total',
        'sale_id', 'placed_at', 'delivered_at', 'cancel_reason', 'store_credit_amount',
    ];

    protected function casts(): array
    {
        return [
            'delivery_slot_start' => 'datetime',
            'delivery_slot_end' => 'datetime',
            'placed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'subtotal' => 'integer',
            'delivery_fee' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'store_credit_amount' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('changed_at');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_user_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** El medio configurado con que el cliente dijo que pagaba. */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withoutGlobalScopes();
    }

    public static function paymentKeyFor(string $kind): string
    {
        return self::PAYMENT_KEY_FOR_KIND[$kind] ?? $kind;
    }

    public static function kindFor(string $paymentKey): string
    {
        return array_search($paymentKey, self::PAYMENT_KEY_FOR_KIND, true) ?: $paymentKey;
    }

    /** Lo que se lee: el medio configurado o, en pedidos de antes, la etiqueta de siempre. */
    public function paymentLabel(): string
    {
        return $this->paymentMethod?->name ?? (self::PAYMENT_LABELS[$this->payment_method] ?? $this->payment_method);
    }

    /** El cupon que se uso al pedir, si hubo alguno. */
    public function couponRedemption(): HasOne
    {
        return $this->hasOne(CouponRedemption::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }
}
