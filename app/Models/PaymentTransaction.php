<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Un intento de cobro por pasarela: el checkout de un pedido, un QR o link de la caja, o un
 * cobro mandado a un datafono.
 *
 * La fila nace `pending` ANTES de llamar a la pasarela: al reves, un cobro exitoso seguido de una
 * caida dejaria plata sin rastro. `reference` es la llave de idempotencia; el sondeo y el webhook
 * terminan en el mismo metodo, que bloquea la fila.
 *
 * `payload` guarda lo que respondio la pasarela, saneado: nunca numeros de tarjeta ni llaves.
 * Los centavos que piden algunas pasarelas se calculan solo en el conector: aqui todo es COP entero.
 */
class PaymentTransaction extends Model
{
    use BelongsToCompany;

    public const REFERENCE_PREFIX = 'PAY-';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ERROR = 'error';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_DECLINED, self::STATUS_VOIDED, self::STATUS_EXPIRED, self::STATUS_ERROR];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_APPROVED => 'Aprobada',
        self::STATUS_DECLINED => 'Rechazada',
        self::STATUS_VOIDED => 'Anulada',
        self::STATUS_EXPIRED => 'Vencida',
        self::STATUS_ERROR => 'Con error',
    ];

    /** Final: ya no cambia por un evento que llegue tarde. */
    public const FINAL_STATUSES = [self::STATUS_APPROVED, self::STATUS_DECLINED, self::STATUS_VOIDED, self::STATUS_EXPIRED, self::STATUS_ERROR];

    public const CHANNEL_POS = 'pos';

    public const CHANNEL_ONLINE = 'online';

    /** Como se cobra: checkout de la tienda, link o QR de la caja, o datafono. */
    public const MODE_CHECKOUT = 'checkout';

    public const MODE_LINK = 'link';

    public const MODE_QR = 'qr';

    public const MODE_TERMINAL = 'terminal';

    /** Como se resolvio una aprobada que no termino en venta ni pedido (pagos por conciliar). */
    public const RESOLUTION_APPLIED = 'applied';

    public const RESOLUTION_STORE_CREDIT = 'store_credit';

    public const RESOLUTION_REFUNDED_OUTSIDE = 'refunded_outside';

    public const RESOLUTION_REFUNDED_GATEWAY = 'refunded_gateway';

    public const RESOLUTION_LABELS = [
        self::RESOLUTION_APPLIED => 'Aplicada a una venta',
        self::RESOLUTION_STORE_CREDIT => 'Abonada como saldo a favor',
        self::RESOLUTION_REFUNDED_OUTSIDE => 'Reembolsada por fuera',
        self::RESOLUTION_REFUNDED_GATEWAY => 'Reembolsada por la pasarela',
    ];

    protected $fillable = [
        'company_id', 'store_id', 'register_session_id', 'payment_method_id', 'gateway_account_id', 'payment_terminal_id',
        'provider', 'environment', 'reference', 'amount', 'currency', 'channel', 'mode', 'payable_type', 'payable_id',
        'status', 'status_detail', 'gateway_transaction_id', 'checkout_url', 'qr_payload', 'terminal_id', 'gateway_link_id',
        'expires_at', 'approved_at', 'applied_at', 'cancelled_at', 'payload', 'context',
        'resolution', 'resolution_note', 'resolved_by_user_id', 'resolved_at', 'refunded_amount', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'refunded_amount' => 'integer',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'resolved_at' => 'datetime',
            'payload' => 'array',
            'context' => 'array',
        ];
    }

    public static function newReference(): string
    {
        return self::REFERENCE_PREFIX.Str::upper((string) Str::ulid());
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function gatewayAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayAccount::class, 'gateway_account_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PaymentTerminal::class, 'payment_terminal_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Los tiquetes suspendidos que esperan un cobro de pasarela de una sede. No se pueden retomar
     * mientras tanto: cobrarlos por otro lado y que despues apruebe el QR seria cobrar dos veces.
     *
     * @return list<int>
     */
    public static function heldSaleIdsAwaiting(int $storeId): array
    {
        return static::query()
            ->where('store_id', $storeId)
            ->where('channel', self::CHANNEL_POS)
            ->where('status', self::STATUS_PENDING)
            ->get(['context'])
            ->map(fn (self $transaction) => (int) (($transaction->context ?? [])['held_sale_id'] ?? 0))
            ->filter()
            ->values()
            ->all();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Pagos por conciliar: la pasarela aprobo y la plata entro, pero no termino en ninguna venta
     * ni pedido, ni nadie decidio que hacer con ella.
     */
    public function scopeToReconcile(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->whereNull('applied_at')
            ->whereNull('resolution');
    }
}
