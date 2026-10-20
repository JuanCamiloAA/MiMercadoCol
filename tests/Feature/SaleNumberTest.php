<?php

namespace Tests\Feature;

use App\Models\Register;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Store;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El numero de venta es unico en la empresa aunque dos sedes tengan su «Caja 1» (C1) o vendan
 * las dos en linea (MOS): el consecutivo es por prefijo, no por sede.
 */
class SaleNumberTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected Store $second;

    protected Register $secondRegister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->second = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Norte', 'code' => 'NOR',
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'suggested_cash_base' => 0, 'is_active' => true,
        ]);
        $this->secondRegister = Register::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'store_id' => $this->second->id, 'name' => 'Caja 1', 'code' => $this->register->code, 'is_active' => true,
        ]);
    }

    public function test_dos_sedes_con_la_caja_1_no_repiten_numero(): void
    {
        $cashier = $this->makeUser('cajero');
        $other = $this->makeUser('cajero');
        $other->stores()->syncWithoutDetaching([$this->second->id]);

        $main = app(RegisterSessionService::class)->open($this->register, $cashier, 100000);
        $north = app(RegisterSessionService::class)->open($this->secondRegister, $other, 100000);
        $product = $this->makeProduct(['price' => 5000]);
        $this->stockIn($product, $this->second, 20);

        $numbers = [
            $this->sell($product, $this->store, $cashier, $main)->number,
            $this->sell($product, $this->second, $other, $north)->number,
            $this->sell($product, $this->store, $cashier, $main)->number,
        ];

        $this->assertSame(['C1-000001', 'C1-000002', 'C1-000003'], $numbers);
    }

    public function test_las_ventas_sin_caja_de_dos_sedes_tampoco(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['price' => 5000]);
        $this->stockIn($product, $this->second, 20);

        $first = $this->sell($product, $this->store, $admin, null);
        $second = $this->sell($product, $this->second, $admin, null);

        $this->assertNotSame($first->number, $second->number);
        $this->assertStringStartsWith('MOS-', $second->number);
    }

    public function test_la_migracion_arranca_donde_va_cada_prefijo(): void
    {
        $cashier = $this->makeUser('cajero');
        $session = app(RegisterSessionService::class)->open($this->register, $cashier, 100000);
        $product = $this->makeProduct(['price' => 5000]);

        // Una base en uso: ya se emitio hasta el C1-000050, en cualquier sede, y nadie lleva la cuenta nueva.
        $sale = $this->sell($product, $this->store, $cashier, $session);
        $sale->forceFill(['number' => 'C1-000050'])->save();
        Sale::query()->withoutGlobalScopes()->create(array_merge(
            collect($sale->getAttributes())->except(['id', 'number'])->all(),
            ['number' => 'CEN-2-000007', 'store_id' => $this->second->id],
        ));
        DB::table('document_counters')->where('key', 'like', 'sale:%')->delete();

        (require database_path('migrations/2026_10_03_000200_count_sale_numbers_per_prefix.php'))->up();

        $this->assertSame('C1-000051', $this->sell($product, $this->store, $cashier, $session)->number);
        $this->assertSame(7, (int) DB::table('document_counters')->where('key', 'sale:CEN-2')->value('last_number'));
    }

    protected function sell($product, Store $store, $user, $session): Sale
    {
        return app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_CARD, 'amount' => 5000]],
        ], $user, $store, $session);
    }

    protected function stockIn($product, Store $store, float $quantity): void
    {
        app(\App\Services\StockService::class)->move(
            product: $product,
            warehouse: app(\App\Services\WarehouseService::class)->posSourceFor($store->id),
            type: \App\Models\StockMovement::TYPE_ADJUSTMENT,
            quantity: $quantity,
            note: 'Existencia de prueba',
        );
    }
}
