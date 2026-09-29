<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class NavigationSettingsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    /**
     * @param  array<string, mixed>  $navigation
     * @param  array<string, string>  $homes
     */
    protected function save(array $navigation, array $homes = [])
    {
        return $this->actingAs($this->admin)->put(route('settings.navigation.update'), [
            'panel' => ['navigation' => $navigation, 'home_by_role' => $homes],
        ]);
    }

    public function test_renombra_ordena_y_oculta_para_todos(): void
    {
        $this->save([
            'section_order' => ['merchandise', 'operation'],
            'entry_order' => ['operation' => ['pos.index', 'dashboard']],
            'labels' => ['merchandise' => 'Bodega', 'pos.index' => 'Caja rápida', 'registers' => ''],
            'hidden' => ['marketing'],
        ])->assertSessionHasNoErrors();

        $saved = app(SettingsService::class)->get($this->company->id, 'panel.navigation');

        $this->assertSame(['merchandise' => 'Bodega', 'pos.index' => 'Caja rápida'], $saved['labels']);
        $this->assertSame(['marketing'], $saved['hidden']);
        $this->assertSame(['pos.index', 'dashboard'], $saved['entry_order']['operation']);

        // Lo ve cualquiera de la empresa, no solo quien lo guardo.
        $this->actingAs($this->makeUser('cajero'))
            ->withSession($this->withActiveStore())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('brand.navigation.labels', fn ($labels) => collect($labels)->get('merchandise') === 'Bodega')
                ->where('brand.navigation.hidden', ['marketing']));
    }

    public function test_no_se_puede_ocultar_donde_vive_ajustes(): void
    {
        $this->save(['hidden' => ['company']])
            ->assertSessionHasErrors(['panel.navigation.hidden' => 'Ajustes vive en Empresa: si lo ocultas, nadie podría volver a esta pantalla para deshacerlo.']);

        $this->save(['hidden' => ['admin']])->assertSessionHasErrors('panel.navigation.hidden');
        $this->save(['hidden' => ['settings.index']])->assertSessionHasErrors('panel.navigation.hidden');

        $this->assertSame([], app(SettingsService::class)->get($this->company->id, 'panel.navigation'));
    }

    public function test_claves_desconocidas_se_aceptan_sin_romper(): void
    {
        // Una pantalla que ya no existe, o una que todavia no: se guarda y el front la ignora.
        $this->save([
            'hidden' => ['pantalla_vieja', 'reportes.antiguos'],
            'labels' => ['modulo_futuro' => 'Algo'],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('settings.index'))
            ->assertOk();
    }

    public function test_claves_con_formato_raro_se_rechazan(): void
    {
        $this->save(['hidden' => ['<script>']])->assertSessionHasErrors('panel.navigation.hidden');
        $this->save(['labels' => ['dashboard' => str_repeat('x', 31)]])->assertSessionHasErrors('panel.navigation.labels');
        $this->save(['otra_cosa' => ['a']])->assertSessionHasErrors('panel.navigation');
    }

    public function test_inicio_por_rol_solo_acepta_paginas_de_la_lista(): void
    {
        $this->save([], ['cajero' => 'users.index'])->assertSessionHasErrors('panel.home_by_role');
        $this->save([], ['rol_inventado' => 'pos.index'])->assertSessionHasErrors('panel.home_by_role');

        $this->save([], ['cajero' => 'pos.index', 'admin' => 'dashboard'])->assertSessionHasNoErrors();

        // El inicio por defecto no se guarda: es lo que pasa sin configurar.
        $this->assertSame(['cajero' => 'pos.index'], app(SettingsService::class)->get($this->company->id, 'panel.home_by_role'));
    }

    public function test_sin_permiso_de_marca_no_se_cambia_el_menu(): void
    {
        $this->actingAs($this->makeUser('supervisor'))
            ->put(route('settings.navigation.update'), ['panel' => ['navigation' => [], 'home_by_role' => []]])
            ->assertForbidden();
    }
}
