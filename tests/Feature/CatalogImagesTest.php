<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductFamilyImage;
use App\Models\ProductImage;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Models\User;
use App\Services\Catalog\CatalogImages;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Varias fotos por ficha (articulo, articulo con subarticulos y servicio): cuantas lo decide la
 * empresa en Ajustes > Catalogo (una por defecto), se eligen al crear o al editar, se cambia la
 * portada y la tienda las muestra todas en el carrusel de la ficha, la portada primero.
 */
class CatalogImagesTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        // Archivos con mime declarado: la prueba corre aunque el PHP no tenga GD.
        Storage::fake('public');
        $this->admin = $this->makeUser('admin');
    }

    protected function limit(int $value): void
    {
        app(SettingsService::class)->set($this->company->id, 'catalog.max_images', $value);
    }

    /** @return list<UploadedFile> */
    protected function photos(int $count): array
    {
        return array_map(fn (int $index) => UploadedFile::fake()->create("foto-{$index}.jpg", 120, 'image/jpeg'), range(1, $count));
    }

    /** @return array<string, mixed> */
    protected function product(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'name' => 'Café molido 500 g',
            'sku' => 'CAF-001',
            'unit' => 'pack',
            'cost' => 16500,
            'price' => 22900,
            'tax_rate' => 5,
            'min_stock' => 10,
            'is_online_visible' => true,
            // Con existencia: la tienda no muestra lo publicado sin disponible.
            'initial_stocks' => [['store_id' => $this->store->id, 'quantity' => 20]],
        ], $overrides);
    }

    protected function updateProduct(Product $product, array $images)
    {
        return $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('products.update', $product), [
            '_method' => 'put',
            'category_id' => $product->category_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit' => $product->unit,
            'cost' => $product->cost,
            'price' => $product->price,
            'tax_rate' => $product->tax_rate,
            'min_stock' => $product->min_stock,
            'is_online_visible' => true,
            'images' => $images,
        ]);
    }

    /** @return array<string, mixed> */
    protected function service(array $overrides = []): array
    {
        return array_merge([
            'code' => 'SRV-ANCH',
            'name' => 'Ancheta navideña',
            'price' => 50000,
            'tax_rate' => 19,
            'pricing_mode' => Service::PRICING_BUNDLED,
            'is_active' => true,
            'is_online_visible' => true,
        ], $overrides);
    }

    public function test_por_defecto_cada_ficha_lleva_una_foto(): void
    {
        $this->assertSame(1, app(CatalogImages::class)->limit($this->company->id));

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('products.store'), $this->product(['images' => $this->photos(2)]))
            ->assertSessionHasErrors('images');

        $this->assertStringContainsString('una foto por ficha', session('errors')->first('images'));
        $this->assertSame(0, Product::query()->withoutGlobalScopes()->where('sku', 'CAF-001')->count());

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('products.store'), $this->product(['images' => $this->photos(1)]))
            ->assertSessionHasNoErrors();
    }

    public function test_la_empresa_elige_cuantas_en_ajustes(): void
    {
        $this->actingAs($this->admin)->put(route('settings.catalog.update'), ['catalog' => ['max_images' => 4]])->assertSessionHasNoErrors();
        $this->assertSame(4, app(CatalogImages::class)->limit($this->company->id));

        // Al menos una y como mucho diez.
        $this->actingAs($this->admin)->put(route('settings.catalog.update'), ['catalog' => ['max_images' => 0]])->assertSessionHasErrors('catalog.max_images');
        $this->actingAs($this->admin)->put(route('settings.catalog.update'), ['catalog' => ['max_images' => 11]])->assertSessionHasErrors('catalog.max_images');

        // Y no lo cambia cualquiera.
        $this->actingAs($this->makeUser('cajero'))->put(route('settings.catalog.update'), ['catalog' => ['max_images' => 9]])->assertForbidden();
        $this->assertSame(4, app(CatalogImages::class)->limit($this->company->id));

        $this->actingAs($this->admin)->get(route('settings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('settings', fn ($settings) => (int) $settings['catalog.max_images'] === 4)->where('editable.catalog', true));
    }

    public function test_al_editar_cuenta_lo_que_ya_tiene(): void
    {
        $this->limit(3);
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('products.store'), $this->product(['images' => $this->photos(2)]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->withoutGlobalScopes()->where('sku', 'CAF-001')->firstOrFail();

        $this->updateProduct($product, $this->photos(2))->assertSessionHasErrors('images');
        $this->assertStringContainsString('solo le caben 1', session('errors')->first('images'));

        $this->updateProduct($product, $this->photos(1))->assertSessionHasNoErrors();
        $this->assertSame(3, $product->images()->count());

        // La pantalla de editar recibe el tope y marca la portada.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())->get(route('products.edit', $product))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('imageLimit', 3)->where('images.0.is_cover', true)->where('images.1.is_cover', false));
    }

    public function test_bajar_el_tope_no_borra_fotos(): void
    {
        $this->limit(3);
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('products.store'), $this->product(['images' => $this->photos(3)]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->withoutGlobalScopes()->where('sku', 'CAF-001')->firstOrFail();
        $this->limit(1);

        // Se guarda sin fotos nuevas, se conservan las tres y no deja subir otra.
        $this->updateProduct($product, [])->assertSessionHasNoErrors();
        $this->assertSame(3, $product->images()->count());
        $this->updateProduct($product, $this->photos(1))->assertSessionHasErrors('images');

        // La tienda las sigue mostrando todas.
        $this->publishStorefront();
        $this->shopAt()->get('/tienda/producto/'.$product->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Storefront/Product')->has('product.images', 3));
    }

    public function test_cambiar_la_portada_la_pone_primera_en_el_carrusel(): void
    {
        $this->limit(3);
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('products.store'), $this->product(['images' => $this->photos(3)]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->withoutGlobalScopes()->where('sku', 'CAF-001')->firstOrFail();
        [$first, $second, $third] = $product->images()->get()->all();
        $this->assertSame($first->path, $product->image_path);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->patch(route('products.images.cover', [$product, $third]))
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame($third->path, $product->image_path);
        $this->assertSame([$third->id, $first->id, $second->id], $product->images()->pluck('id')->all());

        $this->publishStorefront();
        $this->shopAt()->get('/tienda/producto/'.$product->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('product.images.0', '/storage/'.$third->path)
                ->where('product.images.1', '/storage/'.$first->path)
                ->where('product.images.2', '/storage/'.$second->path));

        // La foto de otro articulo no se puede volver portada de este.
        $other = $this->makeProduct();
        $foreign = ProductImage::query()->create(['product_id' => $other->id, 'path' => 'products/x.jpg', 'sort_order' => 1]);
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->patch(route('products.images.cover', [$product, $foreign]))
            ->assertForbidden();
    }

    public function test_un_articulo_sin_fotos_propias_muestra_la_del_catalogo_externo(): void
    {
        $product = $this->makeProduct(['is_online_visible' => true, 'online_price' => 4500, 'image_url' => 'https://images.openfoodfacts.org/images/x.jpg']);
        $this->publishStorefront();

        $this->shopAt()->get('/tienda/producto/'.$product->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.images', ['https://images.openfoodfacts.org/images/x.jpg']));
    }

    public function test_un_articulo_con_subarticulos_respeta_el_tope_en_sus_fotos_comunes(): void
    {
        $this->seedAttributes();
        $payload = fn (array $images) => [
            'name' => 'Aceite de girasol',
            'code' => 'ACE-GIR',
            'category_id' => $this->category->id,
            'tax_rate' => 19,
            'attribute_1_id' => $this->attribute('Volumen')->id,
            'is_active' => true,
            'is_online_visible' => true,
            'images' => $images,
            'variants' => [[
                'value_1_id' => $this->value('Volumen', '500 ml')->id,
                'sku' => 'ACE-500',
                'price' => 7900,
                'cost' => 5500,
                'min_stock' => 5,
                'is_active' => true,
                'is_online_visible' => true,
            ]],
        ];

        $this->actingAs($this->admin)->post(route('products.families.store'), $payload($this->photos(2)))->assertSessionHasErrors('images');
        $this->assertSame(0, ProductFamily::query()->count());

        $this->limit(2);
        $this->actingAs($this->admin)->post(route('products.families.store'), $payload($this->photos(2)))->assertSessionHasNoErrors();

        $family = ProductFamily::query()->sole();
        [$first, $second] = $family->images()->get()->all();

        $this->actingAs($this->admin)->patch(route('products.families.images.cover', [$family, $second]))->assertSessionHasNoErrors();
        $this->assertSame([$second->id, $first->id], $family->images()->pluck('id')->all());

        $this->actingAs($this->admin)->get(route('products.families.edit', $family))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('imageLimit', 2)->where('images.0.id', $second->id)->where('images.0.is_cover', true));

        // Llena: una mas no cabe.
        $this->actingAs($this->admin)->put(route('products.families.update', $family), array_merge($payload($this->photos(1)), [
            'variants' => [array_merge($payload([])['variants'][0], ['id' => $family->variants()->value('id')])],
        ]))->assertSessionHasErrors('images');
        $this->assertSame(2, ProductFamilyImage::query()->where('product_family_id', $family->id)->count());
    }

    public function test_un_servicio_tiene_galeria_y_portada(): void
    {
        $this->limit(3);

        $this->actingAs($this->admin)->post(route('services.store'), $this->service(['images' => $this->photos(4)]))->assertSessionHasErrors('images');
        $this->assertSame(0, Service::query()->withoutGlobalScopes()->count());

        $this->actingAs($this->admin)->post(route('services.store'), $this->service(['images' => $this->photos(2)]))->assertSessionHasNoErrors();

        $service = Service::query()->withoutGlobalScopes()->sole();
        [$first, $second] = $service->images()->get()->all();
        $this->assertSame($first->path, $service->image_path);

        // La `image` suelta de antes sigue sirviendo y cuenta como una mas.
        $edit = array_merge($this->service(), ['image' => UploadedFile::fake()->create('suelta.jpg', 120, 'image/jpeg'), '_method' => 'put']);
        $this->actingAs($this->admin)->post(route('services.update', $service), $edit)->assertSessionHasNoErrors();
        $this->assertSame(3, $service->images()->count());
        $this->actingAs($this->admin)->post(route('services.update', $service), $edit)->assertSessionHasErrors('images');

        $this->actingAs($this->admin)->patch(route('services.images.cover', [$service, $second]))->assertSessionHasNoErrors();
        $this->assertSame($second->path, $service->fresh()->image_path);

        // Borrar la portada asciende a la siguiente.
        $this->actingAs($this->admin)->delete(route('services.images.destroy', [$service, $second]))->assertSessionHasNoErrors();
        $service->refresh();
        $this->assertSame($first->path, $service->image_path);
        $this->assertSame(2, ServiceImage::query()->where('service_id', $service->id)->count());
        Storage::disk('public')->assertMissing($second->path);

        $this->publishStorefront();
        $this->shopAt()->get('/tienda/servicio/'.$service->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Storefront/Service')->has('service.images', 2)->where('service.images.0', '/storage/'.$first->path));
    }

    public function test_la_migracion_le_deja_a_cada_empresa_lo_que_ya_usa(): void
    {
        $product = $this->makeProduct();
        foreach (range(1, 4) as $index) {
            ProductImage::query()->create(['product_id' => $product->id, 'path' => "products/{$index}.jpg", 'sort_order' => $index]);
        }

        $other = $this->makeOtherCompany()['company'];

        $migration = require database_path('migrations/2026_10_07_000300_create_service_images_table.php');
        (fn () => $this->keepCurrentUsage())->call($migration);

        // La que ya montaba cuatro queda en cuatro; la que no tenia fotos sigue con una.
        $this->assertSame(4, app(CatalogImages::class)->limit($this->company->id));
        $this->assertSame(1, app(CatalogImages::class)->limit($other->id));
        $this->assertSame(0, DB::table('settings')->where('company_id', $other->id)->where('key', 'catalog.max_images')->count());
    }
}
