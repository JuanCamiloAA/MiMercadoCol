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

    /** Cobro por pasarela en la tienda en linea. */
    public const METHOD_GATEWAY_CHECKOUT = 'gateway_checkout';

    /** QR o link de pago de la pasarela, en la caja. */
    public const METHOD_GATEWAY_LINK = 'gateway_link';

    /** Datafono integrado con la pasarela. */
    public const METHOD_GATEWAY_TERMINAL = 'gateway_terminal';

    public const METHOD_OTHER = 'other';

    /** @var array<string, string> */
    public const METHOD_LABELS = [
        self::METHOD_CASH => 'Efectivo',
        self::METHOD_CARD => 'Tarjeta',
        self::METHOD_TRANSFER => 'Transferencia',
        self::METHOD_CREDIT => 'Credito',
        self::METHOD_STORE_CREDIT => 'Saldo a favor',
        self::METHOD_EXCHANGE => 'Cambio por devolución',
        self::METHOD_GATEWAY_CHECKOUT => 'Pago en línea',
        self::METHOD_GATEWAY_LINK => 'QR o link de pago',
        self::METHOD_GATEWAY_TERMINAL => 'Datáfono integrado',
        self::METHOD_OTHER => 'Otro',
    ];

    /**
     * Los `kind` que un cajero puede elegir en un cobro normal. Cuales medios concretos valen en
     * esa caja lo decide `PaymentMethodAvailability`; el cambio solo lo arma la devolucion y la
     * pasarela de la caja entra por su propio flujo (la venta nace cuando aprueba).
     */
    public const POS_METHODS = [self::METHOD_CASH, self::METHOD_CARD, self::METHOD_TRANSFER, self::METHOD_CREDIT, self::METHOD_STORE_CREDIT, self::METHOD_OTHER];

    /**
     * Lo que de verdad entro por este medio.
     *
     * El monto de la linea de efectivo es lo que el cliente entrego, cambio incluido; lo que
     * quedo en la caja es el monto menos el cambio. Todo lo que cuente plata —el arqueo, el
     * informe Z y los informes por medio de pago— tiene que usar esta expresion y no `amount`
     * a secas, o el efectivo aparece inflado.
     */
    public const NET_AMOUNT_SQL = 'sale_payments.amount - COALESCE(sale_payments.change_amount, 0)';

    /**
     * `method` guarda el `kind` del medio configurado (`payment_method_id`): las reglas de plata
     * (redondeo, cambio, `NET_AMOUNT_SQL`, arqueo) siguen leyendolo. El nombre es solo texto.
     */
    protected $fillable = [
        'sale_id', 'sequence', 'payment_method_id', 'payment_transaction_id', 'method', 'amount', 'received_amount', 'change_amount', 'reference',
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

    /** El medio configurado con que se cobro: «Nequi», «Datáfono Bancolombia». */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withoutGlobalScopes();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id')->withoutGlobalScopes();
    }

    /** Lo que se lee: el nombre del medio configurado, o el del `kind` en pagos de antes. */
    public function methodLabel(): string
    {
        if ($this->payment_method_id !== null) {
            $name = $this->paymentMethod?->name;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return self::METHOD_LABELS[$this->method] ?? $this->method;
    }
}
