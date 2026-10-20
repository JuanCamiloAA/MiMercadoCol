<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Storefront;
use App\Services\Membership\EntitlementService;
use Illuminate\Validation\ValidationException;

/**
 * Cupones de la tienda.
 *
 * Unico sitio que decide si un cupon sirve y cuanto rebaja. Lo usan el carrito para mostrar el
 * descuento y el checkout para cobrarlo, de modo que lo que el cliente ve antes de confirmar es
 * lo que termina pagando.
 */
class CouponService
{
    /**
     * Busca el cupon y devuelve lo que rebaja, o explica por que no sirve.
     *
     * @param  list<array{product_id: int, line_total: int, category_id?: int|null}>  $lines
     * @return array{coupon: Coupon, discount: int, free_delivery: bool}
     */
    public function apply(
        string $code,
        Storefront $storefront,
        array $lines,
        int $subtotal,
        int $deliveryFee,
        ?Customer $customer = null,
    ): array {
        // Sin cupones en el plan (o quitados por excepcion) no se aplica ninguno.
        if (! app(EntitlementService::class)->forCompanyId((int) $storefront->company_id)?->allowsNode('coupons.*')) {
            throw ValidationException::withMessages(['coupon' => ['Esta tienda no tiene cupones activos.']]);
        }

        $coupon = Coupon::query()
            ->withoutGlobalScopes()
            ->where('company_id', $storefront->company_id)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($code))])
            ->first();

        if (! $coupon) {
            throw ValidationException::withMessages(['coupon' => ['Ese cupón no existe.']]);
        }

        $this->assertUsable($coupon, $storefront, $subtotal, $customer);

        $base = $this->eligibleAmount($coupon, $lines, $subtotal);

        if ($base <= 0 && $coupon->type !== Coupon::TYPE_FREE_DELIVERY) {
            throw ValidationException::withMessages([
                'coupon' => ['Ese cupón no aplica a lo que llevas en el carrito.'],
            ]);
        }

        $discount = match ($coupon->type) {
            Coupon::TYPE_PERCENT => (int) round($base * min(100, $coupon->value) / 100),
            Coupon::TYPE_AMOUNT => min($coupon->value, $base),
            Coupon::TYPE_FREE_DELIVERY => 0,
            default => 0,
        };

        if ($coupon->max_discount !== null && $coupon->max_discount > 0) {
            $discount = min($discount, $coupon->max_discount);
        }

        return [
            'coupon' => $coupon,
            'discount' => max(0, $discount),
            'free_delivery' => $coupon->type === Coupon::TYPE_FREE_DELIVERY && $deliveryFee > 0,
        ];
    }

    /**
     * Deja constancia de que se uso, para poder contar los topes.
     */
    public function redeem(Coupon $coupon, Order $order, int $discount, ?Customer $customer = null): CouponRedemption
    {
        return CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'customer_id' => $customer?->id ?? $order->customer_id,
            'discount_amount' => $discount,
            'redeemed_at' => now(),
        ]);
    }

    protected function assertUsable(Coupon $coupon, Storefront $storefront, int $subtotal, ?Customer $customer): void
    {
        if (! $coupon->is_active) {
            throw ValidationException::withMessages(['coupon' => ['Ese cupón está apagado.']]);
        }

        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'coupon' => ['Ese cupón empieza el '.$coupon->starts_at->format('d/m/Y').'.'],
            ]);
        }

        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            throw ValidationException::withMessages(['coupon' => ['Ese cupón ya venció.']]);
        }

        if ($coupon->storefront_id !== null && $coupon->storefront_id !== $storefront->id) {
            throw ValidationException::withMessages(['coupon' => ['Ese cupón no sirve en esta sede.']]);
        }

        if ($subtotal < $coupon->min_order_total) {
            throw ValidationException::withMessages([
                'coupon' => ['Ese cupón pide un pedido de al menos '.self::money($coupon->min_order_total).'.'],
            ]);
        }

        if ($coupon->usage_limit !== null && $coupon->redemptions()->count() >= $coupon->usage_limit) {
            throw ValidationException::withMessages(['coupon' => ['Ese cupón ya se agotó.']]);
        }

        if ($customer) {
            if ($coupon->usage_limit_per_customer !== null) {
                $used = $coupon->redemptions()->where('customer_id', $customer->id)->count();

                if ($used >= $coupon->usage_limit_per_customer) {
                    throw ValidationException::withMessages(['coupon' => ['Ya usaste ese cupón.']]);
                }
            }

            if ($coupon->first_order_only && Order::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->exists()) {
                throw ValidationException::withMessages(['coupon' => ['Ese cupón es solo para el primer pedido.']]);
            }
        } elseif ($coupon->first_order_only || $coupon->usage_limit_per_customer !== null) {
            throw ValidationException::withMessages(['coupon' => ['Entra a tu cuenta para usar ese cupón.']]);
        }
    }

    /**
     * Sobre cuanto se calcula el descuento, segun el alcance del cupon.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    protected function eligibleAmount(Coupon $coupon, array $lines, int $subtotal): int
    {
        if ($coupon->scope === 'all') {
            return $subtotal;
        }

        $ids = array_map('intval', $coupon->scope_ids ?? []);

        if ($ids === []) {
            return 0;
        }

        if ($coupon->scope === 'product') {
            return collect($lines)
                ->filter(fn (array $line) => in_array((int) $line['product_id'], $ids, true))
                ->sum('line_total');
        }

        // Por categoria: se resuelve la de cada articulo del carrito.
        $categories = Product::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_map(fn (array $line) => (int) $line['product_id'], $lines))
            ->pluck('category_id', 'id');

        // Un servicio trae su propia categoria; un articulo, la de su ficha.
        return collect($lines)
            ->filter(fn (array $line) => in_array((int) ($line['category_id'] ?? $categories[$line['product_id']] ?? 0), $ids, true))
            ->sum('line_total');
    }

    protected static function money(int $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }
}
