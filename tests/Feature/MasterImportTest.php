<?php

namespace Tests\Feature;

use App\Imports\ImportRegistry;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Importacion de maestras desde un archivo.
 *
 * Lo que se prueba no es tanto que cargue, sino que no haga dano: que una fila mala se
 * quede por fuera con su motivo en vez de tumbar la carga entera, y que quien no tiene el
 * permiso no llegue a la pantalla.
 */
class MasterImportTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    public function test_todas_las_maestras_abren_su_pantalla_de_importacion(): void
    {
        foreach (ImportRegistry::modules() as $module) {
            $this->actingAs($this->admin)
                ->withSession($this->withActiveStore())
                ->get(route("{$module}.import.form"))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Imports/Form')
                    ->where('module', $module)
                    ->has('columns')
                    ->has('example'));
        }
    }

    public function test_un_rol_sin_el_permiso_no_llega_a_importar(): void
    {
        $cashier = $this->makeUser('cajero');

        foreach (ImportRegistry::modules() as $module) {
            $this->actingAs($cashier)
                ->withSession($this->withActiveStore())
                ->get(route("{$module}.import.form"))
                ->assertStatus(403);
        }
    }

    public function test_importar_clientes_crea_actualiza_y_deja_el_saldo(): void
    {
        $this->upload('customers', [
            'documento;tipo_documento;nombre;telefono;correo;direccion;barrio;cupo;plazo_dias',
            '43112233;CC;Luz Marina Agudelo;3123334455;luzma@correo.co;Calle 30 # 65-12;Laureles;300000;15',
            '71998877;CC;Jairo Restrepo;3109998877;;Cra 70 # 44-10;Belen;0;0',
        ])->assertRedirect(route('customers.index'));

        $this->assertDatabaseCount('customers', 2);

        $luz = Customer::query()->withoutGlobalScopes()->where('document_number', '43112233')->first();
        $this->assertSame('Luz Marina Agudelo', $luz->name);
        $this->assertSame(300000, $luz->credit_limit);
        $this->assertTrue($luz->credit_enabled);
        $this->assertDatabaseHas('customer_balances', ['customer_id' => $luz->id, 'balance' => 0]);

        // Sin cupo no se le habilita el credito.
        $jairo = Customer::query()->withoutGlobalScopes()->where('document_number', '71998877')->first();
        $this->assertFalse($jairo->credit_enabled);

        // El mismo documento actualiza en vez de duplicar.
        $this->upload('customers', [
            'documento;tipo_documento;nombre;telefono;correo;direccion;barrio;cupo;plazo_dias',
            '43112233;CC;Luz Marina Agudelo Vda;3123334455;luzma@correo.co;Calle 30 # 65-12;Laureles;500000;30',
        ]);

        $this->assertDatabaseCount('customers', 2);
        $this->assertSame(500000, $luz->fresh()->credit_limit);
    }

    public function test_una_fila_incompleta_se_omite_y_dice_por_que(): void
    {
        $response = $this->upload('suppliers', [
            'nombre;nit;contacto;telefono;correo;direccion;dias_entrega;activo',
            'Distribuidora El Trigal;900123456-7;Hernan Pulgarin;3105551234;;Cra 45 # 30-12;3;si',
            ';900999888-1;Sin nombre;;;;;si',
        ]);

        $response->assertRedirect(route('suppliers.import.form'));
        $this->assertSame(1, Supplier::query()->withoutGlobalScopes()->count());

        $result = session('import_result');
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Fila 3', $result['errors'][0]);
        $this->assertStringContainsString('nombre', $result['errors'][0]);
    }

    public function test_el_archivo_sin_las_columnas_obligatorias_se_rechaza(): void
    {
        $this->upload('customers', [
            'nombre;telefono',
            'Luz Marina Agudelo;3123334455',
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_lee_archivos_separados_por_coma_y_con_el_bom_de_excel(): void
    {
        $content = "\u{FEFF}nombre,padre,orden,activa\nLacteos,,10,si\n";

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->post(route('categories.import'), [
                'file' => UploadedFile::fake()->createWithContent('categorias.csv', $content),
            ]);

        $this->assertDatabaseHas('categories', ['name' => 'Lacteos', 'slug' => 'lacteos']);
    }

    public function test_la_categoria_padre_debe_existir(): void
    {
        $this->upload('categories', [
            'nombre;padre;orden;activa',
            'Quesos;Lacteos;1;si',
        ]);

        $this->assertSame(0, Category::query()->withoutGlobalScopes()->where('name', 'Quesos')->count());
        $this->assertStringContainsString('Lacteos', session('import_result')['errors'][0]);
    }

    public function test_el_empleado_queda_en_la_sede_que_dice_el_archivo(): void
    {
        $this->upload('employees', [
            'documento;tipo_documento;nombres;apellidos;cargo;tipo_contrato;ingreso;sede;telefono;correo',
            '1017223344;CC;Diana Carolina;Pulgarin Mesa;Cajera;indefinido;01/03/2025;CEN;3114445566;diana@correo.co',
        ]);

        $employee = Employee::query()->withoutGlobalScopes()->first();

        $this->assertSame($this->store->id, $employee->store_id);
        $this->assertSame('2025-03-01', $employee->hired_at->toDateString());
        $this->assertSame('Cajera', $employee->position);
    }

    public function test_la_importacion_queda_registrada(): void
    {
        $this->upload('categories', ['nombre;padre;orden;activa', 'Lacteos;;10;si']);

        $this->assertDatabaseHas('access_logs', [
            'user_id' => $this->admin->id,
            'action' => 'master_import',
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    protected function upload(string $module, array $lines)
    {
        return $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->post(route("{$module}.import"), [
                'file' => UploadedFile::fake()->createWithContent("{$module}.csv", implode("\n", $lines)."\n"),
            ]);
    }
}
