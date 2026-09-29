<?php

namespace Tests\Feature;

use App\Models\CatalogImport;
use App\Models\CatalogImportItem;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogSourceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ImportsCatalog;
use Tests\TestCase;

/**
 * Traer articulos de una fuente externa al catalogo de la empresa.
 *
 * Lo que se cuida por encima de todo: correr la misma importacion dos veces no puede duplicar
 * nada ni deshacer lo que el comerciante edito a mano. Un modulo que le pisa el trabajo al
 * usuario es peor que no tener modulo.
 */
class CatalogImportTest extends TestCase
{
    use CreatesCompanyEnvironment, ImportsCatalog, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
    }

    public function test_importar_tres_articulos_los_deja_en_el_catalogo(): void
    {
        $this->fakeCatalog();

        $import = $this->runImport($this->admin);

        $this->assertSame(CatalogImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(3, $import->total_imported);
        $this->assertSame(0, $import->total_updated);
        $this->assertSame(3, $import->total_detected);

        $products = Product::query()->withoutGlobalScopes()->where('source', Product::SOURCE_API)->get();

        $this->assertCount(3, $products);
        $this->assertEqualsCanonicalizing(
            ['7702001139415', '7702354001001', '7702084000015'],
            $products->pluck('barcode')->all()
        );

        $yogurt = $products->firstWhere('barcode', '7702001139415');

        $this->assertSame('Yogurt Original', $yogurt->name);
        $this->assertSame('Alpina', $yogurt->brand);
        $this->assertSame('Yogurt con dulce', $yogurt->description);
        $this->assertEqualsWithDelta(1.0, $yogurt->content_amount, 0.001);
        $this->assertSame('l', $yogurt->content_unit);
        $this->assertSame($this->category->id, $yogurt->category_id);
    }

    public function test_lo_importado_entra_apagado_y_sin_precio(): void
    {
        $this->fakeCatalog();
        $this->runImport($this->admin);

        $product = Product::query()->withoutGlobalScopes()->where('barcode', '7702001139415')->firstOrFail();

        // La fuente no trae precios. Un articulo activo en cero es un articulo que la caja
        // regala: entra apagado y lo enciende quien lo tarifa.
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->is_online_visible);
        $this->assertSame(0, $product->price);
        $this->assertSame(0, $product->cost);
    }

    public function test_los_contadores_cuadran_con_lo_detectado(): void
    {
        $this->fakeCatalog('search-dirty');

        $import = $this->runImport($this->admin);
        $totals = $import->total_imported + $import->total_updated + $import->total_skipped + $import->total_failed;

        $this->assertSame($import->total_detected, $totals);
    }

    public function test_un_codigo_invalido_un_nombre_vacio_y_un_repetido_se_omiten_con_su_motivo(): void
    {
        $this->fakeCatalog('search-dirty');

        $import = $this->runImport($this->admin);

        $this->assertSame(1, $import->total_imported);
        $this->assertSame(3, $import->total_skipped);

        $reasons = $import->items()->pluck('reason', 'result')->all();

        $this->assertEqualsCanonicalizing(
            ['invalid_ean', 'missing_name', 'duplicate'],
            $import->items()->where('result', CatalogImportItem::RESULT_SKIPPED)->pluck('reason')->all()
        );
        $this->assertArrayHasKey(CatalogImportItem::RESULT_IMPORTED, $reasons);
    }

    public function test_un_codigo_que_la_fuente_no_tiene_se_omite_sin_crear_nada(): void
    {
        // La trampa: la API responde 200 con status 0, no un 404.
        $this->fakeCatalog('search-page-1', ['7701111111116' => null]);

        $import = $this->runImport($this->admin, ['eans' => ['7701111111116']]);

        $this->assertSame(0, $import->total_imported);
        $this->assertSame(1, $import->total_skipped);
        $this->assertSame(0, Product::query()->withoutGlobalScopes()->where('source', Product::SOURCE_API)->count());

        $item = $import->items()->firstOrFail();

        $this->assertSame(CatalogImportItem::RESULT_SKIPPED, $item->result);
        $this->assertSame('not_found', $item->reason);
    }

    public function test_correr_la_misma_importacion_dos_veces_no_duplica_nada(): void
    {
        $this->fakeCatalog();

        $this->runImport($this->admin);
        $second = $this->runImport($this->admin);

        $this->assertSame(3, Product::query()->withoutGlobalScopes()->where('source', Product::SOURCE_API)->count());
        $this->assertSame(0, $second->total_imported);
        $this->assertSame(3, $second->total_updated);
    }

    public function test_reimportar_no_pisa_lo_que_el_administrador_edito(): void
    {
        $this->fakeCatalog();
        $this->runImport($this->admin);

        $otherCategory = Category::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Lácteos',
            'slug' => 'lacteos',
            'is_active' => true,
        ]);

        // El comerciante lo acomoda a su manera: lo mueve de categoria, lo renombra, le pone
        // precio y lo deja apagado a proposito.
        $product = Product::query()->withoutGlobalScopes()->where('barcode', '7702001139415')->firstOrFail();
        $product->update([
            'category_id' => $otherCategory->id,
            'name' => 'Yogurt Alpina litro',
            'brand' => 'Alpina Colombia',
            'price' => 6500,
            'cost' => 4800,
            'is_active' => false,
        ]);

        $this->runImport($this->admin);

        $product->refresh();

        $this->assertSame($otherCategory->id, $product->category_id);
        $this->assertSame('Yogurt Alpina litro', $product->name);
        $this->assertSame('Alpina Colombia', $product->brand);
        $this->assertSame(6500, $product->price);
        $this->assertSame(4800, $product->cost);
        $this->assertFalse($product->is_active);
    }

    public function test_reimportar_si_completa_lo_que_estaba_en_blanco(): void
    {
        $product = $this->makeProduct([
            'name' => 'Yogurt sin datos',
            'barcode' => '7702001139415',
            'brand' => null,
            'description' => null,
        ]);

        $this->fakeCatalog();
        $import = $this->runImport($this->admin);

        $product->refresh();

        $this->assertSame(1, $import->total_updated + $import->total_imported - 2);
        $this->assertSame('Alpina', $product->brand);
        $this->assertSame('Yogurt con dulce', $product->description);
        // El nombre es suyo y no se toca, aunque la fuente traiga otro.
        $this->assertSame('Yogurt sin datos', $product->name);
    }

    public function test_dos_empresas_pueden_tener_el_mismo_codigo_de_barras(): void
    {
        $this->fakeCatalog();
        $this->runImport($this->admin);

        $otherCompany = Company::create([
            'name' => 'Otra empresa',
            'legal_name' => 'Otra S.A.S.',
            'tax_id' => '901222333-4',
            'currency' => 'COP',
            'is_active' => true,
        ]);

        $otherCategory = Category::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Despensa',
            'slug' => 'despensa-otra',
            'is_active' => true,
        ]);

        $otherAdmin = User::create([
            'company_id' => $otherCompany->id,
            'name' => 'Ana',
            'last_name' => 'Rios',
            'email' => 'ana'.random_int(100, 9999).'@otra.co',
            'password' => bcrypt('Password123*'),
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->runImport($otherAdmin, [], $otherCategory);

        $rows = Product::query()->withoutGlobalScopes()->where('barcode', '7702001139415')->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$this->company->id, $otherCompany->id],
            $rows->pluck('company_id')->all()
        );
    }

    public function test_un_articulo_eliminado_no_resucita_solo(): void
    {
        $this->fakeCatalog();
        $this->runImport($this->admin);

        $product = Product::query()->withoutGlobalScopes()->where('barcode', '7702001139415')->firstOrFail();
        $product->delete();

        $import = $this->runImport($this->admin);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertSame('deleted_product', $import->items()->where('ean', '7702001139415')->value('reason'));
    }

    public function test_la_categoria_de_la_fuente_se_usa_cuando_coincide_por_nombre(): void
    {
        Category::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'coffees',
            'slug' => 'coffees',
            'is_active' => true,
        ]);

        $this->fakeCatalog();
        $this->runImport($this->admin);

        $coffee = Product::query()->withoutGlobalScopes()->where('barcode', '7702354001001')->firstOrFail();
        $yogurt = Product::query()->withoutGlobalScopes()->where('barcode', '7702001139415')->firstOrFail();

        $this->assertSame('coffees', $coffee->category->name);
        // El que no coincide cae en la categoria que eligio el administrador.
        $this->assertSame($this->category->id, $yogurt->category_id);
    }

    public function test_solo_se_traen_los_codigos_seleccionados(): void
    {
        $this->fakeCatalog();

        $import = $this->runImport($this->admin, ['eans' => ['7702001139415', '7702084000015']]);

        $this->assertSame(2, $import->total_imported);
        $this->assertSame(2, $import->total_detected);
        $this->assertEqualsCanonicalizing(
            ['7702001139415', '7702084000015'],
            Product::query()->withoutGlobalScopes()->where('source', Product::SOURCE_API)->pluck('barcode')->all()
        );
    }

    public function test_el_limite_corta_lo_que_se_trae(): void
    {
        $this->fakeCatalog();

        $import = $this->runImport($this->admin, ['limit' => 2]);

        $this->assertSame(2, $import->total_detected);
        $this->assertSame(2, $import->total_imported);
    }

    public function test_si_la_fuente_responde_500_la_importacion_queda_fallida(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => ['country' => 'Colombia', 'limit' => 100, 'eans' => []],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        $job = new \App\Jobs\ImportCatalogJob($import->id, $this->category->id);

        try {
            app()->call([$job, 'handle']);
            $this->fail('Se esperaba que la fuente caída hiciera fallar el job.');
        } catch (CatalogSourceException $exception) {
            // La cola reintenta; agotados los intentos llama a failed().
            $job->failed($exception);
        }

        $import->refresh();

        $this->assertSame(CatalogImport::STATUS_FAILED, $import->status);
        $this->assertNotNull($import->error_message);
        $this->assertStringContainsString('error 500', $import->error_message);
        $this->assertNotNull($import->finished_at);
    }

    public function test_si_la_fuente_no_responde_la_importacion_queda_fallida(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'));

        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => ['country' => 'Colombia', 'limit' => 100, 'eans' => []],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        $job = new \App\Jobs\ImportCatalogJob($import->id, $this->category->id);

        try {
            app()->call([$job, 'handle']);
            $this->fail('Se esperaba que el timeout hiciera fallar el job.');
        } catch (CatalogSourceException $exception) {
            $job->failed($exception);
        }

        $import->refresh();

        // Nunca se queda colgada en «procesando».
        $this->assertSame(CatalogImport::STATUS_FAILED, $import->status);
        $this->assertStringContainsString('No se pudo consultar', $import->error_message);
    }

    public function test_una_importacion_cancelada_se_detiene_y_conserva_lo_que_alcanzo(): void
    {
        $this->fakeCatalog();

        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => ['country' => 'Colombia', 'limit' => 100, 'eans' => []],
            'status' => CatalogImport::STATUS_CANCELLED,
        ]);

        app()->call([new \App\Jobs\ImportCatalogJob($import->id, $this->category->id), 'handle']);

        $import->refresh();

        $this->assertSame(CatalogImport::STATUS_CANCELLED, $import->status);
        $this->assertSame(0, Product::query()->withoutGlobalScopes()->where('source', Product::SOURCE_API)->count());
    }
}
