<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Services\WarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El inventario llevado por bodega.
 *
 * La sede sigue siendo la unidad de negocio; la bodega es donde esta la mercancia. Lo que se
 * cuida aqui es que cada canal descuente del estante correcto: la caja no puede cobrar lo que
 * esta en el deposito de atras, y la tienda no puede ofrecerlo.
 */
class WarehouseTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        // La tienda en linea atiende la sede que el cliente eligio.
        $this->shopAt();

        $this->admin = $this->makeUser('admin');
    }

    public function test_toda_sede_nace_con_su_bodega_principal(): void
    {
        $norte = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Norte', 'code' => 'NOR',
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00',
            'suggested_cash_base' => 0, 'is_active' => true,
        ]);

        $warehouses = Warehouse::query()->withoutGlobalScopes()->where('store_id', $norte->id)->get();

        // Una sede sin bodega revienta la caja en el primer cobro: por eso la crea el modelo.
        $this->assertCount(1, $warehouses);
        $this->assertSame('PRINCIPAL', $warehouses->first()->code);
        $this->assertTrue($warehouses->first()->is_pos_source);
        $this->assertTrue($warehouses->first()->is_online_source);
        $this->assertTrue($warehouses->first()->is_receiving_default);
    }

    public function test_la_venta_de_caja_descuenta_de_la_bodega_de_caja(): void
    {
        $piso = $this->warehouse();
        $deposito = $this->makeWarehouse();

        $arroz = $this->makeProduct(['price' => 5000, 'tax_rate' => 0], stock: 10);
        $this->putStock($arroz->id, $deposito, 200);

        app(SaleService::class)->create([
            'items' => [['product_id' => $arroz->id, 'quantity' => 4]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 20000, 'received_amount' => 20000]],
        ], $this->admin, $this->store);

        // Lo que esta atras no se vende sin ir por ello: la caja solo toca el piso.
        $this->assertEqualsWithDelta(6, $this->stockIn($arroz->id, $piso), 0.001);
        $this->assertEqualsWithDelta(200, $this->stockIn($arroz->id, $deposito), 0.001);
    }

    public function test_la_devolucion_vuelve_a_la_bodega_de_donde_salio(): void
    {
        $piso = $this->warehouse();
        $deposito = $this->makeWarehouse();

        $arroz = $this->makeProduct(['price' => 5000, 'tax_rate' => 0], stock: 10);
        $this->putStock($arroz->id, $deposito, 0);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $arroz->id, 'quantity' => 4]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 20000, 'received_amount' => 20000]],
        ], $this->admin, $this->store);

        // Entre la venta y la anulacion, la caja pasa a atender desde el deposito.
        app(WarehouseService::class)->assignFlags($deposito, [Warehouse::FLAG_POS]);

        app(SaleService::class)->cancel($sale->refresh(), $this->admin, 'Se arrepintio');

        // Devolver a la bodega nueva sumaria mercancia en un estante donde nunca estuvo.
        $this->assertEqualsWithDelta(10, $this->stockIn($arroz->id, $piso), 0.001);
        $this->assertEqualsWithDelta(0, $this->stockIn($arroz->id, $deposito), 0.001);
    }

    public function test_el_pedido_se_despacha_de_donde_reservo(): void
    {
        $piso = $this->warehouse();
        $deposito = $this->makeWarehouse();

        $arroz = $this->makeProduct(['price' => 5000, 'online_price' => 5000, 'tax_rate' => 0, 'is_online_visible' => true], stock: 30);
        $this->putStock($arroz->id, $deposito, 30);

        $customer = $this->makeCustomer();

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $arroz->id, 'quantity' => 5]],
            'delivery_address' => 'Calle 30 # 65-12',
        ], $this->store, $this->admin);

        $this->assertSame($piso->id, (int) $order->warehouse_id);
        $this->assertEqualsWithDelta(5, $this->reservedIn($arroz->id, $piso), 0.001);

        // La empresa cambia de opinion y pasa la tienda al deposito.
        app(WarehouseService::class)->assignFlags($deposito, [Warehouse::FLAG_ONLINE]);

        app(OrderService::class)->changeStatus($order->refresh(), Order::STATUS_PICKING, $this->admin);
        app(OrderService::class)->changeStatus($order->refresh(), Order::STATUS_READY, $this->admin);
        app(OrderService::class)->changeStatus($order->refresh(), Order::STATUS_ON_ROUTE, $this->admin);
        app(OrderService::class)->changeStatus($order->refresh(), Order::STATUS_DELIVERED, $this->admin);

        // Lo apartado estaba en el piso; se despacha de ahi aunque hoy la tienda sea el deposito.
        $this->assertEqualsWithDelta(25, $this->stockIn($arroz->id, $piso), 0.001);
        $this->assertEqualsWithDelta(30, $this->stockIn($arroz->id, $deposito), 0.001);
        $this->assertEqualsWithDelta(0, $this->reservedIn($arroz->id, $piso), 0.001);
    }

    public function test_la_tienda_no_ofrece_lo_que_esta_en_almacenaje(): void
    {
        $deposito = $this->makeWarehouse();

        $solo_atras = $this->makeProduct([
            'name' => 'Panela solo en bodega', 'is_online_visible' => true, 'online_price' => 4000,
        ], stock: 0);
        $this->putStock($solo_atras->id, $deposito, 500);

        $this->get(route('storefront.home'))->assertOk();
        $this->post(route('storefront.cart.store'), ['product_id' => $solo_atras->id, 'quantity' => 1]);

        $cart = Cart::query()->withoutGlobalScopes()->firstOrFail();
        $summary = app(CartService::class)->summary($cart, $this->store);

        // El carrito mide contra la bodega que respalda la tienda: los 500 de atras no cuentan,
        // porque el alistador no los va a encontrar en el estante que despacha.
        $this->assertSame(0.0, (float) $summary['items'][0]['available']);
    }

    public function test_bajo_minimo_se_mide_contra_la_sede_entera(): void
    {
        $deposito = $this->makeWarehouse();

        $arroz = $this->makeProduct(['name' => 'Arroz', 'min_stock' => 20], stock: 2);
        $this->putStock($arroz->id, $deposito, 300);

        $response = $this->actingAs($this->admin)->withSession($this->withActiveStore())->get(route('inventory.index'));

        // Dos en el piso y trescientos atras no es una compra, es una reposicion.
        $response->assertInertia(fn ($page) => $page->where('metrics.low', 0)->where('metrics.items', 1));
    }

    public function test_un_traslado_no_mueve_el_costo_promedio(): void
    {
        $piso = $this->warehouse();
        $deposito = $this->makeWarehouse();

        $arroz = $this->makeProduct(['cost' => 3600], stock: 50);
        $this->putStock($arroz->id, $deposito, 0);

        $this->moveNow($arroz->id, $piso, $deposito, 20);

        // El costo es del articulo, no del estante: moverlo de sitio no lo cambia.
        $this->assertSame(3600, $arroz->fresh()->cost);
        $this->assertEqualsWithDelta(30, $this->stockIn($arroz->id, $piso), 0.001);
        $this->assertEqualsWithDelta(20, $this->stockIn($arroz->id, $deposito), 0.001);

        $movements = StockMovement::query()->withoutGlobalScopes()
            ->whereIn('type', [StockMovement::TYPE_TRANSFER_OUT, StockMovement::TYPE_TRANSFER_IN])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame($piso->id, (int) $movements[0]->warehouse_id);
        $this->assertSame($deposito->id, (int) $movements[1]->warehouse_id);
    }

    public function test_un_traslado_conserva_el_vencimiento_del_lote(): void
    {
        $piso = $this->warehouse();
        $deposito = $this->makeWarehouse();

        $leche = $this->makeProduct(['name' => 'Leche', 'track_lots' => true], stock: 0);
        $this->putStock($leche->id, $deposito, 0);

        $vence = now()->addDays(12)->toDateString();
        app(StockService::class)->receiveLot($leche, $piso, 30, 'L-777', $vence, 2500);

        // El saldo del piso se pone a mano: `receiveLot` solo crea el lote.
        ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $leche->id)->where('warehouse_id', $piso->id)
            ->update(['quantity' => 30]);

        $this->moveNow($leche->id, $piso, $deposito, 10);

        $llegado = \App\Models\ProductLot::query()->withoutGlobalScopes()
            ->where('product_id', $leche->id)
            ->where('warehouse_id', $deposito->id)
            ->firstOrFail();

        // Mover una caja de estante no le borra la fecha de vencimiento.
        $this->assertSame('L-777', $llegado->code);
        $this->assertSame($vence, $llegado->expires_at->toDateString());
        $this->assertEqualsWithDelta(10, $llegado->quantity, 0.001);
    }

    public function test_encender_una_bandera_la_apaga_en_la_otra(): void
    {
        $piso = $this->warehouse();
        $deposito = $this->makeWarehouse();

        app(WarehouseService::class)->assignFlags($deposito, [Warehouse::FLAG_POS, Warehouse::FLAG_RECEIVING]);

        $piso->refresh();
        $deposito->refresh();

        $this->assertTrue($deposito->is_pos_source);
        $this->assertFalse($piso->is_pos_source);
        $this->assertTrue($deposito->is_receiving_default);
        $this->assertFalse($piso->is_receiving_default);
        // La tienda no se toco: cada bandera se mueve sola.
        $this->assertTrue($piso->is_online_source);
        $this->assertFalse($deposito->is_online_source);
    }

    public function test_una_bodega_con_bandera_no_se_desactiva(): void
    {
        $piso = $this->warehouse();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(WarehouseService::class)->deactivate($piso);
    }

    public function test_la_venta_se_niega_si_solo_hay_existencia_atras(): void
    {
        $deposito = $this->makeWarehouse();

        $arroz = $this->makeProduct(['price' => 5000, 'tax_rate' => 0], stock: 0);
        $this->putStock($arroz->id, $deposito, 100);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(SaleService::class)->create([
            'items' => [['product_id' => $arroz->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 5000, 'received_amount' => 5000]],
        ], $this->admin, $this->store);
    }

    public function test_se_crea_una_bodega_desde_la_sede(): void
    {
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('stores.warehouses.store', $this->store), [
                'name' => 'Bodega de almacenaje',
                'code' => 'ALMACEN',
                'type' => Warehouse::TYPE_STORAGE,
                'flags' => [Warehouse::FLAG_RECEIVING],
            ])
            ->assertSessionHasNoErrors();

        $nueva = Warehouse::query()->withoutGlobalScopes()->where('code', 'ALMACEN')->firstOrFail();

        $this->assertTrue($nueva->is_receiving_default);
        // La bandera se movio: la principal ya no recibe.
        $this->assertFalse($this->warehouse()->fresh()->is_receiving_default);
        // Y lo que no se marco no se encendio solo.
        $this->assertFalse($nueva->is_pos_source);
    }

    public function test_el_codigo_de_bodega_no_se_repite_en_la_sede(): void
    {
        $this->makeWarehouse('Almacenaje', ['code' => 'ALMACEN']);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->from(route('stores.warehouses.index', $this->store))
            ->post(route('stores.warehouses.store', $this->store), [
                'name' => 'Otra',
                'code' => 'ALMACEN',
                'type' => Warehouse::TYPE_STORAGE,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_sin_permiso_no_se_administran_bodegas(): void
    {
        $cajero = $this->makeUser('cajero');

        $this->actingAs($cajero)->withSession($this->withActiveStore())
            ->get(route('stores.warehouses.index', $this->store))
            ->assertForbidden();

        $this->actingAs($cajero)->withSession($this->withActiveStore())
            ->post(route('stores.warehouses.store', $this->store), [
                'name' => 'Pirata', 'code' => 'PIRATA', 'type' => Warehouse::TYPE_STORAGE,
            ])
            ->assertForbidden();

        $this->assertSame(0, Warehouse::query()->withoutGlobalScopes()->where('code', 'PIRATA')->count());
    }

    public function test_una_bodega_de_otra_sede_no_se_edita_desde_esta(): void
    {
        $norte = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Norte', 'code' => 'NOR',
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00',
            'suggested_cash_base' => 0, 'is_active' => true,
        ]);

        $ajena = Warehouse::query()->withoutGlobalScopes()->where('store_id', $norte->id)->firstOrFail();

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->put(route('stores.warehouses.update', [$this->store, $ajena]), [
                'name' => 'Secuestrada', 'code' => 'SEC', 'type' => Warehouse::TYPE_STORAGE,
            ])
            ->assertNotFound();

        $this->assertSame('Bodega principal', $ajena->fresh()->name);
    }

    public function test_el_pos_dice_cuanto_hay_en_las_otras_bodegas(): void
    {
        $deposito = $this->makeWarehouse();

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 3);
        $this->putStock($arroz->id, $deposito, 120);

        $response = $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->getJson(route('pos.products', ['search' => 'Arroz']));

        $response->assertOk();

        $row = collect($response->json('products'))->firstWhere('id', $arroz->id);

        // Lo de atras no se puede cobrar, pero el cajero tiene que saber que existe.
        $this->assertEqualsWithDelta(3, $row['stock'], 0.001);
        $this->assertEqualsWithDelta(120, $row['other_stock'], 0.001);
    }

    /**
     * Un traslado de un renglon dentro de la misma sede, despachado y recibido de una.
     */
    protected function moveNow(int $productId, Warehouse $from, Warehouse $to, float $quantity): StockTransfer
    {
        $transfer = app(StockTransferService::class)->create(
            $this->company->id,
            $from,
            $to,
            [['product_id' => $productId, 'quantity' => $quantity]],
            $this->admin,
            'Reposicion',
        );

        return app(StockTransferService::class)->dispatchAndReceive($transfer, $this->admin);
    }

    public function test_el_panel_dice_en_que_bodega_esta_cada_unidad(): void
    {
        $deposito = $this->makeWarehouse();

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 12);
        $this->putStock($arroz->id, $deposito, 88);

        $response = $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->getJson(route('inventory.stock', $arroz));

        $response->assertOk()->assertJsonPath('total', 100);

        $rows = collect($response->json('rows'));

        $this->assertEqualsWithDelta(12, $rows->firstWhere('warehouse_id', $this->warehouse()->id)['quantity'], 0.001);
        $this->assertEqualsWithDelta(88, $rows->firstWhere('warehouse_id', $deposito->id)['quantity'], 0.001);
    }

    public function test_sin_permiso_solo_se_ven_las_sedes_propias(): void
    {
        $norte = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Norte', 'code' => 'NOR',
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00',
            'suggested_cash_base' => 0, 'is_active' => true,
        ]);

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 10);
        $this->putStock($arroz->id, app(WarehouseService::class)->receivingDefaultFor($norte->id), 500);

        // Ve existencias, pero le quitaron el permiso de mirar otras sedes.
        $bodeguero = $this->makeUser('bodega');
        $bodeguero->revokePermissionTo('inventory.index.view_other_stores');

        $response = $this->actingAs($bodeguero->refresh())->withSession($this->withActiveStore())
            ->getJson(route('inventory.stock', $arroz));

        $response->assertOk()->assertJsonPath('sees_all', false);

        $stores = collect($response->json('rows'))->pluck('store_id')->unique();

        $this->assertSame([$this->store->id], $stores->values()->all());
    }

    protected function putStock(int $productId, Warehouse $warehouse, float $quantity): void
    {
        ProductStock::query()->withoutGlobalScopes()->updateOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouse->id],
            [
                'company_id' => $this->company->id,
                'store_id' => $warehouse->store_id,
                'quantity' => $quantity,
                'reserved_quantity' => 0,
            ]
        );
    }

    protected function stockIn(int $productId, Warehouse $warehouse): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity');
    }

    protected function reservedIn(int $productId, Warehouse $warehouse): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouse->id)
            ->value('reserved_quantity');
    }
}
