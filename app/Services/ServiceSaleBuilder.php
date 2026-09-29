<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServiceComponent;
use Illuminate\Validation\ValidationException;

/**
 * Convierte una linea de servicio del tiquete en la linea del servicio mas sus componentes.
 *
 * Siempre parte del maestro **fresco de la base**, compara contra lo que llega y clasifica cada
 * diferencia (quitado, menos, mas, agregado): de ahi sale que permiso hace falta. Los precios
 * los decide aqui, nunca el navegador: el servicio por `ServicePricing`, los articulos de un
 * servicio detallado a su precio vigente del canal y los de un servicio cerrado en cero.
 *
 * Un componente que es el articulo completo («Aceite de girasol», en la presentacion que se
 * elija) se puede cambiar por otra presentacion de la misma familia sin autorizacion: no es un
 * ajuste del servicio, es escoger cual. Se cobra la presentacion elegida.
 */
class ServiceSaleBuilder
{
    public const ACTION_REMOVE = 'service_remove';

    public const ACTION_REDUCE = 'service_reduce';

    public const ACTION_INCREASE = 'service_increase';

    public const ACTION_ADD = 'service_add';

    /** @var array<string, string> Tipo de cambio => lo que dice el error sin autorizacion. */
    public const ACTION_MESSAGES = [
        self::ACTION_REMOVE => 'Quitar artículos de un servicio necesita autorización.',
        self::ACTION_REDUCE => 'Usar menos cantidad de un artículo del servicio necesita autorización.',
        self::ACTION_INCREASE => 'Usar más cantidad de un artículo del servicio necesita autorización.',
        self::ACTION_ADD => 'Agregar a un servicio un artículo que no trae necesita autorización.',
    ];

    public function __construct(protected ServicePricing $pricing) {}

    /**
     * @param  array<string, mixed>  $line  service_id, quantity, unit_price?, discount?, components?: list<{product_id, quantity, discount?, unit_price?}>
     * @param  bool  $lockedPrices  Los precios vienen fijos (un pedido ya tomado): se respetan tal cual.
     * @return array{
     *     service: Service,
     *     line: array<string, mixed>,
     *     components: list<array<string, mixed>>,
     *     changes: list<string>,
     *     manual_discount: int
     * }
     */
    public function build(array $line, int $companyId, string $channel, bool $lockedPrices = false): array
    {
        $service = Service::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with(['components.product', 'components.family'])
            ->find((int) ($line['service_id'] ?? 0));

        if (! $service || (! $service->is_active && ! $lockedPrices)) {
            throw ValidationException::withMessages(['items' => ['Uno de los servicios del tiquete ya no está disponible.']]);
        }

        $quantity = round((float) ($line['quantity'] ?? 0), 3);

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['items' => ["La cantidad de {$service->name} debe ser mayor que cero."]]);
        }

        // Lo que dice el maestro para esta cantidad de servicio. De cada articulo completo se
        // recuerda su familia: cualquier presentacion suya ocupa ese mismo renglon.
        $master = [];
        $familyMaster = [];

        foreach ($service->components as $component) {
            /** @var ServiceComponent $component */
            $master[(int) $component->product_id] = [
                'component' => $component,
                'quantity' => round((float) $component->quantity * $quantity, 3),
            ];

            if ($component->isFamily()) {
                $familyMaster[(int) $component->product_family_id] = (int) $component->product_id;
            }
        }

        // Sin detalle de componentes se vende tal cual el maestro.
        $incoming = array_key_exists('components', $line) && is_array($line['components'])
            ? $line['components']
            : array_map(fn (array $entry) => ['product_id' => (int) $entry['component']->product_id, 'quantity' => $entry['quantity']], $master);

        $products = Product::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', array_map(fn ($entry) => (int) ($entry['product_id'] ?? 0), $incoming))
            ->get()
            ->keyBy('id');

        $itemized = $service->isItemized();
        $changes = [];
        $components = [];
        $seen = [];
        // Renglon del maestro => el articulo que lo ocupa en esta venta (el mismo o otra presentacion).
        $taken = [];
        $swapped = [];
        $manualDiscount = 0;

        foreach ($incoming as $entry) {
            $productId = (int) ($entry['product_id'] ?? 0);
            $product = $products->get($productId);
            $componentQuantity = round((float) ($entry['quantity'] ?? 0), 3);

            if (! $product || isset($seen[$productId])) {
                throw ValidationException::withMessages(['items' => ["Uno de los artículos de {$service->name} no existe o está repetido."]]);
            }

            $seen[$productId] = true;

            // S6: un articulo inactivo o eliminado no se cobra; hay que quitarlo o corregir el maestro.
            if (($product->trashed() || ! $product->is_active) && ! $lockedPrices) {
                throw ValidationException::withMessages([
                    'items' => ["{$product->name} está inactivo o eliminado: quítalo de {$service->name} o corrige el servicio."],
                ]);
            }

            if ($componentQuantity <= 0) {
                continue;
            }

            if (! $product->sold_by_weight && floor($componentQuantity) !== $componentQuantity) {
                throw ValidationException::withMessages(['items' => ["{$product->name} va en unidades enteras dentro de {$service->name}."]]);
            }

            // Otra presentacion del articulo completo: ocupa su renglon, sin pedir autorizacion.
            $masterKey = $productId;

            if (! isset($master[$productId]) && $product->product_family_id !== null) {
                $candidate = $familyMaster[(int) $product->product_family_id] ?? null;

                if ($candidate !== null && ! isset($taken[$candidate])) {
                    $masterKey = $candidate;
                    $swapped[] = [
                        'master_product_id' => $candidate,
                        'product_id' => $productId,
                        'name' => $product->name,
                    ];
                }
            }

            // Un renglon del maestro lo ocupa un solo articulo: una segunda presentacion del mismo
            // articulo completo en la misma linea es algo agregado.
            $masterEntry = isset($taken[$masterKey]) && $taken[$masterKey] !== $productId ? null : ($master[$masterKey] ?? null);

            if ($masterEntry !== null) {
                $taken[$masterKey] = $productId;
            }

            $change = match (true) {
                $masterEntry === null => SaleItem::CHANGE_ADDED,
                $componentQuantity < $masterEntry['quantity'] - 0.0005 => SaleItem::CHANGE_REDUCED,
                $componentQuantity > $masterEntry['quantity'] + 0.0005 => SaleItem::CHANGE_INCREASED,
                default => null,
            };

            if ($change !== null) {
                $changes[] = match ($change) {
                    SaleItem::CHANGE_ADDED => self::ACTION_ADD,
                    SaleItem::CHANGE_REDUCED => self::ACTION_REDUCE,
                    default => self::ACTION_INCREASE,
                };
            }

            $unitPrice = 0;
            $discount = 0;

            if ($itemized) {
                $unitPrice = $lockedPrices && isset($entry['unit_price'])
                    ? max(0, (int) $entry['unit_price'])
                    : $this->pricing->priceInService($masterEntry['component'] ?? null, $product, $channel);
                $discount = max(0, (int) ($entry['discount'] ?? 0));
                $manualDiscount += $lockedPrices ? 0 : $discount;
            }

            $components[] = [
                'product' => $product,
                'description' => $product->name,
                'quantity' => $componentQuantity,
                'unit_price' => $unitPrice,
                'tax_rate' => (float) $product->tax_rate,
                'discount' => $discount,
                'unit_cost' => (int) $product->cost,
                'master_quantity' => $masterEntry['quantity'] ?? null,
                'component_change' => $change,
                'returnable_by_default' => $masterEntry ? (bool) $masterEntry['component']->returnable_by_default : true,
            ];
        }

        $removed = [];

        foreach ($master as $productId => $entry) {
            $occupant = $taken[$productId] ?? null;

            if ($occupant === null || ! collect($components)->contains(fn ($component) => $component['product']->id === $occupant)) {
                $removed[] = [
                    'product_id' => $productId,
                    'name' => $entry['component']->product?->name ?? 'Artículo eliminado',
                    'master_quantity' => $entry['quantity'],
                ];
                $changes[] = self::ACTION_REMOVE;
            }
        }

        // El precio del servicio: digitado si es variable; fijo si viene de un pedido; si no, el del canal.
        if ($service->open_price && ! $lockedPrices) {
            $unitPrice = (int) ($line['unit_price'] ?? 0);

            if ($unitPrice < 1) {
                throw ValidationException::withMessages(['items' => ["Digita el valor de {$service->name}: tiene precio variable."]]);
            }
        } elseif ($lockedPrices && isset($line['unit_price'])) {
            $unitPrice = max(0, (int) $line['unit_price']);
        } else {
            $unitPrice = $this->pricing->serviceValue($service, $channel);
        }

        $discount = max(0, (int) ($line['discount'] ?? 0));
        $manualDiscount += $lockedPrices ? 0 : $discount;

        return [
            'service' => $service,
            'line' => [
                'service' => $service,
                'description' => $service->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => (float) $service->tax_rate,
                'discount' => $discount,
                'unit_cost' => (int) $service->cost,
                // La venta no depende de como quede el maestro despues.
                'service_snapshot' => [
                    'pricing_mode' => $service->pricing_mode,
                    'open_price' => (bool) $service->open_price,
                    'master' => $service->components->map(fn (ServiceComponent $component) => [
                        'product_id' => (int) $component->product_id,
                        'product_family_id' => $component->product_family_id,
                        'family' => $component->family?->name,
                        'name' => $component->product?->name,
                        'quantity' => round((float) $component->quantity, 3),
                        'unit_price' => $component->unit_price,
                        'returnable_by_default' => (bool) $component->returnable_by_default,
                    ])->values()->all(),
                    'removed' => $removed,
                    // La presentacion que se eligio en vez de la del maestro, en cada articulo completo.
                    'swapped' => $swapped,
                    'authorized_by_user_id' => null,
                ],
            ],
            'components' => $components,
            'changes' => array_values(array_unique($changes)),
            'manual_discount' => $manualDiscount,
        ];
    }
}
