<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        // Archivos con mime declarado en vez de imagenes generadas: asi la prueba corre
        // aunque la extension GD no este habilitada en el PHP de la maquina.
        Storage::fake('public');
        // Estas pruebas suben dos fotos: la empresa monta hasta cinco (por defecto es una).
        app(SettingsService::class)->set($this->company->id, 'catalog.max_images', 5);
    }

    public function test_al_crear_un_articulo_la_primera_imagen_queda_de_portada(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('products.store'), [
                'category_id' => $this->category->id,
                'name' => 'Cafe molido 500 g',
                'sku' => 'CAF-001',
                'unit' => 'pack',
                'cost' => 16500,
                'price' => 22900,
                'tax_rate' => 5,
                'min_stock' => 10,
                'images' => [
                    UploadedFile::fake()->create('portada.jpg', 120, 'image/jpeg'),
                    UploadedFile::fake()->create('segunda.jpg', 120, 'image/jpeg'),
                ],
            ])
            ->assertSessionHasNoErrors();

        $product = Product::query()->withoutGlobalScopes()->where('sku', 'CAF-001')->firstOrFail();

        $this->assertCount(2, $product->images);
        $this->assertNotNull($product->image_path);
        $this->assertSame($product->images->first()->path, $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_borrar_la_portada_asciende_a_la_siguiente_imagen(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct();

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('products.update', $product), [
                '_method' => 'put',
                'category_id' => $product->category_id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit,
                'cost' => $product->cost,
                'price' => $product->price,
                'tax_rate' => $product->tax_rate,
                'min_stock' => $product->min_stock,
                'images' => [
                    UploadedFile::fake()->create('uno.jpg', 120, 'image/jpeg'),
                    UploadedFile::fake()->create('dos.jpg', 120, 'image/jpeg'),
                ],
            ])
            ->assertSessionHasNoErrors();

        $product->refresh()->load('images');
        $cover = $product->images->first();
        $second = $product->images->last();

        $this->assertSame($cover->path, $product->image_path);

        $this->actingAs($admin)
            ->delete(route('products.images.destroy', [$product, $cover]))
            ->assertSessionHasNoErrors();

        $this->assertSame($second->path, $product->fresh()->image_path);
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
        Storage::disk('public')->assertMissing($cover->path);
    }

    public function test_rechaza_un_archivo_que_no_es_imagen(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('products.store'), [
                'category_id' => $this->category->id,
                'name' => 'Panela 500 g',
                'sku' => 'PAN-001',
                'unit' => 'unit',
                'cost' => 2600,
                'price' => 3900,
                'tax_rate' => 0,
                'min_stock' => 10,
                'images' => [UploadedFile::fake()->create('lista.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHasErrors('images.0');
    }
}
