<?php

namespace Tests\Feature;

use App\Jobs\ImportCatalogJob;
use App\Models\CatalogImport;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Importacion por archivo.
 *
 * Es el otro camino del mismo modulo: cambia de donde salen las filas, no lo que se hace con
 * ellas. Por eso aqui se comprueba sobre todo que las reglas siguen siendo las mismas.
 */
class CatalogCsvImportTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
    }

    public function test_un_csv_se_importa_con_las_mismas_reglas(): void
    {
        $import = $this->runCsv(<<<'CSV'
        ean,nombre,marca,presentacion,categoria,imagen
        7702001139415,Yogurt Original,"Alpina, Alpina S.A.",1 L,dairies,https://images.openfoodfacts.org/front.jpg
        7702354001001,Café Molido,Juan Valdez,500 g,coffees,
        7702084000015,Panela Pulverizada,Doña Panela,1 kg,sweeteners,
        CSV);

        $this->assertSame(CatalogImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(3, $import->total_imported);
        $this->assertSame(3, $import->total_detected);

        $yogurt = Product::query()->withoutGlobalScopes()->where('barcode', '7702001139415')->firstOrFail();

        $this->assertSame('Yogurt Original', $yogurt->name);
        // La primera marca de la lista, igual que por la API.
        $this->assertSame('Alpina', $yogurt->brand);
        $this->assertEqualsWithDelta(1.0, $yogurt->content_amount, 0.001);
        $this->assertSame('l', $yogurt->content_unit);
        $this->assertFalse($yogurt->is_active);
        $this->assertSame(Product::SOURCE_CSV, $yogurt->source);
    }

    public function test_el_archivo_con_punto_y_coma_y_bom_tambien_se_lee(): void
    {
        $import = $this->runCsv("\u{FEFF}ean;nombre;marca\n7702001139415;Yogurt Original;Alpina\n");

        $this->assertSame(1, $import->total_imported);
        $this->assertSame('7702001139415', Product::query()->withoutGlobalScopes()->value('barcode'));
    }

    public function test_las_filas_malas_se_omiten_con_su_motivo(): void
    {
        $import = $this->runCsv(<<<'CSV'
        ean,nombre,marca
        7702001139415,Yogurt Original,Alpina
        7702001139416,Código malo,Alpina
        7702354001001,,Juan Valdez
        7702001139415,Repetido,Alpina
        CSV);

        $this->assertSame(1, $import->total_imported);
        $this->assertSame(3, $import->total_skipped);
        $this->assertEqualsCanonicalizing(
            ['invalid_ean', 'missing_name', 'duplicate'],
            $import->items()->where('result', 'skipped')->pluck('reason')->all()
        );
    }

    public function test_un_csv_no_pisa_lo_que_el_administrador_edito(): void
    {
        $product = $this->makeProduct([
            'name' => 'Yogurt de la casa',
            'barcode' => '7702001139415',
            'brand' => 'Marca propia',
            'price' => 5400,
            'is_active' => true,
        ]);

        $import = $this->runCsv("ean,nombre,marca\n7702001139415,Yogurt Original,Alpina\n");

        $product->refresh();

        $this->assertSame(1, $import->total_updated);
        $this->assertSame('Yogurt de la casa', $product->name);
        $this->assertSame('Marca propia', $product->brand);
        $this->assertSame(5400, $product->price);
        $this->assertTrue($product->is_active);
    }

    public function test_el_cero_de_la_izquierda_sobrevive_al_archivo(): void
    {
        // Con la comilla que antepone Excel para forzar el texto.
        $import = $this->runCsv("ean,nombre\n'0036000291452,Producto importado\n");

        $this->assertSame(1, $import->total_imported);
        $this->assertSame('0036000291452', Product::query()->withoutGlobalScopes()->value('barcode'));
    }

    public function test_el_archivo_se_borra_al_terminar(): void
    {
        Storage::fake('local');

        $path = 'catalog-imports/prueba.csv';
        Storage::disk('local')->put($path, "ean,nombre\n7702001139415,Yogurt Original\n");

        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_CSV,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        app()->call([new ImportCatalogJob($import->id, $this->category->id, $path), 'handle']);

        // El worker no deja archivos de clientes tirados en el disco.
        Storage::disk('local')->assertMissing($path);
    }

    public function test_si_el_archivo_desaparecio_la_importacion_falla_con_mensaje(): void
    {
        Storage::fake('local');

        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_CSV,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        $job = new ImportCatalogJob($import->id, $this->category->id, 'catalog-imports/no-existe.csv');

        try {
            app()->call([$job, 'handle']);
            $this->fail('Se esperaba que faltara el archivo.');
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }

        $import->refresh();

        $this->assertSame(CatalogImport::STATUS_FAILED, $import->status);
        $this->assertStringContainsString('ya no está disponible', $import->error_message);
    }

    protected function runCsv(string $content): CatalogImport
    {
        Storage::fake('local');

        $path = 'catalog-imports/'.uniqid().'.csv';
        Storage::disk('local')->put($path, $content);

        $import = CatalogImport::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'source' => CatalogImport::SOURCE_CSV,
            'criteria' => [],
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        app()->call([new ImportCatalogJob($import->id, $this->category->id, $path), 'handle']);

        return $import->refresh();
    }
}
