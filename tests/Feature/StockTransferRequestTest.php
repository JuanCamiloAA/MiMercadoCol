<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Solicitudes de traslado.
 *
 * Lo que se cuida: que pedir no mueva nada, que quien surte no mande mas de lo que falta, y que
 * lo pendiente se recalcule solo cuando un traslado generado se anula.
 */
class StockTransferRequestTest extends TestCase
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

    public function test_pedir_no_mueve_inventario(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $this->putStock($arroz->id, $this->deposito, 200);

        $this->as()->post(route('transfer_requests.store'), [
            'to_warehouse_id' => $this->piso->id,
            'from_warehouse_id' => $this->deposito->id,
            'items' => [['product_id' => $arroz->id, 'quantity' => 40]],
        ])->assertSessionHasNoErrors();

        $request = StockTransferRequest::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(StockTransferRequest::STATUS_PENDING, $request->status);
        $this->assertStringStartsWith('SOL-', $request->number);
        $this->assertSame(0, StockTransfer::query()->withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(5, $this->stockIn($arroz->id, $this->piso), 0.001);
        $this->assertEqualsWithDelta(200, $this->stockIn($arroz->id, $this->deposito), 0.001);
    }

    public function test_se_puede_atender_en_varios_traslados(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $this->putStock($arroz->id, $this->deposito, 200);

        $request = $this->requestFromDeposito($arroz->id, 40);
        $item = $request->items()->firstOrFail();

        // Hoy se manda lo que cabe en el carro; el resto, manana.
        $this->as()->post(route('transfer_requests.fulfill', $request), [
            'items' => [['id' => $item->id, 'quantity' => 25]],
        ])->assertSessionHasNoErrors();

        $request->refresh();
        $first = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(StockTransferRequest::STATUS_PARTIAL, $request->status);
        $this->assertSame($request->id, (int) $first->stock_transfer_request_id);
        $this->assertSame(StockTransfer::STATUS_DRAFT, $first->status);
        $this->assertSame($this->deposito->id, (int) $first->from_warehouse_id);
        $this->assertSame($this->piso->id, (int) $first->to_warehouse_id);
        $this->assertEqualsWithDelta(15, $item->refresh()->pendingQuantity(), 0.001);

        $this->as()->post(route('transfer_requests.fulfill', $request), [
            'items' => [['id' => $item->id, 'quantity' => 15]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(StockTransferRequest::STATUS_FULFILLED, $request->refresh()->status);
        $this->assertSame(2, $request->transfers()->count());
    }

    public function test_no_se_manda_mas_de_lo_que_falta(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $this->putStock($arroz->id, $this->deposito, 200);

        $request = $this->requestFromDeposito($arroz->id, 10);

        $this->as()->from(route('transfer_requests.show', $request))
            ->post(route('transfer_requests.fulfill', $request), [
                'items' => [['id' => $request->items()->firstOrFail()->id, 'quantity' => 12]],
            ])->assertSessionHasErrors('items');

        $this->assertSame(0, StockTransfer::query()->withoutGlobalScopes()->count());
        $this->assertSame(StockTransferRequest::STATUS_PENDING, $request->refresh()->status);
    }

    public function test_atender_y_despachar_deja_la_mercancia_en_camino_hasta_que_la_reciban(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $this->putStock($arroz->id, $this->deposito, 200);

        $request = $this->requestFromDeposito($arroz->id, 40);

        $this->as()->post(route('transfer_requests.fulfill', $request), [
            'dispatch' => true,
            'items' => [['id' => $request->items()->firstOrFail()->id, 'quantity' => 40]],
        ])->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();

        // Salio del deposito y todavia no entra al piso: falta que alguien lo reciba alla.
        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->status);
        $this->assertEqualsWithDelta(160, $this->stockIn($arroz->id, $this->deposito), 0.001);
        $this->assertEqualsWithDelta(5, $this->stockIn($arroz->id, $this->piso), 0.001);

        $this->as()->post(route('transfers.receive', $transfer), [
            'items' => [['id' => $transfer->items()->firstOrFail()->id, 'received_quantity' => 40]],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(45, $this->stockIn($arroz->id, $this->piso), 0.001);
    }

    public function test_anular_el_traslado_generado_devuelve_lo_pendiente(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $this->putStock($arroz->id, $this->deposito, 200);

        $request = $this->requestFromDeposito($arroz->id, 40);

        $this->as()->post(route('transfer_requests.fulfill', $request), [
            'items' => [['id' => $request->items()->firstOrFail()->id, 'quantity' => 40]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(StockTransferRequest::STATUS_FULFILLED, $request->refresh()->status);

        $transfer = StockTransfer::query()->withoutGlobalScopes()->firstOrFail();
        $this->as()->post(route('transfers.cancel', $transfer))->assertSessionHasNoErrors();

        // Si no salio, lo pedido sigue pedido: nadie tiene que acordarse de restarlo.
        $this->assertSame(StockTransferRequest::STATUS_PENDING, $request->refresh()->status);
        $this->assertEqualsWithDelta(40, $request->items()->firstOrFail()->pendingQuantity(), 0.001);
    }

    public function test_rechazar_pide_motivo_y_solo_aplica_si_no_se_ha_mandado_nada(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $request = $this->requestFromDeposito($arroz->id, 40);

        $this->as()->from(route('transfer_requests.show', $request))
            ->post(route('transfer_requests.reject', $request), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->as()->post(route('transfer_requests.reject', $request), ['reason' => 'No hay en el deposito'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(StockTransferRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('No hay en el deposito', $request->response_note);

        // Rechazada ya no admite traslados.
        $this->as()->from(route('transfer_requests.show', $request))
            ->post(route('transfer_requests.fulfill', $request), [
                'items' => [['id' => $request->items()->firstOrFail()->id, 'quantity' => 1]],
            ])->assertSessionHasErrors('status');
    }

    public function test_una_atendida_en_parte_se_cierra_en_vez_de_rechazarse(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $this->putStock($arroz->id, $this->deposito, 200);

        $request = $this->requestFromDeposito($arroz->id, 40);

        $this->as()->post(route('transfer_requests.fulfill', $request), [
            'items' => [['id' => $request->items()->firstOrFail()->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $this->as()->from(route('transfer_requests.show', $request))
            ->post(route('transfer_requests.reject', $request), ['reason' => 'Ya no'])
            ->assertSessionHasErrors('status');

        $this->as()->post(route('transfer_requests.close', $request), ['reason' => 'El resto se compra'])
            ->assertSessionHasNoErrors();

        $this->assertSame(StockTransferRequest::STATUS_CLOSED, $request->refresh()->status);
        // Cerrarla no toca lo que ya se genero: ese traslado sigue su curso.
        $this->assertSame(StockTransfer::STATUS_DRAFT, StockTransfer::query()->withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_quien_pidio_puede_anularla_mientras_nadie_la_atienda(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $this->putStock($arroz->id, $this->deposito, 200);

        $pendiente = $this->requestFromDeposito($arroz->id, 10);
        $this->as()->post(route('transfer_requests.cancel', $pendiente))->assertSessionHasNoErrors();
        $this->assertSame(StockTransferRequest::STATUS_CANCELLED, $pendiente->refresh()->status);

        $empezada = $this->requestFromDeposito($arroz->id, 10);
        $this->as()->post(route('transfer_requests.fulfill', $empezada), [
            'items' => [['id' => $empezada->items()->firstOrFail()->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $this->as()->from(route('transfer_requests.show', $empezada))
            ->post(route('transfer_requests.cancel', $empezada))
            ->assertSessionHasErrors('status');
    }

    public function test_sin_acceso_a_la_sede_que_surte_no_se_atiende(): void
    {
        [$norte, $bodegaNorte] = $this->otherStore();

        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $this->putStock($arroz->id, $bodegaNorte, 100);

        // El piso de esta sede le pide a la bodega del Norte.
        $this->as()->post(route('transfer_requests.store'), [
            'to_warehouse_id' => $this->piso->id,
            'from_warehouse_id' => $bodegaNorte->id,
            'items' => [['product_id' => $arroz->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $request = StockTransferRequest::query()->withoutGlobalScopes()->firstOrFail();

        // El bodeguero de aca tiene el permiso, pero la bodega que surte no es de su sede.
        $bodeguero = $this->makeUser('bodega');

        $this->as($bodeguero)->from(route('transfer_requests.show', $request))
            ->post(route('transfer_requests.fulfill', $request), [
                'items' => [['id' => $request->items()->firstOrFail()->id, 'quantity' => 10]],
            ])->assertSessionHasErrors('status');

        $this->assertSame(0, StockTransfer::query()->withoutGlobalScopes()->count());

        // Quien si llega a la sede del Norte la atiende.
        $this->as($norte)->post(route('transfer_requests.fulfill', $request), [
            'items' => [['id' => $request->items()->firstOrFail()->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(StockTransferRequest::STATUS_FULFILLED, $request->refresh()->status);
    }

    public function test_no_se_pide_para_una_bodega_de_otra_sede(): void
    {
        [, $bodegaNorte] = $this->otherStore();
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);

        $bodeguero = $this->makeUser('bodega');

        $this->as($bodeguero)->from(route('transfer_requests.create'))
            ->post(route('transfer_requests.store'), [
                'to_warehouse_id' => $bodegaNorte->id,
                'from_warehouse_id' => $this->piso->id,
                'items' => [['product_id' => $arroz->id, 'quantity' => 10]],
            ])->assertSessionHasErrors('status');

        $this->assertSame(0, StockTransferRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_la_ficha_y_el_listado_abren_con_lo_que_la_pantalla_necesita(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 5);
        $this->putStock($arroz->id, $this->deposito, 30);

        $request = $this->requestFromDeposito($arroz->id, 40);

        // El barrido de rutas no monta una solicitud, asi que la ficha se abre aqui.
        $this->as()->get(route('transfer_requests.show', $request))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('TransferRequests/Show')
                ->where('request.number', $request->number)
                ->where('request.status', StockTransferRequest::STATUS_PENDING)
                ->where('items.0.quantity', 40)
                ->where('items.0.pending', 40)
                // Quien surte ve cuanto tiene para mandar.
                ->where('items.0.origin_available', 30)
                ->where('can.fulfill', true)
                ->where('can.reject', true)
                ->where('can.close', false)
                ->where('can.cancel', true));

        $this->as()->get(route('transfer_requests.index', ['side' => 'incoming']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('TransferRequests/Index')
                ->where('requests.data.0.number', $request->number)
                ->where('counts.incoming', 1));

        $this->as()->get(route('transfer_requests.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Transfers/Form')
                ->where('mode', 'request')
                ->where('toId', $this->piso->id));
    }

    protected function requestFromDeposito(int $productId, float $quantity): StockTransferRequest
    {
        $this->as()->post(route('transfer_requests.store'), [
            'to_warehouse_id' => $this->piso->id,
            'from_warehouse_id' => $this->deposito->id,
            'items' => [['product_id' => $productId, 'quantity' => $quantity]],
        ])->assertSessionHasNoErrors();

        return StockTransferRequest::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
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
