<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * El escenario de las devoluciones de venta: un cajero con permiso de devolver, una caja
 * abierta, dos articulos y un cliente. Las ventas se hacen por `SaleService`, como las cobra
 * la caja.
 */
trait ReturnsSales
{
    protected User $cashier;

    protected RegisterSession $session;

    protected Product $leche;

    protected Product $huevos;

    protected Customer $customer;

    protected function seedReturnScenario(): void
    {
        $this->cashier = $this->makeUser('cajero');
        $this->cashier->givePermissionTo('pos.sale.refund');
        $this->cashier = $this->cashier->fresh();
        $this->session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 200000);

        $this->leche = $this->makeProduct(['name' => 'Leche entera', 'sku' => 'LAC-001', 'price' => 4000, 'cost' => 3000, 'tax_rate' => 0], 50);
        $this->huevos = $this->makeProduct(['name' => 'Huevos x12', 'sku' => 'LAC-002', 'price' => 9000, 'cost' => 7000, 'tax_rate' => 0], 50);
        $this->customer = $this->makeCustomer(['name' => 'Luz Marina', 'email' => 'luz@correo.co']);
    }

    /**
     * @param  list<array{0: Product, 1: float}>  $lines
     * @param  array<string, mixed>  $extra
     */
    protected function saleOf(array $lines, array $extra = []): Sale
    {
        $total = 0;
        $items = [];

        foreach ($lines as [$product, $quantity]) {
            $items[] = ['product_id' => $product->id, 'quantity' => $quantity];
            $total += (int) round($product->price * $quantity);
        }

        return app(SaleService::class)->create(array_merge([
            'items' => $items,
            'payments' => [['method' => SalePayment::METHOD_CARD, 'amount' => $total]],
            'customer_id' => $this->customer->id,
        ], $extra), $this->cashier, $this->store, $this->session)->load('items');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    protected function returnAtPos(Sale $sale, array $items, array $extra = [], ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->cashier)
            ->withSession($this->withActiveStore())
            ->post(route('pos.sales.refund', $sale), array_merge([
                'items' => $items,
                'resolution' => 'refund',
                'method' => 'cash',
                'reason' => 'El cliente lo trajo de vuelta',
            ], $extra));
    }

    protected function lineOf(Sale $sale, Product $product): int
    {
        return (int) $sale->items->firstWhere('product_id', $product->id)->id;
    }

    protected function stockOf(Product $product): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->where('warehouse_id', $this->warehouse()->id)->value('quantity');
    }

    protected function supervisorWithPin(string $pin): User
    {
        $supervisor = $this->makeUser('supervisor', ['email' => 'sup'.$pin.'@lacanasta.co']);
        $supervisor->forceFill(['pos_pin' => Hash::make($pin)])->save();

        return $supervisor->fresh();
    }
}
