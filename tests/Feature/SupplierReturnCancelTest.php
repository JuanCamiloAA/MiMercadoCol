<?php

namespace Tests\Feature;

use App\Models\StockMovement;
use App\Models\SupplierReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ReturnsToSupplier;
use Tests\TestCase;

/**
 * Solo el borrador se anula. Despachada, la mercancia ya salio: si vuelve, es un rechazo.
 */
class SupplierReturnCancelTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, ReturnsToSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_anular_un_borrador_no_mueve_inventario(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 10, 'cost' => 4000]]);
        $return = $this->draft([$this->lineFor($order, $arroz, 4)], $order);

        $cancelled = $this->returns()->cancel($return, $this->admin, 'Se arregló con el proveedor');

        $this->assertSame(SupplierReturn::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('Se arregló con el proveedor', $cancelled->cancel_reason);
        $this->assertSame($this->admin->id, $cancelled->cancelled_by_user_id);
        $this->assertEqualsWithDelta(10, $this->stockOf($arroz), 0.001);
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_SUPPLIER_RETURN)->count());

        // Lo que tenia ese borrador vuelve a estar disponible para devolver.
        $this->assertCount(1, $this->draft([$this->lineFor($order, $arroz, 10)], $order)->items);
    }

    public function test_una_despachada_no_se_anula(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 20);
        $return = $this->returns()->dispatch($this->draft([['product_id' => $arroz->id, 'quantity' => 4]]), $this->admin);

        try {
            $this->returns()->cancel($return, $this->admin, 'Error');
            $this->fail('Se esperaba un error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(SupplierReturn::STATUS_DISPATCHED, $return->refresh()->status);
        $this->assertEqualsWithDelta(16, $this->stockOf($arroz), 0.001);
    }
}
