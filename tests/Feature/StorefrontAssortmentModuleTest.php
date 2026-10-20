<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Store;
use App\Models\StorefrontSuggestionDismissal;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * «Tienda en linea» en el panel: el estado real de cada articulo frente a la tienda de la sede,
 * los indicadores, las vistas, los filtros, el apartado sin stock y las sugerencias.
 */
class StorefrontAssortmentModuleTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    public function test_las_pantallas_piden_el_permiso_del_surtido(): void
    {
        $routes = ['storefront.catalog.index', 'storefront.catalog.out_of_stock', 'storefront.catalog.suggestions', 'storefront.catalog.export'];

        foreach ($routes as $name) {
            $this->panel()->get(route($name))->assertOk();
        }

        $this->admin->revokePermissionTo('storefront.catalog.manage');

        foreach ($routes as $name) {
            $this->panel()->get(route($name))->assertForbidden();
        }
    }

    public function test_los_indicadores_cuadran(): void
    {
        $this->makeProduct(['name' => 'Visible', 'is_online_visible' => true], 10);
        $this->makeProduct(['name' => 'Publicado sin stock', 'is_online_visible' => true], 0);
        $this->makeProduct(['name' => 'Publicado sin precio', 'is_online_visible' => true, 'price' => 0], 10);
        $this->makeProduct(['name' => 'Con stock sin publicar', 'is_online_visible' => false], 10);
        $this->makeProduct(['name' => 'Sin stock ni publicar', 'is_online_visible' => false], 0);

        $metrics = $this->props()['metrics'];

        $this->assertSame(1, $metrics['visible']);
        $this->assertSame(1, $metrics['out_of_stock']);
        $this->assertSame(3, $metrics['published']);
        $this->assertSame(5, $metrics['active']);
        // Entre los que tienen precio, lo publicado es visible o esta oculto por stock.
        $this->assertSame($metrics['published'] - 1, $metrics['visible'] + $metrics['out_of_stock']);
        $this->assertSame($metrics['active'] - $metrics['published'], $metrics['stock_unpublished'] + 1);
    }

    public function test_con_dos_bodegas_cada_articulo_cuenta_una_vez(): void
    {
        $product = $this->makeProduct(['name' => 'Arroz', 'is_online_visible' => true], 10);
        app(StockService::class)->move($product, $this->makeWarehouse(), StockMovement::TYPE_ADJUSTMENT, 30);

        $props = $this->props();

        $this->assertSame(1, $props['rows']['total']);
        $this->assertSame(1, $props['metrics']['active']);
        // Disponible es el de la bodega de la tienda; la otra se dice aparte.
        $this->assertEquals(10, $props['rows']['data'][0]['available']);
        $this->assertEquals(30, $props['rows']['data'][0]['other_available']);
    }

    public function test_el_selector_de_sede_cambia_existencias_y_ventas_y_no_deja_pedir_otra_empresa(): void
    {
        $north = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Norte', 'code' => 'NOR',
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'suggested_cash_base' => 0, 'is_active' => true,
        ]);
        $this->admin->stores()->sync([$this->store->id, $north->id]);

        $product = $this->makeProduct(['name' => 'Panela'], 4);
        $northWarehouse = Warehouse::query()->withoutGlobalScopes()->where('store_id', $north->id)->firstOrFail();
        app(StockService::class)->move($product, $northWarehouse, StockMovement::TYPE_ADJUSTMENT, 25);
        $this->sell($product, 7, store: $north);

        $here = $this->props()['rows']['data'][0];
        $there = $this->props(['store' => $north->id])['rows']['data'][0];

        $this->assertEquals(4, $here['available']);
        $this->assertEquals(0, $here['sold_pos_30d']);
        $this->assertEquals(25, $there['available']);
        $this->assertEquals(7, $there['sold_pos_30d']);

        $other = $this->makeOtherCompany();
        $this->panel()->get(route('storefront.catalog.index', ['store' => $other['store']->id]))->assertNotFound();
    }

    public function test_ventas_de_caja_de_treinta_dias(): void
    {
        $product = $this->makeProduct(['name' => 'Leche'], 50);

        $line = $this->sell($product, 10);
        $this->sell($product, 4, status: Sale::STATUS_CANCELLED);
        $this->sell($product, 6, soldAt: now()->subDays(31));
        // Una devolucion resta.
        $this->sell($product, -3, status: Sale::STATUS_REFUNDED, item: ['refunded_from_sale_item_id' => $line->id]);
        // Un articulo dentro de un servicio cerrado va a $0 y no es «vendido».
        $parent = $this->sell(null, 1);
        $this->sell($product, 2, item: ['parent_sale_item_id' => $parent->id, 'line_total' => 0, 'unit_price' => 0], sale: $parent->sale_id);
        $this->sell($product, 5, channel: Sale::CHANNEL_ONLINE);

        $row = $this->props()['rows']['data'][0];

        $this->assertEquals(7, $row['sold_pos_30d']);
        $this->assertEquals(5, $row['sold_online_30d']);
    }

    public function test_vistas_rapidas(): void
    {
        $visible = $this->makeProduct(['name' => 'Visible', 'is_online_visible' => true, 'is_featured' => true], 10);
        $this->makeProduct(['name' => 'Sin stock', 'is_online_visible' => true], 0);
        $this->makeProduct(['name' => 'Oportunidad', 'is_online_visible' => false], 10);
        $this->makeProduct(['name' => 'Bajo minimo', 'is_online_visible' => true, 'min_stock' => 20], 5);
        $this->makeProduct(['name' => 'Apagado sin nada', 'is_online_visible' => false], 0);
        DB::table('products')->where('id', $visible->id)->update(['description' => 'Rico', 'image_url' => 'https://example.test/a.jpg', 'created_at' => now()->subDays(60)]);

        $this->assertSame(['Bajo minimo', 'Visible'], $this->names(['view' => 'visible']));
        $this->assertSame(['Sin stock'], $this->names(['view' => 'out_of_stock']));
        $this->assertSame(['Oportunidad'], $this->names(['view' => 'stock_unpublished']));
        $this->assertSame(['Apagado sin nada', 'Oportunidad'], $this->names(['view' => 'unpublished']));
        $this->assertSame(['Bajo minimo'], $this->names(['view' => 'low_stock']));
        $this->assertSame(['Visible'], $this->names(['view' => 'featured']));
        $this->assertNotContains('Visible', $this->names(['view' => 'incomplete']));
        $this->assertNotContains('Visible', $this->names(['view' => 'new']));

        // El numero de cada chip es el de su vista.
        $views = collect($this->props()['options']['quick_views'])->keyBy('value');
        $this->assertSame(1, $views['out_of_stock']['count']);
        $this->assertSame('warn', $views['out_of_stock']['tone']);
        $this->assertSame(5, $views['all']['count']);
    }

    public function test_buscar_por_nombre_sku_codigo_de_barras_y_marca(): void
    {
        $this->makeProduct(['name' => 'Cafe molido', 'sku' => 'CAF-1', 'barcode' => '0012345678905', 'brand' => 'Sello Rojo']);
        $this->makeProduct(['name' => 'Chocolate', 'sku' => 'CHO-1', 'barcode' => '7701234567891', 'brand' => 'Corona']);

        $this->assertSame(['Cafe molido'], $this->names(['search' => 'cafe']));
        $this->assertSame(['Chocolate'], $this->names(['search' => 'CHO-1']));
        $this->assertSame(['Cafe molido'], $this->names(['search' => '0012345678905']));
        $this->assertSame(['Chocolate'], $this->names(['search' => 'Corona']));
    }

    public function test_la_categoria_incluye_sus_subcategorias(): void
    {
        $child = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Granos', 'slug' => 'granos', 'parent_id' => $this->category->id, 'is_active' => true]);
        $other = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Aseo', 'slug' => 'aseo', 'is_active' => true]);
        $this->makeProduct(['name' => 'Frijol', 'category_id' => $child->id]);
        $this->makeProduct(['name' => 'Arroz']);
        $this->makeProduct(['name' => 'Jabon', 'category_id' => $other->id]);

        $this->assertSame(['Arroz', 'Frijol'], $this->names(['category' => $this->category->id]));
        $this->assertSame(['Frijol'], $this->names(['category' => $child->id]));
    }

    public function test_marca_exacta(): void
    {
        $this->makeProduct(['name' => 'Arroz Diana', 'brand' => 'Diana']);
        $this->makeProduct(['name' => 'Arroz Dianita', 'brand' => 'Dianita']);

        $this->assertSame(['Arroz Diana'], $this->names(['brand' => 'Diana']));
    }

    public function test_proveedor_por_cualquiera_de_sus_proveedores(): void
    {
        $main = Supplier::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Principal', 'is_active' => true]);
        $second = Supplier::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Alterno', 'is_active' => true]);
        $shared = $this->makeProduct(['name' => 'Aceite', 'supplier_id' => $main->id]);
        $this->makeProduct(['name' => 'Sal', 'supplier_id' => $main->id]);
        DB::table('product_suppliers')->insert(['company_id' => $this->company->id, 'product_id' => $shared->id, 'supplier_id' => $second->id, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['Aceite'], $this->names(['supplier' => $second->id]));
        $this->assertSame(['Aceite', 'Sal'], $this->names(['supplier' => $main->id]));
    }

    public function test_existencias_en_la_bodega_de_la_tienda(): void
    {
        $this->makeProduct(['name' => 'Lleno'], 50);
        $this->makeProduct(['name' => 'Vacio'], 0);
        $this->makeProduct(['name' => 'Escaso', 'min_stock' => 10], 3);

        $this->assertSame(['Escaso', 'Lleno'], $this->names(['stock' => 'in']));
        $this->assertSame(['Vacio'], $this->names(['stock' => 'out']));
        $this->assertSame(['Escaso'], $this->names(['stock' => 'low']));
    }

    public function test_estado_en_la_tienda(): void
    {
        $this->makeProduct(['name' => 'Visible', 'is_online_visible' => true], 5);
        $this->makeProduct(['name' => 'Oculto', 'is_online_visible' => true], 0);
        $this->makeProduct(['name' => 'Sin precio', 'is_online_visible' => true, 'price' => 0], 5);

        $this->assertSame(['Oculto'], $this->names(['status' => 'out_of_stock']));
        $this->assertSame(['Sin precio'], $this->names(['status' => 'no_price']));
        $this->assertSame(['Visible'], $this->names(['status' => 'visible']));
    }

    public function test_ficha_completa_sin_foto_y_sin_descripcion(): void
    {
        $this->makeProduct(['name' => 'Completo', 'description' => 'Todo', 'image_url' => 'https://example.test/x.jpg']);
        $gallery = $this->makeProduct(['name' => 'Con galeria', 'description' => 'Todo']);
        DB::table('product_images')->insert(['product_id' => $gallery->id, 'path' => 'productos/x.jpg', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->makeProduct(['name' => 'Sin foto', 'description' => 'Algo']);
        $this->makeProduct(['name' => 'Sin descripcion', 'image_url' => 'https://example.test/y.jpg']);

        $this->assertSame(['Completo', 'Con galeria'], $this->names(['sheet' => 'complete']));
        $this->assertSame(['Sin foto'], $this->names(['sheet' => 'no_image']));
        $this->assertSame(['Sin descripcion'], $this->names(['sheet' => 'no_description']));
    }

    public function test_precio_de_tienda_desde_y_hasta(): void
    {
        $this->makeProduct(['name' => 'Barato', 'price' => 3000]);
        $this->makeProduct(['name' => 'Medio', 'price' => 9000, 'online_price' => 7000]);
        $this->makeProduct(['name' => 'Caro', 'price' => 20000]);

        // El de la tienda: el propio si lo tiene.
        $this->assertSame(['Medio'], $this->names(['price_min' => 5000, 'price_max' => 8000]));
    }

    public function test_con_promocion_activa_en_linea(): void
    {
        $promoted = $this->makeProduct(['name' => 'En oferta']);
        $this->makeProduct(['name' => 'Precio normal']);
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Oferta', 'type' => Promotion::TYPE_PERCENT_OFF, 'config' => ['percent' => 10],
            'applies_online' => true, 'applies_pos' => false, 'is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        PromotionTarget::query()->create(['promotion_id' => $promotion->id, 'target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $promoted->id, 'quantity' => 1]);

        $this->assertSame(['En oferta'], $this->names(['promo' => 1]));
    }

    public function test_lote_que_vence_pronto_en_la_bodega_de_la_tienda(): void
    {
        $soon = $this->makeProduct(['name' => 'Yogur'], 10);
        $late = $this->makeProduct(['name' => 'Queso'], 10);
        $empty = $this->makeProduct(['name' => 'Kumis'], 10);
        $this->lot($soon, now()->addDays(3), 5);
        $this->lot($late, now()->addDays(20), 5);
        // Un lote sin saldo ya no cuenta.
        $this->lot($empty, now()->addDays(2), 0);

        $this->assertSame(['Yogur'], $this->names(['expiring_days' => 7]));
        $this->assertSame(['Queso', 'Yogur'], $this->names(['expiring_days' => 30]));
    }

    public function test_los_umbrales_salen_de_ajustes(): void
    {
        $product = $this->makeProduct(['name' => 'Queso'], 10);
        $this->lot($product, now()->addDays(20), 5);
        $this->assertSame([], $this->names(['view' => 'expiring']));

        $this->panel()->put(route('settings.catalog.update'), [
            'catalog' => ['max_images' => 1],
            'storefront_catalog' => ['expiring_days' => 30, 'overstock_days' => 90, 'overstock_min_factor' => 3, 'low_margin_percent' => 20],
        ])->assertSessionHasNoErrors();

        $props = $this->props(['view' => 'expiring']);
        $this->assertSame(30, $props['thresholds']['expiring_days']);
        $this->assertSame(20, $props['thresholds']['low_margin']);
        $this->assertSame(['Queso'], collect($props['rows']['data'])->pluck('name')->all());

        // Guardar solo las fotos no borra los umbrales.
        $this->panel()->put(route('settings.catalog.update'), ['catalog' => ['max_images' => 2]])->assertSessionHasNoErrors();
        $this->assertSame(30, $this->props()['thresholds']['expiring_days']);
    }

    public function test_sin_ventas_en_linea_precio_distinto_margen_bajo_y_otra_bodega(): void
    {
        $sold = $this->makeProduct(['name' => 'Vendido en linea', 'is_online_visible' => true]);
        $this->makeProduct(['name' => 'Quieto en linea', 'is_online_visible' => true]);
        $this->sell($sold, 2, channel: Sale::CHANNEL_ONLINE);
        $this->assertSame(['Quieto en linea'], $this->names(['no_online_sales' => 1]));

        DB::table('products')->update(['is_online_visible' => false]);
        $this->makeProduct(['name' => 'Precio distinto', 'price' => 5000, 'online_price' => 5500]);
        $this->assertSame(['Precio distinto'], $this->names(['price_differs' => 1]));

        // Base sin IVA 10.000; costo 9.000: margen 10 %.
        $this->makeProduct(['name' => 'Margen flaco', 'price' => 11900, 'tax_rate' => 19, 'cost' => 9000]);
        $this->assertSame(['Margen flaco'], $this->names(['margin_below' => 15]));

        $backed = $this->makeProduct(['name' => 'Con respaldo'], 0);
        app(StockService::class)->move($backed, $this->makeWarehouse(), StockMovement::TYPE_ADJUSTMENT, 9);
        $this->assertSame(['Con respaldo'], $this->names(['other_warehouse' => 1]));
    }

    public function test_ordenar_por_lo_mas_vendido_en_caja(): void
    {
        $few = $this->makeProduct(['name' => 'Poco']);
        $many = $this->makeProduct(['name' => 'Mucho']);
        $this->sell($few, 1);
        $this->sell($many, 9);

        $this->assertSame(['Mucho', 'Poco'], $this->names(['sort' => 'sold_pos_desc'], sorted: false));
    }

    public function test_reposicion_hacia_la_bodega_de_la_tienda(): void
    {
        $purchased = $this->makeProduct(['name' => 'Con compra', 'is_online_visible' => true], 0);
        $elsewhere = $this->makeProduct(['name' => 'Compra a otra bodega', 'is_online_visible' => true], 0);
        $moving = $this->makeProduct(['name' => 'Con traslado', 'is_online_visible' => true], 0);
        $drafted = $this->makeProduct(['name' => 'Traslado sin despachar', 'is_online_visible' => true], 0);
        $storage = $this->makeWarehouse();

        $this->purchase($purchased, $this->warehouse(), PurchaseOrder::STATUS_SENT);
        $this->purchase($elsewhere, $storage, PurchaseOrder::STATUS_SENT);
        $this->transfer($moving, $storage, $this->warehouse(), StockTransfer::STATUS_IN_TRANSIT);
        $this->transfer($drafted, $storage, $this->warehouse(), StockTransfer::STATUS_DRAFT);

        $props = $this->props([], 'storefront.catalog.out_of_stock');
        $rows = collect($props['rows']['data'])->keyBy('name');

        $this->assertSame(4, $props['metrics']['hidden']);
        $this->assertSame(2, $props['metrics']['replenishing']);
        $this->assertSame(1, $props['metrics']['replenishing_purchases']);
        $this->assertSame(1, $props['metrics']['replenishing_transfers']);
        $this->assertSame($props['metrics']['hidden'], $props['metrics']['replenishing'] + $props['metrics']['transferable'] + $props['metrics']['no_replenishment']);
        $this->assertSame('purchase', $rows['Con compra']['replenishment']['kind']);
        $this->assertSame('transfer', $rows['Con traslado']['replenishment']['kind']);
        $this->assertNull($rows['Compra a otra bodega']['replenishment']);
        $this->assertNull($rows['Traslado sin despachar']['replenishment']);
        // Sin nada en camino ni en otra bodega se propone comprar, hacia la bodega de la tienda.
        $this->assertStringContainsString('warehouse='.$this->warehouse()->id, $rows['Compra a otra bodega']['purchase_href']);

        $this->assertSame(['Con compra'], collect($this->props(['replenishment' => 'purchase'], 'storefront.catalog.out_of_stock')['rows']['data'])->pluck('name')->all());
    }

    public function test_sugerencias_descartes_y_publicar(): void
    {
        $candidate = $this->makeProduct(['name' => 'Se vende en caja'], 20);
        $published = $this->makeProduct(['name' => 'Ya publicado', 'is_online_visible' => true], 20);
        $empty = $this->makeProduct(['name' => 'Sin disponible'], 0);
        $dismissed = $this->makeProduct(['name' => 'Descartado'], 20);
        // Se venden todos en caja: lo que decide son publicado, disponible y descartado.
        foreach ([$candidate, $published, $empty, $dismissed] as $product) {
            $this->sell($product, 5);
        }

        $this->panel()->post(route('storefront.catalog.suggestions.dismiss'), ['store_id' => $this->store->id, 'product_id' => $dismissed->id, 'group' => 'pos_sellers'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Se vende en caja'], $this->suggested('pos_sellers'));
        $this->assertCount(1, $this->props([], 'storefront.catalog.suggestions')['dismissed']);

        // El descarte es de la sede: en otra sigue sugerido.
        $north = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Norte', 'code' => 'NOR',
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'suggested_cash_base' => 0, 'is_active' => true,
        ]);
        $this->admin->stores()->sync([$this->store->id, $north->id]);
        app(StockService::class)->move($dismissed, Warehouse::query()->withoutGlobalScopes()->where('store_id', $north->id)->firstOrFail(), StockMovement::TYPE_ADJUSTMENT, 8);
        $this->sell($dismissed, 2, store: $north);
        $this->assertSame(['Descartado'], $this->suggested('pos_sellers', $north));

        // Restaurar lo devuelve.
        $this->panel()->delete(route('storefront.catalog.suggestions.restore'), ['store_id' => $this->store->id, 'product_id' => $dismissed->id, 'group' => 'pos_sellers'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Descartado', 'Se vende en caja'], $this->suggested('pos_sellers'));

        // Publicar borra sus descartes: si despues se despublica, vuelve a sugerirse.
        StorefrontSuggestionDismissal::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'product_id' => $candidate->id, 'group' => 'overstock', 'dismissed_at' => now()]);
        $this->panel()->post(route('storefront.catalog.update'), ['product_ids' => [$candidate->id], 'field' => 'is_online_visible', 'value' => true])->assertSessionHasNoErrors();
        $this->assertSame(0, StorefrontSuggestionDismissal::query()->withoutGlobalScopes()->where('product_id', $candidate->id)->count());

        // Un descarte de otra empresa no se puede pedir.
        $other = $this->makeOtherCompany();
        $this->panel()->post(route('storefront.catalog.suggestions.dismiss'), ['store_id' => $other['store']->id, 'product_id' => $candidate->id, 'group' => 'pos_sellers'])
            ->assertSessionHasErrors('store_id');
    }

    public function test_publicar_y_destacar_en_una_sola_peticion_y_la_forma_vieja_sigue(): void
    {
        $product = $this->makeProduct(['name' => 'Miel'], 10);

        $this->panel()->post(route('storefront.catalog.update'), ['product_ids' => [$product->id], 'fields' => ['is_online_visible' => true, 'is_featured' => true]])
            ->assertSessionHas('success');
        $product->refresh();
        $this->assertTrue($product->is_online_visible);
        $this->assertTrue($product->is_featured);

        $this->panel()->post(route('storefront.catalog.update'), ['product_ids' => [$product->id], 'field' => 'is_featured', 'value' => false])->assertSessionHas('success');
        $this->assertFalse($product->refresh()->is_featured);

        // Publicar algo sin disponible avisa que no se va a ver.
        $empty = $this->makeProduct(['name' => 'Agotado'], 0);
        $this->panel()->post(route('storefront.catalog.update'), ['product_ids' => [$empty->id], 'field' => 'is_online_visible', 'value' => true, 'store_id' => $this->store->id])
            ->assertSessionHas('warning');
    }

    public function test_exportar_respeta_los_filtros_y_los_ceros_del_codigo_de_barras(): void
    {
        $this->makeProduct(['name' => 'Cafe', 'barcode' => '0012345678905', 'is_online_visible' => true], 0);
        $this->makeProduct(['name' => 'Te', 'barcode' => '7701234567891', 'is_online_visible' => true], 10);

        $response = $this->panel()->get(route('storefront.catalog.export', ['view' => 'out_of_stock']));
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'0012345678905", $csv);
        $this->assertStringContainsString('Publicado sin stock', $csv);
        $this->assertStringNotContainsString('7701234567891', $csv);
    }

    public function test_los_saltos_a_otros_modulos_dependen_de_su_permiso(): void
    {
        $this->assertTrue($this->props()['can']['transfer_requests_create']);

        $this->admin->revokePermissionTo(['inventory.transfer_requests.create', 'purchases.index.create', 'promotions.index.create', 'products.index.edit']);
        $can = $this->props()['can'];

        $this->assertFalse($can['transfer_requests_create']);
        $this->assertFalse($can['purchases_create']);
        $this->assertFalse($can['promotions_create']);
        $this->assertFalse($can['products_edit']);
    }

    public function test_las_pantallas_de_destino_aceptan_la_precarga(): void
    {
        $product = $this->makeProduct(['name' => 'Sardinas', 'min_stock' => 12], 2);
        $storage = $this->makeWarehouse();
        $supplier = Supplier::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Distribuidora', 'is_active' => true]);

        $this->panel()->get(route('transfer_requests.create', ['to_warehouse' => $this->warehouse()->id, 'from_warehouse' => $storage->id, 'products' => [$product->id]]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('toId', $this->warehouse()->id)
                ->where('fromId', $storage->id)
                ->where('preload.0.id', $product->id));

        $this->panel()->get(route('purchases.create', ['warehouse' => $this->warehouse()->id, 'products' => [$product->id]]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('warehouse.id', $this->warehouse()->id)
                ->where('preload.0.id', $product->id));

        // La orden queda para recibirse en la bodega que se dijo.
        $this->panel()->post(route('purchases.store'), [
            'supplier_id' => $supplier->id, 'status' => PurchaseOrder::STATUS_SENT, 'warehouse_id' => $storage->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 3000]],
        ])->assertSessionHasNoErrors();
        $this->assertSame($storage->id, (int) PurchaseOrder::query()->withoutGlobalScopes()->latest('id')->value('warehouse_id'));

        $this->panel()->get(route('promotions.index', ['create' => 1, 'products' => [$product->id]]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill.targets.0.target_id', $product->id));
    }

    protected function panel(): static
    {
        return $this->actingAs($this->admin)->withSession($this->withActiveStore());
    }

    /** @return array<string, mixed> */
    protected function props(array $query = [], string $route = 'storefront.catalog.index'): array
    {
        $response = $this->panel()->get(route($route, $query));
        $response->assertOk();

        return $this->pageProps($response);
    }

    /** @return array<string, mixed> */
    protected function pageProps(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    /** @return list<string> */
    protected function names(array $query, bool $sorted = true): array
    {
        $names = collect($this->props($query)['rows']['data'])->pluck('name');

        return ($sorted ? $names->sort() : $names)->values()->all();
    }

    /** @return list<string> */
    protected function suggested(string $group, ?Store $store = null): array
    {
        $groups = collect($this->props($store ? ['store' => $store->id] : [], 'storefront.catalog.suggestions')['groups'])->keyBy('key');

        return collect($groups[$group]['items'])->pluck('name')->sort()->values()->all();
    }

    protected function sell(?Product $product, float $quantity, string $channel = Sale::CHANNEL_POS, string $status = Sale::STATUS_PAID, $soldAt = null, ?Store $store = null, array $item = [], ?int $sale = null): SaleItem
    {
        $sale ??= Sale::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => ($store ?? $this->store)->id,
            'number' => 'T-'.random_int(100000, 999999),
            'channel' => $channel,
            'status' => $status,
            'subtotal' => 0,
            'total' => 0,
            'sold_at' => $soldAt ?? now()->subHour(),
        ])->id;

        return SaleItem::query()->create(array_merge([
            'sale_id' => $sale,
            'product_id' => $product?->id,
            'description' => $product?->name ?? 'Servicio',
            'quantity' => $quantity,
            'unit_price' => 1000,
            'line_total' => (int) round(1000 * $quantity),
        ], $item));
    }

    protected function lot(Product $product, $expiresAt, float $quantity): void
    {
        ProductLot::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'product_id' => $product->id, 'store_id' => $this->store->id,
            'warehouse_id' => $this->warehouse()->id, 'code' => 'L-'.random_int(100, 999), 'expires_at' => $expiresAt,
            'quantity' => $quantity, 'unit_cost' => 1000, 'received_at' => now(),
        ]);
    }

    protected function purchase(Product $product, Warehouse $warehouse, string $status): void
    {
        $supplier = Supplier::query()->withoutGlobalScopes()->firstOrCreate(['company_id' => $this->company->id, 'name' => 'Proveedor'], ['is_active' => true]);
        $order = PurchaseOrder::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id, 'code' => 'OC-'.random_int(1000, 9999), 'status' => $status, 'expected_at' => now()->addDays(2),
        ]);
        PurchaseOrderItem::query()->create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 10, 'received_quantity' => 0, 'unit_cost' => 1000]);
    }

    protected function transfer(Product $product, Warehouse $from, Warehouse $to, string $status): void
    {
        $transfer = StockTransfer::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'number' => 'TR-'.random_int(1000, 9999),
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'status' => $status,
            'dispatched_at' => $status === StockTransfer::STATUS_IN_TRANSIT ? now() : null,
        ]);
        StockTransferItem::query()->create(['stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 6]);
    }
}
