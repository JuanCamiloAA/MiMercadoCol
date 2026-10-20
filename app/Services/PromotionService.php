<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Services\Membership\MembershipFeatures;
use Illuminate\Support\Collection;

/**
 * Precios con promocion.
 *
 * Unico sitio que decide cuanto vale un articulo hoy. Lo usan la tienda en linea para pintar
 * el precio tachado, el carrito, el punto de venta y el registro de la venta, de modo que lo
 * que el cliente ve en el celular es lo mismo que le cobran en la caja cuando la promocion
 * aplica en los dos canales.
 *
 * Hay dos familias de promocion y se resuelven distinto:
 *  - Las de **precio unitario** (precio especial y porcentaje) bajan el valor de cada unidad.
 *  - Las de **linea** (lleva N paga M y combos) dependen de cuantas unidades lleve el cliente,
 *    asi que solo se pueden calcular cuando ya hay un carrito.
 */
class PromotionService
{
    public const CHANNEL_ONLINE = 'online';

    public const CHANNEL_POS = 'pos';

    /** @var array<string, Collection<int, Promotion>> */
    protected array $cache = [];

    /** @var array<int, bool> si cada empresa tiene promociones en su plan */
    protected array $features = [];

    /**
     * Promociones vigentes de la empresa para ese canal.
     *
     * @return Collection<int, Promotion>
     */
    public function running(int $companyId, string $channel): Collection
    {
        $key = $companyId.':'.$channel;

        // Sin promociones en el plan no rebaja nada, ni en la caja ni en linea: el precio es el
        // de lista, y la pantalla que lo muestra pregunta aqui mismo, asi que no se descuadra.
        if (! ($this->features[$companyId] ??= (bool) Company::query()->find($companyId)?->hasFeature(MembershipFeatures::PROMOTIONS))) {
            return $this->cache[$key] = new Collection;
        }

        return $this->cache[$key] ??= Promotion::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->running()
            ->forChannel($channel)
            ->with('targets')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Lo que alcanzan, juntas, las promociones vigentes de un canal: articulos, categorias
     * (exactas, como en `reaches()`) y articulos principales. Sirve para marcar y filtrar en una
     * sola consulta —«Promoción activa» en «Tienda en línea»— sin preguntar articulo por articulo.
     *
     * @return array{product_ids: list<int>, category_ids: list<int>, family_ids: list<int>}
     */
    public function reach(int $companyId, string $channel): array
    {
        $reach = ['product_ids' => [], 'category_ids' => [], 'family_ids' => []];

        foreach ($this->running($companyId, $channel) as $promotion) {
            foreach ($promotion->targets as $target) {
                $key = match ($target->target_type) {
                    PromotionTarget::TYPE_PRODUCT => 'product_ids',
                    PromotionTarget::TYPE_CATEGORY => 'category_ids',
                    PromotionTarget::TYPE_FAMILY => 'family_ids',
                    default => null,
                };

                if ($key !== null) {
                    $reach[$key][] = (int) $target->target_id;
                }
            }
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $reach);
    }

    /** Olvida lo memorizado: hace falta tras crear o editar una promocion en la misma peticion. */
    public function forget(): void
    {
        $this->cache = [];
        $this->features = [];
    }

    /**
     * Precio de lista del canal.
     *
     * La tienda en linea puede tener su propia lista (`online_price`); un 20% alli se descuenta
     * sobre ese precio, no sobre el del mostrador.
     */
    public function basePrice(object $product, string $channel): int
    {
        if ($channel === self::CHANNEL_ONLINE && ! empty($product->online_price)) {
            return (int) $product->online_price;
        }

        return (int) $product->price;
    }

    /**
     * Precio vigente de cada articulo, con el de lista para tacharlo.
     *
     * @param  Collection<int, Product>|iterable<Product>  $products
     * @return array<int, array{price: int, compare_at: int|null, promotion_id: int, badge: string, name: string}>
     */
    public function unitPrices(iterable $products, int $companyId, string $channel): array
    {
        $promotions = $this->running($companyId, $channel)
            ->filter(fn (Promotion $promotion) => in_array($promotion->type, Promotion::UNIT_TYPES, true));

        if ($promotions->isEmpty()) {
            return [];
        }

        $result = [];

        foreach ($products as $product) {
            $best = null;

            $base = $this->basePrice($product, $channel);

            foreach ($promotions as $promotion) {
                $price = $this->unitPriceFor($promotion, $product, $channel);

                if ($price === null || $price >= $base) {
                    continue;
                }

                // Manda la prioridad; a igual prioridad, la que le sirva mejor al cliente.
                if ($best === null || $promotion->priority > $best['priority'] || ($promotion->priority === $best['priority'] && $price < $best['price'])) {
                    $best = [
                        'priority' => $promotion->priority,
                        'price' => $price,
                        'compare_at' => $base,
                        'promotion_id' => $promotion->id,
                        'badge' => $promotion->badge(),
                        'name' => $promotion->name,
                    ];
                }
            }

            if ($best !== null) {
                unset($best['priority']);
                $result[(int) $product->id] = $best;
            }
        }

        return $result;
    }

    /**
     * Que promocion toca cada articulo, sea del tipo que sea.
     *
     * `unitPrices()` solo conoce las que cambian el precio; esta tambien reporta las que
     * dependen de la cantidad —el 3x2, el combo—, que no se notan en el precio unitario pero
     * el cajero tiene que poder verlas al seleccionar el articulo.
     *
     * @param  iterable<object>  $products
     * @return array<int, array<string, mixed>>
     */
    public function descriptors(iterable $products, int $companyId, string $channel): array
    {
        $promotions = $this->running($companyId, $channel);

        if ($promotions->isEmpty()) {
            return [];
        }

        $products = collect($products);
        $unit = $this->unitPrices($products, $companyId, $channel);
        $result = [];

        foreach ($products as $product) {
            $id = (int) $product->id;
            $priced = $unit[$id] ?? null;

            // La que baja el precio manda; si no hay, la de mayor prioridad que lo alcance.
            $promotion = $priced
                ? $promotions->firstWhere('id', $priced['promotion_id'])
                : $promotions->first(fn (Promotion $candidate) => $this->reaches($candidate, $product));

            if (! $promotion) {
                continue;
            }

            $result[$id] = [
                'id' => $promotion->id,
                'name' => $promotion->name,
                'badge' => $promotion->badge(),
                'hint' => $promotion->hint().$this->familySuffix($promotion, $product),
                'type' => $promotion->type,
                'depends_on_quantity' => $promotion->dependsOnQuantity(),
                // Cuantas unidades del mismo articulo hacen falta para que aplique, para que
                // la caja pueda decir «lleva una mas» en vez de un vago «falta cantidad».
                'needs' => $promotion->type === Promotion::TYPE_N_FOR_M
                    ? (int) ($promotion->config['buy'] ?? 0)
                    : null,
                'price' => $priced['price'] ?? null,
                'compare_at' => $priced['compare_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * « en cualquier presentación» / « en cualquier talla»: cuando la promocion va al articulo
     * principal, las unidades de sus subarticulos se juntan y el cliente lo tiene que saber.
     */
    protected function familySuffix(Promotion $promotion, object $product): string
    {
        $familyId = (int) ($product->product_family_id ?? 0);

        if ($familyId === 0 || ! $promotion->targets->contains(fn (PromotionTarget $target) => $target->target_type === PromotionTarget::TYPE_FAMILY && $target->target_id === $familyId)) {
            return '';
        }

        $attribute = ProductFamily::query()->withoutGlobalScopes()->with('attribute1')->find($familyId)?->attribute1;

        return ' en cualquier '.($attribute === null || $attribute->isMeasure() ? 'presentación' : mb_strtolower($attribute->name));
    }

    /**
     * Precio con promocion de un articulo suelto, o el de lista si no tiene ninguna.
     */
    public function priceFor(object $product, int $companyId, string $channel): int
    {
        $prices = $this->unitPrices([$product], $companyId, $channel);

        return $prices[(int) $product->id]['price'] ?? $this->basePrice($product, $channel);
    }

    /**
     * Aplica las promociones a las lineas de un carrito o de una venta.
     *
     * Devuelve las mismas lineas con `unit_price` ya rebajado por las promociones unitarias y
     * `discount` aumentado por las de linea, mas el detalle de que promocion toco que.
     *
     * @param  list<array{product_id: int, quantity: float, unit_price?: int|null, discount?: int}>  $lines
     * @return array{lines: list<array<string, mixed>>, applied: list<array{promotion_id: int, name: string, badge: string, amount: int}>}
     */
    public function apply(array $lines, int $companyId, string $channel): array
    {
        if ($lines === []) {
            return ['lines' => [], 'applied' => []];
        }

        // Una linea puede no tener articulo (el domicilio, por ejemplo): ninguna promocion
        // le aplica y se deja tal cual venga.
        $ids = array_values(array_filter(array_map(
            fn ($line) => isset($line['product_id']) ? (int) $line['product_id'] : null,
            $lines
        )));

        if ($ids === []) {
            return ['lines' => array_values($lines), 'applied' => []];
        }

        $products = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $unit = $this->unitPrices($products, $companyId, $channel);
        $applied = [];

        // 1) Precio unitario: cada linea arranca del precio que hoy tiene el articulo.
        foreach ($lines as $index => $line) {
            $product = isset($line['product_id']) ? $products->get((int) $line['product_id']) : null;

            if (! $product) {
                continue;
            }

            $listed = isset($line['unit_price']) && $line['unit_price'] !== null
                ? (int) $line['unit_price']
                : $this->basePrice($product, $channel);

            // El cajero puede decidir no aplicarle la promocion a una linea.
            $promotion = empty($line['skip_promotion']) ? ($unit[(int) $product->id] ?? null) : null;

            if ($promotion && $promotion['price'] < $listed) {
                $saved = (int) round(($listed - $promotion['price']) * (float) $line['quantity']);

                $lines[$index]['unit_price'] = $promotion['price'];
                $lines[$index]['promotion_id'] = $promotion['promotion_id'];

                $applied[] = [
                    'promotion_id' => $promotion['promotion_id'],
                    'name' => $promotion['name'],
                    'badge' => $promotion['badge'],
                    'amount' => $saved,
                ];
            } else {
                $lines[$index]['unit_price'] = $listed;
            }

            $lines[$index]['discount'] = max(0, (int) ($line['discount'] ?? 0));
        }

        // 2) Promociones que dependen de la cantidad, ya con el precio unitario resuelto.
        foreach ($this->running($companyId, $channel) as $promotion) {
            $discounts = match ($promotion->type) {
                Promotion::TYPE_N_FOR_M => $this->nForM($promotion, $lines, $products),
                Promotion::TYPE_BUNDLE => $this->bundle($promotion, $lines, $products),
                default => [],
            };

            $total = 0;

            foreach ($discounts as $index => $amount) {
                $lines[$index]['discount'] = (int) ($lines[$index]['discount'] ?? 0) + $amount;
                $total += $amount;
            }

            if ($total > 0) {
                $applied[] = [
                    'promotion_id' => $promotion->id,
                    'name' => $promotion->name,
                    'badge' => $promotion->badge(),
                    'amount' => $total,
                ];
            }
        }

        return ['lines' => array_values($lines), 'applied' => $applied];
    }

    /**
     * Precio unitario que deja una promocion sobre un articulo, o null si no le aplica.
     */
    protected function unitPriceFor(Promotion $promotion, object $product, string $channel): ?int
    {
        if (! $this->reaches($promotion, $product)) {
            return null;
        }

        return match ($promotion->type) {
            Promotion::TYPE_SPECIAL_PRICE => $this->specialPriceFor($promotion, $product),
            Promotion::TYPE_PERCENT_OFF => $this->percentPriceFor($promotion, $product, $channel),
            default => null,
        };
    }

    protected function specialPriceFor(Promotion $promotion, object $product): ?int
    {
        // El articulo por nombre propio manda; si no, el precio fijado a su articulo principal
        // vale para todos sus subarticulos.
        $target = $promotion->targets
            ->firstWhere(fn (PromotionTarget $t) => $t->target_type === PromotionTarget::TYPE_PRODUCT && $t->target_id === (int) $product->id)
            ?? $promotion->targets->firstWhere(fn (PromotionTarget $t) => $t->target_type === PromotionTarget::TYPE_FAMILY && $t->target_id === (int) ($product->product_family_id ?? 0));

        return $target?->value !== null ? max(0, (int) $target->value) : null;
    }

    protected function percentPriceFor(Promotion $promotion, object $product, string $channel): ?int
    {
        $percent = (float) ($promotion->config['percent'] ?? 0);

        if ($percent <= 0) {
            return null;
        }

        return (int) round($this->basePrice($product, $channel) * (1 - min($percent, 100) / 100));
    }

    /** Si la promocion cubre ese articulo: por nombre propio, por su articulo principal o por su categoria. */
    protected function reaches(Promotion $promotion, object $product): bool
    {
        foreach ($promotion->targets as $target) {
            if ($target->target_type === PromotionTarget::TYPE_PRODUCT && $target->target_id === (int) $product->id) {
                return true;
            }

            if ($target->target_type === PromotionTarget::TYPE_FAMILY && ! empty($product->product_family_id) && $target->target_id === (int) $product->product_family_id) {
                return true;
            }

            if ($target->target_type === PromotionTarget::TYPE_CATEGORY && $target->target_id === (int) $product->category_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lleva N paga M.
     *
     * Se cuenta **por articulo**, no sobre el total de la promocion: tres aceites dan un
     * aceite gratis, pero dos aceites y un agua no dan nada. Es como lo entiende el cliente
     * cuando ve el aviso en la estanteria, y evita que una promocion de varios articulos
     * termine regalando el mas barato por llevar uno de cada uno.
     *
     * Los subarticulos de un mismo articulo principal cuentan juntos: un aceite de 500 ml y dos
     * de 900 ml son tres aceites, y se regala la unidad mas barata. Articulos distintos siguen
     * sin juntarse.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, Product>  $products
     * @return array<int, int>
     */
    protected function nForM(Promotion $promotion, array $lines, Collection $products): array
    {
        $buy = (int) ($promotion->config['buy'] ?? 0);
        $pay = (int) ($promotion->config['pay'] ?? 0);

        if ($buy <= 0 || $pay <= 0 || $pay >= $buy) {
            return [];
        }

        // Cada articulo cuenta lo suyo: dos aceites y un agua no arman un 3x2. Para llevarse
        // uno gratis hay que llevar tres del mismo articulo. Lo que se cuenta en grupo es el
        // combo, que para eso existe.
        $byProduct = [];

        foreach ($lines as $index => $line) {
            $product = isset($line['product_id']) ? $products->get((int) $line['product_id']) : null;

            if (! $product || ! empty($line['skip_promotion']) || ! $this->reaches($promotion, $product)) {
                continue;
            }

            $whole = (int) floor((float) $line['quantity']);

            // Unidad por unidad, porque el mismo articulo puede venir en dos lineas con
            // precios distintos y lo que se regala es siempre lo mas barato. La llave es el
            // articulo principal si lo tiene: sus presentaciones o tallas cuentan juntas.
            $key = $product->product_family_id ? 'f'.$product->product_family_id : 'p'.$product->id;

            for ($i = 0; $i < $whole; $i++) {
                $byProduct[$key][] = ['index' => $index, 'price' => (int) $line['unit_price']];
            }
        }

        $discounts = [];

        foreach ($byProduct as $units) {
            $free = intdiv(count($units), $buy) * ($buy - $pay);

            if ($free <= 0) {
                continue;
            }

            usort($units, fn ($a, $b) => $a['price'] <=> $b['price']);

            foreach (array_slice($units, 0, $free) as $unit) {
                $discounts[$unit['index']] = ($discounts[$unit['index']] ?? 0) + $unit['price'];
            }
        }

        return $discounts;
    }

    /**
     * Combo: varios articulos juntos a un precio fijo.
     *
     * Se arman tantos combos completos como alcancen las cantidades del carrito, y el ahorro
     * se reparte entre las lineas que lo componen, a prorrata de lo que aporta cada una.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, Product>  $products
     * @return array<int, int>
     */
    protected function bundle(Promotion $promotion, array $lines, Collection $products): array
    {
        $price = (int) ($promotion->config['price'] ?? 0);
        $required = $promotion->targets
            ->where('target_type', PromotionTarget::TYPE_PRODUCT)
            ->mapWithKeys(fn (PromotionTarget $t) => [$t->target_id => max(1.0, (float) $t->quantity)]);

        if ($price <= 0 || $required->isEmpty()) {
            return [];
        }

        // Cuantos combos completos caben, y que linea aporta cada articulo.
        $sets = null;
        $byProduct = [];

        foreach ($required as $productId => $needed) {
            $available = 0.0;

            foreach ($lines as $index => $line) {
                if (! isset($line['product_id']) || (int) $line['product_id'] !== (int) $productId || ! empty($line['skip_promotion'])) {
                    continue;
                }

                $available += (float) $line['quantity'];
                $byProduct[$productId][] = $index;
            }

            $possible = (int) floor($available / $needed);
            $sets = $sets === null ? $possible : min($sets, $possible);
        }

        if (! $sets) {
            return [];
        }

        // Lo que costarian por separado los articulos de esos combos.
        $regular = 0;
        $shares = [];

        foreach ($required as $productId => $needed) {
            $pending = $needed * $sets;

            foreach ($byProduct[$productId] ?? [] as $index) {
                if ($pending <= 0) {
                    break;
                }

                $take = min($pending, (float) $lines[$index]['quantity']);
                $value = (int) round($take * (int) $lines[$index]['unit_price']);

                $shares[$index] = ($shares[$index] ?? 0) + $value;
                $regular += $value;
                $pending -= $take;
            }
        }

        $saving = $regular - ($price * $sets);

        if ($saving <= 0) {
            return [];
        }

        return $this->spread($saving, $shares);
    }

    /**
     * Reparte un ahorro entre lineas a prorrata, sin perder ni ganar un peso.
     *
     * @param  array<int, int>  $shares
     * @return array<int, int>
     */
    protected function spread(int $amount, array $shares): array
    {
        $total = array_sum($shares);

        if ($total <= 0) {
            return [];
        }

        $discounts = [];
        $assigned = 0;

        foreach ($shares as $index => $share) {
            $part = (int) floor($amount * $share / $total);
            $discounts[$index] = $part;
            $assigned += $part;
        }

        // El sobrante del redondeo va a la linea que mas aporta.
        if ($assigned < $amount) {
            arsort($shares);
            $discounts[array_key_first($shares)] += $amount - $assigned;
        }

        return array_filter($discounts, fn (int $value) => $value > 0);
    }
}
