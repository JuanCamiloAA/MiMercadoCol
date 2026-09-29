<?php

namespace Tests\Feature;

use App\Models\ProductLot;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Services\WarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Traslados de mercancia entre bodegas.
 *
 * Lo que se cuida es el momento intermedio: entre despachar y recibir la mercancia no esta en
 * ninguna bodega, y eso tiene que ser cierto en la base, no solo en la pantalla. Y que lo que
 * se despacho y no llego quede con nombre, en vez de desaparecer.
 */
class StockTransferTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected Warehouse $piso;

    protected Warehouse $deposito;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->piso = $this->warehouse();
        $this->deposito = $this->makeWarehouse();
    }

    public function test_dentro_de_la_sede_despachar_deja_la_mercancia_en_camino(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 100);
        $this->putStock($arroz->id, $this->deposito, 0);

        $this->as()->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->deposito->id,
            'dispatch' => true,
            'items' => [['product_id' => $arroz->id, 'quantity' => 30]],
        ])->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();

        // Aun en la misma sede, la bodega de destino cuenta lo que llego antes de que entre.
        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->status);
        $this->assertNull($transfer->received_at);
        $this->assertEqualsWithDelta(70, $this->stockIn($arroz->id, $this->piso), 0.001);
        $this->assertEqualsWithDelta(0, $this->stockIn($arroz->id, $this->deposito), 0.001);

        $this->as()->post(route('transfers.receive', $transfer), [
            'items' => [['id' => $transfer->items()->firstOrFail()->id, 'received_quantity' => 30]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(StockTransfer::STATUS_RECEIVED, $transfer->refresh()->status);
        $this->assertEqualsWithDelta(30, $this->stockIn($arroz->id, $this->deposito), 0.001);
    }

    public function test_el_traslado_directo_despacha_y_recibe_en_un_solo_acto(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 100);
        $this->putStock($arroz->id, $this->deposito, 0);

        $this->as()->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->deposito->id,
            'direct' => true,
            'items' => [['product_id' => $arroz->id, 'quantity' => 30]],
        ])->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(StockTransfer::STATUS_RECEIVED, $transfer->status);
        $this->assertNotNull($transfer->dispatched_at);
        $this->assertNotNull($transfer->received_at);
        $this->assertEqualsWithDelta(70, $this->stockIn($arroz->id, $this->piso), 0.001);
        $this->assertEqualsWithDelta(30, $this->stockIn($arroz->id, $this->deposito), 0.001);
    }

    public function test_un_traslado_por_despachar_se_puede_hacer_directo_desde_la_ficha(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 100);

        $this->as()->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->deposito->id,
            'items' => [['product_id' => $arroz->id, 'quantity' => 12]],
        ])->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();

        $this->as()->post(route('transfers.direct', $transfer))->assertSessionHasNoErrors();

        $this->assertSame(StockTransfer::STATUS_RECEIVED, $transfer->refresh()->status);
        $this->assertEqualsWithDelta(12, $this->stockIn($arroz->id, $this->deposito), 0.001);
    }

    public function test_sin_el_permiso_de_traslado_directo_no_se_salta_la_recepcion(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 100);

        $bodeguero = $this->makeUser('bodega');
        $bodeguero->revokePermissionTo('inventory.transfers.direct');
        $this->assertFalse($bodeguero->refresh()->can('inventory.transfers.direct'));

        $this->as($bodeguero)->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->deposito->id,
            'direct' => true,
            'items' => [['product_id' => $arroz->id, 'quantity' => 10]],
        ])->assertForbidden();

        $this->assertSame(0, StockTransfer::query()->withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(100, $this->stockIn($arroz->id, $this->piso), 0.001);
    }

    public function test_entre_sedes_no_hay_traslado_directo(): void
    {
        [, $bodegaNorte] = $this->otherStore();

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 100);

        // En el medio hay un carro y alguien del otro lado que tiene que contar lo que llego.
        $this->as()->from(route('transfers.create'))->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $bodegaNorte->id,
            'direct' => true,
            'items' => [['product_id' => $arroz->id, 'quantity' => 10]],
        ])->assertSessionHasErrors('status');

        $this->assertSame(0, StockTransfer::query()->withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(100, $this->stockIn($arroz->id, $this->piso), 0.001);
    }

    public function test_entre_sedes_va_en_dos_pasos_y_en_el_medio_no_esta_en_ninguna_parte(): void
    {
        [$norte, $bodegaNorte] = $this->otherStore();

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 100);
        $this->putStock($arroz->id, $bodegaNorte, 0);

        $this->as()->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $bodegaNorte->id,
            'dispatch' => true,
            'items' => [['product_id' => $arroz->id, 'quantity' => 40]],
        ])->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->status);
        // Salio de aqui y todavia no llego alla: eso es exactamente lo que pasa en el camion.
        $this->assertEqualsWithDelta(60, $this->stockIn($arroz->id, $this->piso), 0.001);
        $this->assertEqualsWithDelta(0, $this->stockIn($arroz->id, $bodegaNorte), 0.001);

        $this->as($norte)->post(route('transfers.receive', $transfer), [
            'items' => [['id' => $transfer->items()->firstOrFail()->id, 'received_quantity' => 40]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(StockTransfer::STATUS_RECEIVED, $transfer->refresh()->status);
        $this->assertEqualsWithDelta(40, $this->stockIn($arroz->id, $bodegaNorte), 0.001);
    }

    public function test_lo_que_no_llego_queda_como_averia_con_el_numero_del_traslado(): void
    {
        [$norte, $bodegaNorte] = $this->otherStore();

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 100);
        $this->putStock($arroz->id, $bodegaNorte, 0);

        $transfer = $this->dispatchTo($bodegaNorte, $arroz->id, 40);

        // Salieron 40 y llegaron 36: cuatro se perdieron en el camino.
        $this->as($norte)->post(route('transfers.receive', $transfer), [
            'items' => [['id' => $transfer->items()->firstOrFail()->id, 'received_quantity' => 36]],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(36, $this->stockIn($arroz->id, $bodegaNorte), 0.001);
        $this->assertEqualsWithDelta(4, $transfer->items()->firstOrFail()->missingQuantity(), 0.001);

        $waste = StockMovement::query()->withoutGlobalScopes()
            ->where('type', StockMovement::TYPE_WASTE)
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', $transfer->id)
            ->firstOrFail();

        // Sin esta baja, las cuatro unidades se perderian sin salir en ningun informe de merma.
        $this->assertEqualsWithDelta(-4, $waste->quantity, 0.001);
        $this->assertStringContainsString($transfer->number, (string) $waste->note);
    }

    public function test_no_se_despacha_mas_de_lo_disponible(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 10);
        $this->putStock($arroz->id, $this->deposito, 0);

        $this->as()->from(route('transfers.create'))->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->deposito->id,
            'dispatch' => true,
            'items' => [['product_id' => $arroz->id, 'quantity' => 25]],
        ])->assertSessionHasErrors('items');

        // Ni siquiera quedo el traslado por despachar: o sale entero, o no pasa nada.
        $this->assertSame(0, StockTransfer::query()->withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(10, $this->stockIn($arroz->id, $this->piso), 0.001);
    }

    public function test_un_borrador_si_admite_mas_de_lo_que_hay(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 10);

        // Entre armarlo y despacharlo puede entrar mercancia: el borrador no prejuzga.
        $this->as()->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->deposito->id,
            'items' => [['product_id' => $arroz->id, 'quantity' => 25]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(StockTransfer::STATUS_DRAFT, StockTransfer::query()->withoutGlobalScopes()->firstOrFail()->status);
        $this->assertEqualsWithDelta(10, $this->stockIn($arroz->id, $this->piso), 0.001);
    }

    public function test_lo_apartado_para_un_pedido_no_se_traslada(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 20);

        ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $arroz->id)->where('warehouse_id', $this->piso->id)
            ->update(['reserved_quantity' => 15]);

        $this->as()->from(route('transfers.create'))->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->deposito->id,
            'dispatch' => true,
            // Hay 20, pero 15 tienen dueno: mandarlas dejaria ese pedido sin con que despacharse.
            'items' => [['product_id' => $arroz->id, 'quantity' => 8]],
        ])->assertSessionHasErrors('items');
    }

    public function test_los_lotes_llegan_con_su_vencimiento(): void
    {
        [$norte, $bodegaNorte] = $this->otherStore();

        $leche = $this->makeProduct(['name' => 'Leche', 'track_lots' => true], stock: 0);
        $vence = now()->addDays(9)->toDateString();

        app(StockService::class)->receiveLot($leche, $this->piso, 50, 'L-900', $vence, 2500);
        ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $leche->id)->where('warehouse_id', $this->piso->id)
            ->update(['quantity' => 50]);

        $transfer = $this->dispatchTo($bodegaNorte, $leche->id, 20);

        $this->as($norte)->post(route('transfers.receive', $transfer), [
            'items' => [['id' => $transfer->items()->firstOrFail()->id, 'received_quantity' => 20]],
        ])->assertSessionHasNoErrors();

        $llegado = ProductLot::query()->withoutGlobalScopes()
            ->where('product_id', $leche->id)
            ->where('warehouse_id', $bodegaNorte->id)
            ->firstOrFail();

        // Mover una caja de bodega no le borra la fecha: la alerta de «por vencer» la sigue viendo.
        $this->assertSame('L-900', $llegado->code);
        $this->assertSame($vence, $llegado->expires_at->toDateString());
        $this->assertEqualsWithDelta(20, $llegado->quantity, 0.001);
    }

    public function test_un_traslado_en_camino_no_se_anula(): void
    {
        [, $bodegaNorte] = $this->otherStore();

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 50);
        $transfer = $this->dispatchTo($bodegaNorte, $arroz->id, 20);

        $this->as()->from(route('transfers.show', $transfer))
            ->post(route('transfers.cancel', $transfer))
            ->assertSessionHasErrors('status');

        // Anularlo dejaria 20 unidades fuera de las dos bodegas y sin documento que lo explique.
        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->refresh()->status);
    }

    public function test_un_borrador_si_se_anula_y_no_mueve_nada(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 50);

        $this->as()->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->deposito->id,
            'items' => [['product_id' => $arroz->id, 'quantity' => 20]],
        ]);

        $transfer = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();

        $this->as()->post(route('transfers.cancel', $transfer))->assertSessionHasNoErrors();

        $this->assertSame(StockTransfer::STATUS_CANCELLED, $transfer->refresh()->status);
        $this->assertEqualsWithDelta(50, $this->stockIn($arroz->id, $this->piso), 0.001);
    }

    public function test_sin_permiso_de_despachar_no_se_despacha(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 50);

        $this->as()->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->deposito->id,
            'items' => [['product_id' => $arroz->id, 'quantity' => 20]],
        ]);

        $transfer = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();
        $cajero = $this->makeUser('cajero');

        $this->actingAs($cajero)->withSession($this->withActiveStore())
            ->post(route('transfers.dispatch', $transfer))
            ->assertForbidden();

        $this->assertSame(StockTransfer::STATUS_DRAFT, $transfer->refresh()->status);
    }

    public function test_sin_acceso_a_la_sede_de_destino_no_se_recibe(): void
    {
        [, $bodegaNorte] = $this->otherStore();

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 50);
        $transfer = $this->dispatchTo($bodegaNorte, $arroz->id, 20);

        // El bodeguero de esta sede tiene el permiso, pero no llega a la sede del otro lado.
        $bodeguero = $this->makeUser('bodega');

        $this->actingAs($bodeguero)->withSession($this->withActiveStore())
            ->from(route('transfers.show', $transfer))
            ->post(route('transfers.receive', $transfer), [
                'items' => [['id' => $transfer->items()->firstOrFail()->id, 'received_quantity' => 20]],
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->refresh()->status);
        $this->assertEqualsWithDelta(0, $this->stockIn($arroz->id, $bodegaNorte), 0.001);
    }

    public function test_el_origen_y_el_destino_no_pueden_ser_la_misma_bodega(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 50);

        $this->as()->from(route('transfers.create'))->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $this->piso->id,
            'items' => [['product_id' => $arroz->id, 'quantity' => 5]],
        ])->assertSessionHasErrors('from_warehouse_id');
    }

    public function test_la_reposicion_propone_subir_lo_que_falta_en_el_piso(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz', 'min_stock' => 20], stock: 3);
        $this->putStock($arroz->id, $this->deposito, 200);

        $surtido = $this->makeProduct(['name' => 'Panela', 'min_stock' => 5], stock: 90);
        $this->putStock($surtido->id, $this->deposito, 50);

        $this->as()->post(route('transfers.replenish'))->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(StockTransfer::STATUS_DRAFT, $transfer->status);
        $this->assertSame($this->deposito->id, (int) $transfer->from_warehouse_id);
        $this->assertSame($this->piso->id, (int) $transfer->to_warehouse_id);

        // Solo el que esta bajo minimo en el piso: lo surtido no se toca.
        $this->assertCount(1, $transfer->items);
        $this->assertSame($arroz->id, (int) $transfer->items->first()->product_id);
        // Tres en el estante y minimo veinte: se propone llevarlo al doble del minimo.
        $this->assertEqualsWithDelta(37, $transfer->items->first()->quantity, 0.001);
    }

    public function test_la_reposicion_no_propone_nada_si_el_piso_esta_surtido(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz', 'min_stock' => 10], stock: 80);
        $this->putStock($arroz->id, $this->deposito, 200);

        $this->as()->post(route('transfers.replenish'))->assertSessionHasNoErrors();

        $this->assertSame(0, StockTransfer::query()->withoutGlobalScopes()->count());
    }

    public function test_la_reposicion_no_propone_lo_que_no_hay_atras(): void
    {
        // Bajo minimo en el piso y nada en el deposito: eso si es una compra, no una reposicion.
        $this->makeProduct(['name' => 'Arroz', 'min_stock' => 20], stock: 2);

        $this->as()->post(route('transfers.replenish'))->assertSessionHasNoErrors();

        $this->assertSame(0, StockTransfer::query()->withoutGlobalScopes()->count());
    }

    public function test_la_ficha_del_traslado_abre_con_lo_que_la_pantalla_necesita(): void
    {
        [$norte, $bodegaNorte] = $this->otherStore();

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 50);
        $transfer = $this->dispatchTo($bodegaNorte, $arroz->id, 20);

        // El barrido de rutas no monta un traslado, asi que la ficha se abre aqui.
        $this->as($norte)->get(route('transfers.show', $transfer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Transfers/Show')
                ->where('transfer.number', $transfer->number)
                ->where('transfer.status', StockTransfer::STATUS_IN_TRANSIT)
                ->where('transfer.is_internal', false)
                ->where('items.0.quantity', 20)
                // Quien llega a la sede de destino si puede recibir; despachar ya no aplica.
                ->where('can.receive', true)
                ->where('can.dispatch', false));

        // El de esta sede ve la ficha, pero no le pinta el boton de recibir.
        $this->as()->get(route('transfers.show', $transfer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.receive', false));
    }

    /**
     * Otra sede con su bodega principal, y un usuario que si llega a ella.
     *
     * @return array{0: User, 1: Warehouse}
     */
    protected function otherStore(): array
    {
        $norte = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Norte', 'code' => 'NOR',
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00',
            'suggested_cash_base' => 0, 'is_active' => true,
        ]);

        $warehouse = app(WarehouseService::class)->receivingDefaultFor($norte->id);

        $user = $this->makeUser('admin');
        $user->stores()->sync([$this->store->id, $norte->id]);

        return [$user->refresh(), $warehouse];
    }

    protected function dispatchTo(Warehouse $to, int $productId, float $quantity): StockTransfer
    {
        $this->as()->post(route('transfers.store'), [
            'from_warehouse_id' => $this->piso->id,
            'to_warehouse_id' => $to->id,
            'dispatch' => true,
            'items' => [['product_id' => $productId, 'quantity' => $quantity]],
        ])->assertSessionHasNoErrors();

        return StockTransfer::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    protected function as(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->admin)->withSession($this->withActiveStore());
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
}
