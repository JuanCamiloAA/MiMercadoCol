<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Service;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SettingsService;
use Illuminate\Testing\TestResponse;

/**
 * El escenario de caja con servicios: una ancheta de precio cerrado con tres articulos y un
 * arreglo de frutas detallado. Todo por la ruta de siempre, como cobra el cajero.
 */
trait SellsServicesAtPos
{
    use SellsServices;

    protected User $cashier;

    protected RegisterSession $session;

    protected Product $vino;

    protected Product $galletas;

    protected Product $chocolate;

    protected Product $fresa;

    protected Product $banano;

    protected Service $ancheta;

    protected Service $arreglo;

    protected function seedServiceScenario(): void
    {
        app(SettingsService::class)->set($this->company->id, 'pos.allow_discount_percent', 10);
        app(SettingsService::class)->set($this->company->id, 'pos.cash_rounding', 0);

        $this->cashier = $this->makeUser('cajero');
        $this->session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 100000);

        $this->vino = $this->makeProduct(['name' => 'Vino tinto', 'sku' => 'LIC-001', 'cost' => 20000, 'price' => 32000], 50);
        $this->galletas = $this->makeProduct(['name' => 'Galletas', 'sku' => 'DUL-001', 'cost' => 3000, 'price' => 5000], 50);
        $this->chocolate = $this->makeProduct(['name' => 'Chocolates', 'sku' => 'DUL-002', 'cost' => 2000, 'price' => 4000], 50);
        $this->fresa = $this->makeProduct(['name' => 'Fresa', 'sku' => 'FRU-001', 'cost' => 1800, 'price' => 3000, 'unit' => 'kg', 'sold_by_weight' => true], 50);
        $this->banano = $this->makeProduct(['name' => 'Banano', 'sku' => 'FRU-002', 'cost' => 500, 'price' => 1000], 50);

        $this->ancheta = $this->makeService('Armado de ancheta', 60000, [[$this->vino, 1], [$this->galletas, 2], [$this->chocolate, 1]], attributes: ['code' => 'SRV-ANCHETA', 'cost' => 5000]);
        $this->arreglo = $this->makeService('Arreglo de frutas', 5000, [[$this->fresa, 2, false], [$this->banano, 3, false]], Service::PRICING_ITEMIZED, ['code' => 'SRV-ARREGLO']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>|null  $payments
     */
    protected function sell(array $items, ?array $payments = null, ?User $user = null, array $extra = []): TestResponse
    {
        return $this->actingAs($user ?? $this->cashier)
            ->withSession($this->withActiveStore())
            ->post(route('pos.sales.store'), array_merge([
                'items' => $items,
                'payments' => $payments ?? [['method' => SalePayment::METHOD_CASH, 'amount' => 1000000]],
                'register_session_id' => $this->session->id,
            ], $extra));
    }

    protected function lastSale(): Sale
    {
        return Sale::query()->withoutGlobalScopes()->latest('id')->firstOrFail()->load('items');
    }

    protected function stockOf(Product $product): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse()->id)
            ->value('quantity');
    }
}
