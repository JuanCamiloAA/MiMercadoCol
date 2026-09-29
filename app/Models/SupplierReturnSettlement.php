<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que respondio el proveedor por una devolucion: nota credito, reembolso o reposicion.
 */
class SupplierReturnSettlement extends Model
{
    public const TYPE_CREDIT_NOTE = 'credit_note';

    public const TYPE_REFUND = 'refund';

    /** El proveedor manda mercancia nueva: entra por una orden de compra de reposicion. */
    public const TYPE_REPLACEMENT = 'replacement';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_CREDIT_NOTE => 'Nota crédito',
        self::TYPE_REFUND => 'Reembolso',
        self::TYPE_REPLACEMENT => 'Reposición',
    ];

    public const METHOD_CASH = 'cash';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_OTHER = 'other';

    /** @var array<string, string> */
    public const METHOD_LABELS = [
        self::METHOD_CASH => 'Efectivo',
        self::METHOD_TRANSFER => 'Transferencia',
        self::METHOD_OTHER => 'Otro',
    ];

    protected $fillable = [
        'supplier_return_id', 'type', 'document_number', 'amount', 'received_at', 'payment_method',
        'purchase_order_id', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
