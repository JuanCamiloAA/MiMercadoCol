<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use BelongsToCompany, HasFactory;

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_SALE = 'sale';

    public const TYPE_RETURN = 'return';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_WASTE = 'waste';

    /**
     * Mercancia que sale hacia el proveedor (negativa) o que el proveedor rechazo y volvio
     * (positiva). No es `return`, que es de clientes, ni `waste`, que los informes leen como averia.
     */
    public const TYPE_SUPPLIER_RETURN = 'supplier_return';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_PURCHASE => 'Compra',
        self::TYPE_SALE => 'Venta',
        self::TYPE_RETURN => 'Devolucion',
        self::TYPE_ADJUSTMENT => 'Ajuste',
        self::TYPE_TRANSFER_IN => 'Traslado entrante',
        self::TYPE_TRANSFER_OUT => 'Traslado saliente',
        self::TYPE_WASTE => 'Averia',
        self::TYPE_SUPPLIER_RETURN => 'Devolución a proveedor',
    ];

    /** Una entrada suma existencias; una salida las resta. */
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /**
     * Motivos de una entrada o salida registrada a mano, con el tipo de kardex al que apunta.
     *
     * El motivo no tiene columna propia a proposito: lo que queda guardado es el `type`
     * (`waste` sale como averia en los informes) y el motivo escrito al frente de la nota. Una
     * columna que ningun informe lee seria un dato muerto.
     *
     * Ninguno apunta a `return`: esa devolucion nace de una venta y lleva su referencia. Una
     * devolucion suelta aqui dejaria el informe de devoluciones contando cosas sin venta.
     *
     * @var array<string, array<string, array{label: string, type: string}>>
     */
    public const REASONS = [
        self::DIRECTION_IN => [
            'count' => ['label' => 'Sobrante de conteo', 'type' => self::TYPE_ADJUSTMENT],
            'supplier' => ['label' => 'Mercancia recibida sin orden', 'type' => self::TYPE_ADJUSTMENT],
            'production' => ['label' => 'Produccion o armado interno', 'type' => self::TYPE_ADJUSTMENT],
            'correction' => ['label' => 'Correccion de un error', 'type' => self::TYPE_ADJUSTMENT],
        ],
        self::DIRECTION_OUT => [
            'damage' => ['label' => 'Averia o rotura', 'type' => self::TYPE_WASTE],
            'expired' => ['label' => 'Vencido', 'type' => self::TYPE_WASTE],
            'loss' => ['label' => 'Perdida o hurto', 'type' => self::TYPE_WASTE],
            'count' => ['label' => 'Faltante de conteo', 'type' => self::TYPE_ADJUSTMENT],
            'internal' => ['label' => 'Consumo interno', 'type' => self::TYPE_ADJUSTMENT],
            'sample' => ['label' => 'Muestra o degustacion', 'type' => self::TYPE_ADJUSTMENT],
            'correction' => ['label' => 'Correccion de un error', 'type' => self::TYPE_ADJUSTMENT],
        ],
    ];

    protected $fillable = [
        'company_id', 'product_id', 'store_id', 'warehouse_id', 'type', 'quantity', 'balance_after',
        'unit_cost', 'reference_type', 'reference_id', 'user_id', 'note', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'balance_after' => 'float',
            'unit_cost' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /**
     * Los motivos de una direccion, listos para pintar un selector.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function reasonsFor(string $direction): array
    {
        $reasons = self::REASONS[$direction] ?? [];

        return array_values(array_map(
            fn (string $key, array $reason) => ['value' => $key, 'label' => $reason['label']],
            array_keys($reasons),
            $reasons,
        ));
    }

    public static function reasonType(string $direction, string $reason): ?string
    {
        return self::REASONS[$direction][$reason]['type'] ?? null;
    }

    public static function reasonLabel(string $direction, string $reason): ?string
    {
        return self::REASONS[$direction][$reason]['label'] ?? null;
    }

    /**
     * El documento que se puede abrir desde el kardex, por movimiento.
     *
     * Una sola consulta por tipo de documento, no una por fila.
     *
     * @param  iterable<StockMovement>  $movements
     * @return array<int, array{kind: string, id: int, label: string}>
     */
    public static function referenceLinks(iterable $movements): array
    {
        $returnIds = [];
        $customerReturnIds = [];
        $links = [];

        foreach ($movements as $movement) {
            if ($movement->reference_type === (new SupplierReturn)->getMorphClass() && $movement->reference_id) {
                $returnIds[$movement->id] = (int) $movement->reference_id;
            }

            // Lo que el cliente devolvio (al inventario o a averia) lleva su documento DV.
            if ($movement->reference_type === (new CustomerReturn)->getMorphClass() && $movement->reference_id) {
                $customerReturnIds[$movement->id] = (int) $movement->reference_id;
            }

            // Lo que salio (o volvio) como articulo de un servicio lo dice su nota, que escribe
            // `SaleService` al mismo tiempo que el movimiento.
            if (preg_match('/^Servicio (.+) — venta /u', (string) $movement->note, $match)
                || preg_match('/\(servicio (.+)\)$/u', (string) $movement->note, $match)) {
                $links[$movement->id] = ['kind' => 'service', 'id' => 0, 'label' => $match[1]];
            }
        }

        if ($customerReturnIds !== []) {
            $customerNumbers = CustomerReturn::query()->withoutGlobalScopes()
                ->whereIn('id', array_unique($customerReturnIds))
                ->pluck('number', 'id');

            foreach ($customerReturnIds as $movementId => $returnId) {
                if ($customerNumbers->has($returnId)) {
                    $links[$movementId] = ['kind' => 'customer_return', 'id' => $returnId, 'label' => $customerNumbers[$returnId]];
                }
            }
        }

        if ($returnIds === []) {
            return $links;
        }

        $numbers = SupplierReturn::query()->withoutGlobalScopes()
            ->whereIn('id', array_unique($returnIds))
            ->pluck('number', 'id');

        foreach ($returnIds as $movementId => $returnId) {
            if ($numbers->has($returnId)) {
                $links[$movementId] = ['kind' => 'supplier_return', 'id' => $returnId, 'label' => $numbers[$returnId]];
            }
        }

        return $links;
    }
}
