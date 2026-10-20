<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento del saldo a favor de un cliente. Los abonos (monto positivo) llevan lo que les
 * queda y cuando vencen; los cargos solo el monto. La suma de `amount` es el saldo. Solo lo
 * escribe `StoreCreditService`.
 */
class StoreCreditMovement extends Model
{
    use BelongsToCompany;

    public const TYPE_RETURN_CREDIT = 'return_credit';

    public const TYPE_SALE_PAYMENT = 'sale_payment';

    public const TYPE_SALE_REVERSAL = 'sale_reversal';

    public const TYPE_ORDER_HOLD = 'order_hold';

    public const TYPE_ORDER_RELEASE = 'order_release';

    public const TYPE_EXPIRED = 'expired';

    /** Un pedido pagado en linea que se entrego por menos: la diferencia vuelve como saldo. */
    public const TYPE_ONLINE_OVERPAID = 'online_overpaid';

    /** Un pago por pasarela que no termino en venta y se le abono al cliente (pagos por conciliar). */
    public const TYPE_GATEWAY_CREDIT = 'gateway_credit';

    /** Lo que se le devolvio por la pasarela en vez de dejarlo como saldo. */
    public const TYPE_GATEWAY_REFUND = 'gateway_refund';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_ONLINE_OVERPAID => 'Diferencia de un pedido pagado en línea',
        self::TYPE_GATEWAY_CREDIT => 'Pago en línea abonado',
        self::TYPE_GATEWAY_REFUND => 'Reembolsado por la pasarela',
        self::TYPE_RETURN_CREDIT => 'Devolución',
        self::TYPE_SALE_PAYMENT => 'Pago de una compra',
        self::TYPE_SALE_REVERSAL => 'Compra anulada o devuelta',
        self::TYPE_ORDER_HOLD => 'Pedido en línea',
        self::TYPE_ORDER_RELEASE => 'Pedido cancelado o ajustado',
        self::TYPE_EXPIRED => 'Vencido',
    ];

    protected $fillable = [
        'company_id', 'customer_id', 'type', 'amount', 'remaining', 'expires_at', 'customer_return_id',
        'sale_id', 'order_id', 'user_id', 'note', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'remaining' => 'integer',
            'expires_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
