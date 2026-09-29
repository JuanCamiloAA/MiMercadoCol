<?php

namespace Tests\Feature;

use App\Jobs\ImportCatalogJob;
use App\Models\CatalogImport;
use App\Models\Category;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ImportsCatalog;
use Tests\TestCase;

/**
 * Las rutas del asistente: permisos, aislamiento por empresa y bloqueo de corridas paralelas.
 */
class CatalogImportHttpTest extends TestCase
{
    use CreatesCompanyEnvironment, ImportsCatalog, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
    }

    public function test_el_asistente_abre_con_lo_que_necesita(): void
    {
        $this->as()->get(route('catalog.import.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Catalog/Import')
                ->where('can.run', true)
                ->where('running', null)
                ->has('categories')
                ->has('attribution.label')
            );
    }

    public function test_la_previsualizacion_marca_lo_que_ya_existe(): void
    {
        $this->makeProduct(['name' => 'Yogurt ya creado', 'barcode' => '7702001139415']);
        $this->fakeCatalog();

        $response = $this->as()->postJson(route('catalog.import.preview'), [
            'country' => 'Colombia',
            'limit' => 100,
        ]);

        $response->assertOk();

        $products = collect($response->json('products'));

        $this->assertSame(3, $response->json('count'));

        // Ya esta, pero le faltan datos que la fuente si trae: se sigue ofreciendo.
        $this->assertSame('outdated', $products->firstWhere('ean', '7702001139415')['status']);
        $this->assertSame('Yogurt ya creado', $products->firstWhere('ean', '7702001139415')['current_name']);
        $this->assertSame('new', $products->firstWhere('ean', '7702084000015')['status']);
    }

    public function test_los_filtros_viajan_a_la_fuente(): void
    {
        $this->fakeCatalog();

        $this->as()->postJson(route('catalog.import.preview'), [
            'country' => 'Colombia',
            'brand' => 'Alpina',
            'category' => 'dairies',
            'search' => 'yogurt',
            'limit' => 100,
        ])->assertOk();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $query = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return ($query['countries_tags_en'] ?? null) === 'Colombia'
                // En forma de etiqueta: la fuente compara contra el tag, no contra el texto.
                && ($query['brands_tags'] ?? null) === 'alpina'
                && ($query['categories_tags_en'] ?? null) === 'dairies'
                && ($query['search_terms'] ?? null) === 'yogurt';
        });
    }

    public function test_la_marca_se_manda_como_etiqueta(): void
    {
        $this->fakeCatalog();

        $this->as()->postJson(route('catalog.import.preview'), [
            'country' => 'Colombia',
            'brand' => '  Doña Panela S.A.  ',
            'limit' => 100,
        ])->assertOk();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $query = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            // Sin acentos, en minuscula y con guiones: asi se llaman las etiquetas.
            return ($query['brands_tags'] ?? null) === 'dona-panela-s-a';
        });
    }

    public function test_lo_que_ya_esta_al_dia_se_marca_para_no_volver_a_ofrecerlo(): void
    {
        $this->fakeCatalog();

        // Uno ya importado y completo: la fuente no le aporta nada.
        $this->makeProduct([
            'name' => 'Yogurt Original',
            'barcode' => '7702001139415',
            'brand' => 'Alpina',
            'description' => 'Yogurt con dulce',
            'content_amount' => 1,
            'content_unit' => 'l',
            'image_url' => 'https://images.openfoodfacts.org/x.jpg',
            'source_ref' => '7702001139415',
        ]);

        // Otro ya importado pero al que le falta la marca: ese si vale la pena.
        $this->makeProduct([
            'name' => 'Cafe sin marca',
            'barcode' => '7702354001001',
            'brand' => null,
            'description' => null,
        ]);

        $response = $this->as()->postJson(route('catalog.import.preview'), [
            'country' => 'Colombia',
            'limit' => 100,
        ])->assertOk();

        $rows = collect($response->json('products'))->keyBy('ean');

        $this->assertSame('up_to_date', $rows['7702001139415']['status']);
        $this->assertSame([], $rows['7702001139415']['adds']);

        $this->assertSame('outdated', $rows['7702354001001']['status']);
        $this->assertContains('marca', $rows['7702354001001']['adds']);

        $this->assertSame('new', $rows['7702084000015']['status']);

        // Dos valen la pena de los tres de la pagina.
        $this->assertSame(2, $response->json('worth'));
    }

    public function test_un_articulo_eliminado_no_cuenta_como_al_dia(): void
    {
        $this->fakeCatalog();

        $product = $this->makeProduct(['name' => 'Yogurt', 'barcode' => '7702001139415']);
        $product->delete();

        $rows = collect($this->as()->postJson(route('catalog.import.preview'), [
            'country' => 'Colombia',
            'limit' => 100,
        ])->json('products'))->keyBy('ean');

        $this->assertSame('deleted', $rows['7702001139415']['status']);
    }

    public function test_la_previsualizacion_ofrece_los_segmentos_en_castellano(): void
    {
        $this->as()->get(route('catalog.import.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('segments')
                ->where('segments.0.value', 'plant-based-foods')
            );
    }

    public function test_la_previsualizacion_no_escribe_nada(): void
    {
        $this->fakeCatalog();

        $this->as()->postJson(route('catalog.import.preview'), ['country' => 'Colombia', 'limit' => 100])->assertOk();

        $this->assertSame(0, CatalogImport::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, \App\Models\Product::query()->withoutGlobalScopes()->count());
    }

    public function test_si_la_fuente_esta_caida_la_previsualizacion_lo_dice(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('boom', 500)]);

        $this->as()->postJson(route('catalog.import.preview'), ['country' => 'Colombia', 'limit' => 100])
            ->assertStatus(503)
            ->assertJsonPath('message', 'El catálogo externo respondió con un error 500.');
    }

    public function test_lanzar_una_importacion_despacha_el_job(): void
    {
        Queue::fake();
        $this->fakeCatalog();

        $this->as()->post(route('catalog.import.store'), [
            'source' => CatalogImport::SOURCE_API,
            'category_id' => $this->category->id,
            'country' => 'Colombia',
            'limit' => 100,
            'eans' => ['7702001139415'],
        ])->assertRedirect();

        $import = CatalogImport::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(CatalogImport::STATUS_PENDING, $import->status);
        $this->assertSame(['7702001139415'], $import->criteria['eans']);
        $this->assertSame($this->admin->id, $import->user_id);

        Queue::assertPushed(ImportCatalogJob::class, fn ($job) => $job->catalogImportId === $import->id);
    }

    public function test_el_codigo_seleccionado_viaja_como_texto(): void
    {
        Queue::fake();
        $this->fakeCatalog();

        $this->as()->post(route('catalog.import.store'), [
            'source' => CatalogImport::SOURCE_API,
            'category_id' => $this->category->id,
            'country' => 'Colombia',
            'limit' => 100,
            'eans' => ['0036000291452'],
        ])->assertRedirect();

        // El cero de la izquierda tiene que llegar intacto hasta la base.
        $this->assertSame(
            ['0036000291452'],
            CatalogImport::query()->withoutGlobalScopes()->firstOrFail()->criteria['eans']
        );
    }

    public function test_sin_categoria_de_destino_no_se_lanza(): void
    {
        Queue::fake();

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.store'), [
                'source' => CatalogImport::SOURCE_API,
                'country' => 'Colombia',
                'limit' => 100,
            ])
            ->assertSessionHasErrors('category_id');

        Queue::assertNothingPushed();
    }

    public function test_no_se_puede_usar_una_categoria_de_otra_empresa(): void
    {
        Queue::fake();

        $otherCategory = Category::query()->withoutGlobalScopes()->create([
            'company_id' => $this->otherCompany()->id,
            'name' => 'Ajena',
            'slug' => 'ajena',
            'is_active' => true,
        ]);

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.store'), [
                'source' => CatalogImport::SOURCE_API,
                'category_id' => $otherCategory->id,
                'country' => 'Colombia',
                'limit' => 100,
            ])
            ->assertSessionHasErrors('category_id');

        Queue::assertNothingPushed();
    }

    public function test_con_una_importacion_en_curso_no_se_lanza_otra(): void
    {
        Queue::fake();

        CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PROCESSING,
        ]);

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.store'), [
                'source' => CatalogImport::SOURCE_API,
                'category_id' => $this->category->id,
                'country' => 'Colombia',
                'limit' => 100,
            ])
            ->assertSessionHasErrors('source');

        Queue::assertNothingPushed();
        $this->assertSame(1, CatalogImport::query()->withoutGlobalScopes()->count());
    }

    public function test_el_estado_y_el_detalle_se_pueden_consultar(): void
    {
        $this->fakeCatalog();
        $import = $this->runImport($this->admin);

        $this->as()->getJson(route('catalog.import.show', $import))
            ->assertOk()
            ->assertJsonPath('import.status', CatalogImport::STATUS_COMPLETED)
            ->assertJsonPath('import.totals.imported', 3)
            ->assertJsonPath('import.progress', 100);

        $this->as()->getJson(route('catalog.import.items', $import))
            ->assertOk()
            ->assertJsonPath('counts.imported', 3)
            ->assertJsonCount(3, 'items.data');
    }

    public function test_el_detalle_se_puede_filtrar_por_resultado(): void
    {
        $this->fakeCatalog('search-dirty');
        $import = $this->runImport($this->admin);

        $this->as()->getJson(route('catalog.import.items', ['import' => $import->id, 'result' => 'skipped']))
            ->assertOk()
            ->assertJsonCount(3, 'items.data');
    }

    public function test_el_detalle_se_pagina(): void
    {
        $this->fakeCatalog();
        $import = $this->runImport($this->admin);

        $response = $this->as()->getJson(route('catalog.import.items', $import));

        $response->assertOk()
            ->assertJsonPath('items.current_page', 1)
            ->assertJsonPath('items.total', 3)
            ->assertJsonPath('items.last_page', 1);
    }

    public function test_el_exporte_trae_todo_el_detalle_no_solo_la_pagina(): void
    {
        $this->fakeCatalog('search-dirty');
        $import = $this->runImport($this->admin);

        $response = $this->as()->get(route('catalog.import.export', $import));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("
", trim($csv))));

        // Encabezado mas las cuatro filas de la corrida: nada se queda por fuera.
        $this->assertCount(5, $lines);
        $this->assertStringContainsString('Codigo de barras', $lines[0]);

        // El codigo sale como texto para que Excel no se coma el cero de la izquierda.
        $this->assertStringContainsString("'7702001139415", $csv);
    }

    public function test_el_exporte_respeta_el_filtro(): void
    {
        $this->fakeCatalog('search-dirty');
        $import = $this->runImport($this->admin);

        $csv = $this->as()->get(route('catalog.import.export', ['import' => $import->id, 'result' => 'skipped']))
            ->assertOk()
            ->streamedContent();

        $lines = array_values(array_filter(explode("
", trim($csv))));

        // Encabezado mas los tres omitidos.
        $this->assertCount(4, $lines);
        $this->assertStringNotContainsString('Importado', $csv);
    }

    public function test_no_se_exporta_el_detalle_de_otra_empresa(): void
    {
        $foreign = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->otherCompany()->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => [],
            'status' => CatalogImport::STATUS_COMPLETED,
        ]);

        $this->as()->get(route('catalog.import.export', $foreign))->assertForbidden();
    }

    public function test_cancelar_detiene_la_corrida(): void
    {
        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PROCESSING,
        ]);

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.cancel', $import))
            ->assertRedirect();

        $this->assertSame(CatalogImport::STATUS_CANCELLED, $import->refresh()->status);
        $this->assertNotNull($import->finished_at);
    }

    public function test_cancelar_una_que_ya_termino_avisa(): void
    {
        $this->fakeCatalog();
        $import = $this->runImport($this->admin);

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.cancel', $import))
            ->assertSessionHasErrors('status');
    }

    public function test_el_boton_de_importar_ahora_corre_la_corrida_sin_cola(): void
    {
        Queue::fake();
        $this->fakeCatalog();

        $this->as()->post(route('catalog.import.store'), [
            'source' => CatalogImport::SOURCE_API,
            'category_id' => $this->category->id,
            'country' => 'Colombia',
            'limit' => 100,
            'eans' => ['7702001139415', '7702354001001'],
        ])->assertRedirect();

        $import = CatalogImport::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(CatalogImport::STATUS_PENDING, $import->status);
        // La categoria de destino queda guardada para poder correrla despues.
        $this->assertSame($this->category->id, $import->criteria['category_id']);

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.run', $import))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $import->refresh();

        $this->assertSame(CatalogImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(2, $import->total_imported);
        $this->assertSame(2, \App\Models\Product::query()->withoutGlobalScopes()->where('source', 'api')->count());
    }

    public function test_una_corrida_vieja_sin_categoria_guardada_igual_se_puede_correr(): void
    {
        $this->fakeCatalog();

        // Como las que quedaron antes de guardar la categoria en los criterios.
        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => ['country' => 'Colombia', 'limit' => 10, 'eans' => []],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.run', $import))
            ->assertSessionHasNoErrors();

        $this->assertSame(CatalogImport::STATUS_COMPLETED, $import->refresh()->status);
        $this->assertSame(3, $import->total_imported);
    }

    public function test_correr_una_que_ya_termino_avisa(): void
    {
        $this->fakeCatalog();
        $import = $this->runImport($this->admin);

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.run', $import))
            ->assertSessionHasErrors('status');
    }

    public function test_no_se_corre_una_si_hay_otra_procesando(): void
    {
        $this->fakeCatalog();

        CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PROCESSING,
        ]);

        $pending = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => ['country' => 'Colombia', 'limit' => 10, 'eans' => [], 'category_id' => $this->category->id],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.run', $pending))
            ->assertSessionHasErrors('status');

        $this->assertSame(CatalogImport::STATUS_PENDING, $pending->refresh()->status);
    }

    public function test_si_la_fuente_se_cae_al_correr_a_mano_la_corrida_queda_fallida(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('boom', 500)]);

        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => ['country' => 'Colombia', 'limit' => 10, 'eans' => [], 'category_id' => $this->category->id],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        // Aqui no hay cola que reintente: se cierra en el acto y se explica.
        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.run', $import))
            ->assertSessionHasErrors('status');

        $import->refresh();

        $this->assertSame(CatalogImport::STATUS_FAILED, $import->status);
        $this->assertStringContainsString('error 500', $import->error_message);
        $this->assertNotNull($import->finished_at);
    }

    public function test_correr_una_de_otra_empresa_responde_403(): void
    {
        $otherCompany = $this->otherCompany();

        $otherAdmin = User::create([
            'company_id' => $otherCompany->id,
            'name' => 'Ana',
            'last_name' => 'Rios',
            'email' => 'ana'.random_int(100, 99999).'@otra.co',
            'password' => bcrypt('Password123*'),
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $foreign = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'user_id' => $otherAdmin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        $this->as()->post(route('catalog.import.run', $foreign))->assertForbidden();

        $this->assertSame(CatalogImport::STATUS_PENDING, $foreign->refresh()->status);
    }

    public function test_el_supervisor_no_puede_correr_una_a_mano(): void
    {
        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        $this->actingAs($this->makeUser('supervisor'))->withSession($this->withActiveStore());

        $this->post(route('catalog.import.run', $import))->assertForbidden();
    }

    public function test_sin_permiso_no_se_entra_a_ninguna_ruta(): void
    {
        $cashier = $this->makeUser('cajero');

        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        $this->actingAs($cashier)->withSession($this->withActiveStore());

        $this->get(route('catalog.import.index'))->assertForbidden();
        $this->postJson(route('catalog.import.preview'), ['country' => 'Colombia', 'limit' => 10])->assertForbidden();
        $this->post(route('catalog.import.store'), ['source' => 'openfoodfacts', 'category_id' => $this->category->id])->assertForbidden();
        $this->getJson(route('catalog.import.show', $import))->assertForbidden();
        $this->getJson(route('catalog.import.items', $import))->assertForbidden();
        $this->post(route('catalog.import.cancel', $import))->assertForbidden();
        $this->post(route('catalog.import.run', $import))->assertForbidden();
    }

    public function test_el_supervisor_mira_pero_no_lanza(): void
    {
        $supervisor = $this->makeUser('supervisor');

        $this->actingAs($supervisor)->withSession($this->withActiveStore());

        $this->get(route('catalog.import.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.run', false));

        $this->post(route('catalog.import.store'), [
            'source' => CatalogImport::SOURCE_API,
            'category_id' => $this->category->id,
            'country' => 'Colombia',
            'limit' => 10,
        ])->assertForbidden();
    }

    public function test_una_importacion_de_otra_empresa_responde_403(): void
    {
        $otherCompany = $this->otherCompany();

        $otherAdmin = User::create([
            'company_id' => $otherCompany->id,
            'name' => 'Ana',
            'last_name' => 'Rios',
            'email' => 'ana'.random_int(100, 99999).'@otra.co',
            'password' => bcrypt('Password123*'),
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $foreign = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'user_id' => $otherAdmin->id,
            'source' => CatalogImport::SOURCE_API,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PROCESSING,
        ]);

        // Existe, pero no es suya: 403, no 404.
        $this->as()->getJson(route('catalog.import.show', $foreign))->assertForbidden();
        $this->as()->getJson(route('catalog.import.items', $foreign))->assertForbidden();
        $this->as()->post(route('catalog.import.cancel', $foreign))->assertForbidden();

        $this->assertSame(CatalogImport::STATUS_PROCESSING, $foreign->refresh()->status);
    }

    public function test_un_csv_se_acepta_y_se_guarda_para_el_worker(): void
    {
        Queue::fake();
        \Illuminate\Support\Facades\Storage::fake('local');

        $csv = "ean,nombre,marca,presentacion,categoria,imagen\n7702001139415,Yogurt Original,Alpina,1 L,dairies,\n";

        $this->as()->post(route('catalog.import.store'), [
            'source' => CatalogImport::SOURCE_CSV,
            'category_id' => $this->category->id,
            'file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
        ])->assertRedirect();

        $import = CatalogImport::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(CatalogImport::SOURCE_CSV, $import->source);
        Queue::assertPushed(ImportCatalogJob::class, fn ($job) => $job->csvPath !== null);
    }

    public function test_un_archivo_que_no_es_csv_se_rechaza(): void
    {
        Queue::fake();
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->as()->from(route('catalog.import.index'))
            ->post(route('catalog.import.store'), [
                'source' => CatalogImport::SOURCE_CSV,
                'category_id' => $this->category->id,
                'file' => UploadedFile::fake()->create('catalogo.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');

        Queue::assertNothingPushed();
    }

    protected function otherCompany(): Company
    {
        return $this->other ??= Company::create([
            'name' => 'Otra empresa',
            'legal_name' => 'Otra S.A.S.',
            'tax_id' => '901222333-4',
            'currency' => 'COP',
            'is_active' => true,
        ]);
    }

    protected ?Company $other = null;

    /** Peticion como administrador, con la sede activa puesta. */
    protected function as(): static
    {
        return $this->actingAs($this->admin)->withSession($this->withActiveStore());
    }
}
