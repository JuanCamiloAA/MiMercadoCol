<?php

namespace Tests\Feature;

use App\Models\ProductLot;
use App\Models\PurchaseOrder;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnSettlement;
use App\Services\PaymentTermService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ReturnsToSupplier;
use Tests\TestCase;

/**
 * Lo que responde el proveedor: nota credito, reembolso o reposicion. Nunca mas de lo que debe.
 */
class SupplierReturnSettlementTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, ReturnsToSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_la_nota_credito_exige_numero(): void
    {
        $return = $this->dispatched(10, 1000);

        $this->expectException(ValidationException::class);
        $this->returns()->settle($return, ['type' => 'credit_note', 'amount' => 5000], $this->admin);
    }

    public function test_el_reembolso_exige_como_llego(): void
    {
        $return = $this->dispatched(10, 1000);

        $this->expectException(ValidationException::class);
        $this->returns()->settle($return, ['type' => 'refund', 'amount' => 5000], $this->admin);
    }

    public function test_no_se_responde_mas_de_lo_pendiente(): void
    {
        $return = $this->dispatched(10, 1000);

        try {
            $this->returns()->settle($return, ['type' => 'credit_note', 'document_number' => 'NC-1', 'amount' => 10001], $this->admin);
            $this->fail('Se esperaba un error.');
        } catch (ValidationException $exception) {
            $this->assertSame('El proveedor solo debe $ 10.000 por esta devolución.', $exception->errors()['amount'][0]);
        }
    }

    public function test_parcial_queda_pendiente_y_completo_cierra(): void
    {
        $return = $this->dispatched(10, 1000);

        $this->returns()->settle($return, ['type' => 'credit_note', 'document_number' => 'NC-1', 'amount' => 6000], $this->admin);
        $return->refresh();
        $this->assertSame(SupplierReturn::STATUS_DISPATCHED, $return->status);
        $this->assertSame(6000, $return->settled_total);
        $this->assertSame(4000, $return->pendingTotal());

        $this->returns()->settle($return, ['type' => 'refund', 'payment_method' => 'transfer', 'amount' => 4000], $this->admin);
        $return->refresh();
        $this->assertSame(SupplierReturn::STATUS_CLOSED, $return->status);
        $this->assertSame(0, $return->pendingTotal());

        // Cerrada, ya no admite mas respuestas.
        $this->expectException(ValidationException::class);
        $this->returns()->settle($return, ['type' => 'refund', 'payment_method' => 'cash', 'amount' => 1], $this->admin);
    }

    public function test_nota_credito_por_la_mitad_y_rechazo_por_el_resto_cierra(): void
    {
        $return = $this->dispatched(10, 1000);

        $this->returns()->settle($return, ['type' => 'credit_note', 'document_number' => 'NC-9', 'amount' => 5000], $this->admin);
        $closed = $this->returns()->reject($return->refresh(), [$return->items()->first()->id => 5], $this->admin);

        $this->assertSame(SupplierReturn::STATUS_CLOSED, $closed->status);
        $this->assertEqualsWithDelta(25, $this->stockOf($return->items()->first()->product), 0.001);
    }

    public function test_la_reposicion_crea_una_orden_sin_plazo_que_se_recibe_por_el_flujo_normal(): void
    {
        // El proveedor da treinta dias, pero la reposicion ya estaba pagada.
        $term = app(PaymentTermService::class)->forDays($this->company->id, 30, create: true);
        $this->granos->update(['payment_term_id' => $term->id]);

        $leche = $this->makeSuppliedProduct('Leche', $this->granos, cost: 2800, stock: 0, attributes: ['track_lots' => true]);
        $order = $this->receivedOrder([['product' => $leche, 'quantity' => 20, 'cost' => 3000, 'lot' => 'L-1']]);
        $return = $this->returns()->dispatch($this->draft([$this->lineFor($order, $leche, 6)], $order), $this->admin);
        $item = $return->items()->first();

        $replacement = $this->returns()->createReplacementOrder($return, [$item->id => 6], $this->admin);

        $this->assertSame(PurchaseOrder::SOURCE_REPLACEMENT, $replacement->source);
        $this->assertSame($return->id, $replacement->supplier_return_id);
        $this->assertSame(PurchaseOrder::STATUS_SENT, $replacement->status);
        $this->assertNull($replacement->payment_term_id);
        $this->assertSame(18000, $replacement->total);

        $settlement = $return->settlements()->firstOrFail();
        $this->assertSame(SupplierReturnSettlement::TYPE_REPLACEMENT, $settlement->type);
        $this->assertSame($replacement->id, $settlement->purchase_order_id);
        $this->assertSame(SupplierReturn::STATUS_CLOSED, $return->refresh()->status);

        // Se recibe con el boton de siempre: entra con lote y vencimiento, y sin vencimiento de pago.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('purchases.receive', $replacement), [
            'items' => [[
                'id' => $replacement->items()->firstOrFail()->id,
                'received_quantity' => 6,
                'lot_code' => 'L-REP',
                'expires_at' => now()->addDays(20)->toDateString(),
            ]],
        ])->assertSessionHasNoErrors();

        $replacement->refresh();
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $replacement->status);
        $this->assertNull($replacement->due_at);
        $this->assertEqualsWithDelta(20, $this->stockOf($leche), 0.001);
        $this->assertTrue(ProductLot::query()->withoutGlobalScopes()->where('code', 'L-REP')->exists());
    }

    public function test_no_se_repone_mas_de_lo_que_se_quedo_el_proveedor(): void
    {
        $return = $this->dispatched(10, 1000);
        $item = $return->items()->first();

        $this->returns()->createReplacementOrder($return, [$item->id => 4], $this->admin);

        $this->expectException(ValidationException::class);
        $this->returns()->createReplacementOrder($return->refresh(), [$item->id => 7], $this->admin);
    }

    public function test_anular_la_orden_de_reposicion_vuelve_a_dejar_pendiente_la_devolucion(): void
    {
        $return = $this->dispatched(10, 1000);
        $replacement = $this->returns()->createReplacementOrder($return, [$return->items()->first()->id => 10], $this->admin);
        $this->assertSame(SupplierReturn::STATUS_CLOSED, $return->refresh()->status);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('purchases.cancel', $replacement))
            ->assertSessionHasNoErrors();

        $return->refresh();
        $this->assertSame(SupplierReturn::STATUS_DISPATCHED, $return->status);
        $this->assertSame(0, $return->settled_total);
        $this->assertSame(10000, $return->pendingTotal());
    }

    protected function dispatched(float $quantity, int $unitCost): SupplierReturn
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: $unitCost, stock: 30);

        return $this->returns()->dispatch(
            $this->draft([['product_id' => $arroz->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]]),
            $this->admin
        );
    }
}
