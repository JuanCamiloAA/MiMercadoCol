<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    use HasFactory;

    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_CREDIT = 'credit';

    /** Saldo a favor del cliente (`StoreCreditService`). No es plata de la gaveta. */
    public const METHOD_STORE_CREDIT = 'store_credit';

    /**
     * Valor de una devolucion aplicado a la compra de cambio. Sale en negativo en la devolucion y
     * en positivo en la venta nueva: en el informe por medio de pago se anula solo.
     */
    public const METHOD_EXCHANGE = 'exchange';

    /** @var array<string, string> */
    public const METHOD_LABELS = [
        self::METHOD_CASH => 'Efectivo',
        self::METHOD_CARD => 'Tarjeta',
        self::METHOD_TRANSFER => 'Transferencia',
        self::METHOD_CREDIT => 'Credito',
        self::METHOD_STORE_CREDIT => 'Saldo a favor',
        self::METHOD_EXCHANGE => 'Cambio por devolución',
    ];

    /** Lo que un cajero puede elegir en un cobro normal. El cambio solo lo arma la devolucion. */
    public const POS_METHODS = [self::METHOD_CASH, self::METHOD_CARD, self::METHOD_TRANSFER, self::METHOD_CREDIT, self::METHOD_STORE_CREDIT];

    /**
     * Lo que de verdad entro por este medio.
     *
     * El monto de la linea de efectivo es lo que el cliente entrego, cambio incluido; lo que
     * quedo en la caja es el monto menos el cambio. Todo lo que cuente plata —el arqueo, el
     * informe Z y los informes por medio de pago— tiene que usar esta expresion y no `amount`
     * a secas, o el efectivo aparece inflado.
     */
    public const NET_AMOUNT_SQL = 'sale_payments.amount - COALESCE(sale_payments.change_amount, 0)';

    protected $fillable = [
        'sale_id', 'sequence', 'method', 'amount', 'received_amount', 'change_amount', 'reference',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount' => 'integer',
            'received_amount' => 'integer',
            'change_amount' => 'integer',
        ];
    }

    /** Monto sin el cambio entregado. */
    public function netAmount(): int
    {
        return (int) $this->amount - (int) ($this->change_amount ?? 0);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function methodLabel(): string
    {
        return self::METHOD_LABELS[$this->method] ?? $this->method;
    }
}
