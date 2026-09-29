<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServices;
use Tests\TestCase;

/**
 * Los articulos que consume un servicio: de la empresa, activos, sin repetir y enteros si no se
 * venden por peso. Y un articulo que usa un servicio activo no se borra.
 */
class ServiceComponentsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServices;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    public function test_los_componentes_son_de_la_empresa_y_activos(): void
    {
        $inactivo = $this->makeProduct(['name' => 'Inactivo', 'is_active' => false]);
        $other = $this->makeOtherCompany();
        $category = Category::query()->withoutGlobalScopes()->create(['company_id' => $other['company']->id, 'name' => 'Otra', 'slug' => 'otra', 'is_active' => true]);
        $ajeno = Product::query()->withoutGlobalScopes()->create([
            'company_id' => $other['company']->id, 'category_id' => $category->id, 'name' => 'Ajeno', 'sku' => 'AJ-1',
            'unit' => 'unit', 'cost' => 100, 'price' => 200, 'tax_rate' => 0, 'is_active' => true,
        ]);

        $this->store([['product_id' => $inactivo->id, 'quantity' => 1]])->assertSessionHasErrors('components.0.product_id');
        $this->store([['product_id' => $ajeno->id, 'quantity' => 1]])->assertSessionHasErrors('components.0.product_id');
    }

    public function test_un_articulo_no_se_repite(): void
    {
        $leche = $this->makeProduct(['name' => 'Leche']);

        $this->store([
            ['product_id' => $leche->id, 'quantity' => 1],
            ['product_id' => $leche->id, 'quantity' => 2],
        ])->assertSessionHasErrors('components.1.product_id');
    }

    public function test_cantidad_entera_si_no_se_vende_por_peso(): void
    {
        $leche = $this->makeProduct(['name' => 'Leche']);
        $queso = $this->makeProduct(['name' => 'Queso', 'unit' => 'kg', 'sold_by_weight' => true]);

        $this->store([['product_id' => $leche->id, 'quantity' => 1.5]])->assertSessionHasErrors('components.0.quantity');
        $this->store([['product_id' => $queso->id, 'quantity' => 0.25]])->assertSessionHasNoErrors();
        $this->store([['product_id' => $queso->id, 'quantity' => 0]], 'SRV-2')->assertSessionHasErrors('components.0.quantity');
    }

    public function test_borrar_un_articulo_que_usa_un_servicio_se_bloquea(): void
    {
        $vino = $this->makeProduct(['name' => 'Vino']);
        $this->makeService('Armado de ancheta', 30000, [[$vino, 1]]);
        $this->makeService('Ancheta ejecutiva', 50000, [[$vino, 2]]);

        $response = $this->actingAs($this->admin)->withSession($this->withActiveStore())->delete(route('products.destroy', $vino));

        $response->assertSessionHas('error', 'Lo usan 2 servicios: Ancheta ejecutiva, Armado de ancheta. Quítalo de ellos primero.');
        $this->assertNotSoftDeleted('products', ['id' => $vino->id]);
    }

    public function test_un_componente_inactivo_deja_el_servicio_sin_poder_cobrarse(): void
    {
        $vino = $this->makeProduct(['name' => 'Vino']);
        $service = $this->makeService('Ancheta', 30000, [[$vino, 1]]);

        $this->assertTrue($service->isSellable());

        $vino->update(['is_active' => false]);

        $this->assertFalse($service->refresh()->isSellable());
        $this->assertSame(['Vino (inactivo)'], $service->blockedComponents());
        $this->assertSame(0, Service::query()->withoutGlobalScopes()->whereKey($service->id)->sellable()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    protected function store(array $components, string $code = 'SRV-1')
    {
        return $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('services.store'), [
            'code' => $code, 'name' => 'Servicio', 'price' => 10000, 'tax_rate' => 0, 'pricing_mode' => 'bundled', 'is_active' => true,
            'components' => $components,
        ]);
    }
}
