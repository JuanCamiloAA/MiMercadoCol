<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Storefront;
use App\Services\Storefront\StorefrontRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * «Compra el look»: las prendas llegan en el orden de los puntos, con su precio vigente y, de un
 * artículo con tallas, las presentaciones para escoger. Agregar el look es la misma cantidad
 * absoluta del carrito de siempre por cada prenda, y el total lo dice el servidor.
 */
class LookBlockCartTest extends TestCase
{
    use BuildsStorefront, BuildsVariants, CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected Storefront $storefront;

    protected Product $camisa;

    protected ProductFamily $jean;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();

        $this->storefront = $this->site();
        $this->camisa = $this->makeProduct(['name' => 'Camisa lino', 'price' => 89000, 'online_price' => 89000, 'is_online_visible' => true], 10);
        $this->jean = $this->makeFamily('Jean recto', 'Talla', null, [['30', null, 120000], ['32', null, 120000]]);
    }

    public function test_el_look_trae_sus_prendas_con_las_tallas(): void
    {
        $media = $this->mediaFor($this->company->id);
        $variant = $this->jean->variants()->orderBy('id')->first();
        $this->addBlock('look', ['media_id' => $media->id, 'source' => 'manual', 'product_ids' => [$this->camisa->id, $variant->id], 'hotspots' => [['x' => 40, 'y' => 30], ['x' => 55, 'y' => 70]]]);

        $look = collect(app(StorefrontRenderer::class)->draft($this->store)['blocks'])->firstWhere('type', 'look');
        $items = $look['data']['items'];

        $this->assertSame($media->id, $look['data']['media']['id']);
        $this->assertCount(2, $items);
        $this->assertSame('p:'.$this->camisa->id, $items[0]['product']['key']);
        $this->assertSame([], $items[0]['options']);
        $this->assertSame('family', $items[1]['product']['kind']);
        $this->assertCount(2, $items[1]['options']);
        $this->assertSame(120000, $items[1]['options'][0]['price']);
    }

    public function test_agregar_el_look_suma_cada_prenda_y_el_total_lo_da_el_servidor(): void
    {
        $this->publishSite($this->storefront);
        $variant = $this->jean->variants()->orderBy('id')->first();

        $this->shopAt()->putJson(route('storefront.cart.quantity'), ['product_id' => $this->camisa->id, 'quantity' => 1])->assertOk();
        $this->shopAt()->putJson(route('storefront.cart.quantity'), ['product_id' => $variant->id, 'quantity' => 1])
            ->assertOk()
            ->assertJsonPath('cart.count', 2)
            ->assertJsonPath('cart.total', 209000);
    }
}
