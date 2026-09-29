<?php

namespace Tests\Feature;

use App\Imports\ProductImport;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\MasterImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Importar articulos con subarticulos desde el mismo archivo de siempre: cinco columnas
 * opcionales arman la familia, y un archivo sin ellas sigue entrando igual.
 */
class ProductImportVariantsTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
        $this->admin = $this->makeUser('admin');
    }

    public function test_las_filas_con_el_mismo_articulo_principal_forman_una_familia(): void
    {
        $result = $this->import([
            $this->row('ACE-500', 'Aceite 500', '8200', 'Aceite de girasol', 'Volumen', '500 ml', iva: '19', brand: 'Premier'),
            $this->row('ACE-900', 'Aceite 900', '13900', 'Aceite de girasol', 'Volumen', '900 ml'),
            $this->row('ACE-2L', 'Aceite 2 litros', '28500', 'Aceite de girasol', 'Volumen', '2 L'),
        ]);

        $this->assertSame(3, $result['created'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $family = ProductFamily::query()->withoutGlobalScopes()->where('name', 'Aceite de girasol')->firstOrFail();
        $this->assertCount(3, $family->variants);
        $this->assertSame(19.0, (float) $family->tax_rate);
        $this->assertSame('Premier', $family->brand);

        $oil = Product::query()->withoutGlobalScopes()->where('sku', 'ACE-900')->firstOrFail();
        $this->assertSame($family->id, $oil->product_family_id);
        $this->assertSame('Aceite de girasol · 900 ml', $oil->name);
        $this->assertSame('900 ml', $oil->variant_label);
        $this->assertSame('ml', $oil->content_unit);
        $this->assertEqualsWithDelta(900, (float) $oil->content_amount, 0.001);
        // Lo comun lo pone la familia aunque la fila lo deje vacio.
        $this->assertSame(19.0, (float) $oil->tax_rate);
        $this->assertSame('Premier', $oil->brand);
        $this->assertSame(13900, (int) $oil->price);
    }

    public function test_caracteristicas_y_valores_nuevos_se_crean(): void
    {
        $result = $this->import([
            $this->row('LEC-1L', 'Leche', '4200', 'Leche entera', 'Presentación', 'Bolsa 1 L'),
            $this->row('LEC-X6', 'Leche', '23900', 'Leche entera', 'Presentación', 'Bolsa x6'),
            $this->row('GAS-750', 'Gaseosa', '3500', 'Gaseosa cola', 'Volumen', '750 ml', attribute2: 'Sabor', value2: 'Cola'),
        ]);

        $this->assertSame(3, $result['created'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $presentation = ProductAttribute::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('slug', 'presentacion')->firstOrFail();
        $this->assertNull($presentation->measure);
        $this->assertSame(['Bolsa 1 L', 'Bolsa x6'], $presentation->values()->pluck('value')->all());

        // La medida nueva cae en su lugar, con cantidad y unidad.
        $volume = $this->attribute('Volumen');
        $value = $volume->values()->where('value', '750 ml')->firstOrFail();
        $this->assertEqualsWithDelta(750, (float) $value->amount, 0.001);
        $this->assertSame('ml', $value->unit);
        $this->assertSame(['500 ml', '750 ml', '900 ml'], $volume->values()->whereIn('value', ['500 ml', '750 ml', '900 ml'])->pluck('value')->all());

        $soda = ProductFamily::query()->withoutGlobalScopes()->where('name', 'Gaseosa cola')->firstOrFail();
        $this->assertSame($this->attribute('Sabor')->id, $soda->attribute_2_id);
    }

    public function test_una_fila_que_contradice_la_familia_falla_con_su_motivo(): void
    {
        Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Aseo', 'slug' => 'aseo', 'is_active' => true]);

        $result = $this->import([
            $this->row('ACE-500', 'Aceite', '8200', 'Aceite de girasol', 'Volumen', '500 ml', category: 'Despensa', iva: '19'),
            $this->row('ACE-900', 'Aceite', '13900', 'Aceite de girasol', 'Volumen', '900 ml', category: 'Aseo'),
            $this->row('ACE-9002', 'Aceite', '13900', 'Aceite de girasol', 'Volumen', '500 ml'),
            $this->row('ACE-T', 'Aceite', '13900', 'Aceite de girasol', 'Talla', 'M'),
            $this->row('ACE-IVA', 'Aceite', '13900', 'Aceite de girasol', 'Volumen', '2 L', iva: '5'),
            $this->row('ACE-MAL', 'Aceite', '13900', 'Aceite de girasol', 'Volumen', '900'),
            $this->row('ACE-SIN', 'Aceite', '13900', 'Aceite de girasol', '', ''),
        ]);

        $this->assertSame(1, $result['created'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame(6, $result['skipped']);

        $reasons = collect($result['failures'])->pluck('reason', 'line');
        $this->assertStringContainsString('la categoría no coincide con la de «Aceite de girasol»', $reasons[3]);
        $this->assertStringContainsString('ya tiene el subartículo 500 ml (SKU ACE-500)', $reasons[4]);
        $this->assertStringContainsString('se arma con Volumen, no con Talla', $reasons[5]);
        $this->assertStringContainsString('el IVA no coincide', $reasons[6]);
        $this->assertStringContainsString('«900» no es un valor de Volumen', $reasons[7]);
        $this->assertStringContainsString('hacen falta atributo_1 y valor_1', $reasons[8]);

        // La fila que falla no deja ni el articulo ni la caracteristica a medias.
        $this->assertSame(['ACE-500'], Product::query()->withoutGlobalScopes()->where('sku', 'like', 'ACE-%')->pluck('sku')->all());
    }

    public function test_un_archivo_sin_las_columnas_nuevas_funciona_igual(): void
    {
        $family = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8200], ['900 ml', null, 13900]], ['tax_rate' => 19]);
        $variant = $family->variants()->orderBy('variant_sort')->firstOrFail();

        $old = ['sku;nombre;categoria;unidad;costo;precio;iva;minimo;codigo_barras;marca',
            'ARR-001;Arroz blanco 1 kg;Despensa;unit;3600;5100;0;80;7701234567890;Diana',
            $variant->sku.';Otro nombre;;unit;6000;8900;;5;;'];

        $result = $this->importLines($old);

        $this->assertSame(1, $result['created'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $result['updated']);
        $rice = Product::query()->withoutGlobalScopes()->where('sku', 'ARR-001')->firstOrFail();
        $this->assertNull($rice->product_family_id);
        $this->assertSame('Arroz blanco 1 kg', $rice->name);

        // El subarticulo actualiza lo suyo y sigue en su articulo, con su nombre compuesto.
        $variant->refresh();
        $this->assertSame($family->id, $variant->product_family_id);
        $this->assertSame('Aceite de girasol · 500 ml', $variant->name);
        $this->assertSame(8900, (int) $variant->price);
        $this->assertSame(19.0, (float) $variant->tax_rate);
    }

    public function test_exportar_y_volver_a_importar_da_lo_mismo(): void
    {
        $shirt = $this->makeFamily('Camiseta básica', 'Talla', 'Color', [['S', 'Azul', 29900], ['M', 'Azul', 29900], ['M', 'Negro', 31900]], ['tax_rate' => 19]);
        $oil = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8200], ['1 L', null, 15400]], ['tax_rate' => 19]);
        $this->makeProduct(['name' => 'Arroz blanco 1 kg', 'sku' => 'ARR-001']);

        $before = $this->snapshot();

        $csv = $this->actingAs($this->admin)->get(route('products.export'))->assertOk()->streamedContent();
        // fputcsv pone comillas donde hay espacios: se compara el encabezado sin ellas.
        $this->assertStringContainsString('Articulo principal;Atributo 1;Valor 1;Atributo 2;Valor 2', str_replace('"', '', (string) strtok($csv, "\n")));

        $path = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($path, $csv);
        $result = app(MasterImportService::class)->run(new ProductImport, $path, $this->company->id);

        $this->assertSame(0, $result['skipped'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $result['created']);
        $this->assertSame(6, $result['updated']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(2, ProductFamily::query()->withoutGlobalScopes()->count());
        $this->assertSame(3, $shirt->variants()->count());
        $this->assertSame(2, $oil->variants()->count());
    }

    /** @return list<string> */
    protected function snapshot(): array
    {
        return Product::query()->withoutGlobalScopes()->orderBy('sku')->get()
            ->map(fn (Product $product) => implode('|', [$product->sku, $product->name, $product->product_family_id, $product->variant_key, $product->variant_label, $product->price, (float) $product->tax_rate, $product->category_id, $product->barcode]))
            ->all();
    }


    /** @return list<string> */
    protected function row(string $sku, string $name, string $price, string $family, string $attribute1, string $value1, string $category = '', string $iva = '', string $brand = '', string $attribute2 = '', string $value2 = ''): array
    {
        return [$sku, $name, $category, 'unit', '0', $price, $iva, '5', '', $brand, $family, $attribute1, $value1, $attribute2, $value2];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array<string, mixed>
     */
    protected function import(array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'prd');
        $handle = fopen($path, 'w');

        foreach ([array_keys((new ProductImport)->columns()), ...$rows] as $row) {
            fputcsv($handle, $row, ';');
        }

        fclose($handle);

        return app(MasterImportService::class)->run(new ProductImport, $path, $this->company->id);
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    protected function importLines(array $lines): array
    {
        $path = tempnam(sys_get_temp_dir(), 'prd');
        file_put_contents($path, implode("\n", $lines));

        return app(MasterImportService::class)->run(new ProductImport, $path, $this->company->id);
    }
}
