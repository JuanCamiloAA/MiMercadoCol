<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Atributos pide su permiso en cada ruta, y en una base en uso se reparte por equivalencia: quien
 * veia el catalogo ve las caracteristicas; quien editaba articulos las administra.
 */
class ProductAttributesPermissionTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
    }

    public function test_cada_ruta_pide_su_permiso(): void
    {
        $cashier = $this->makeUser('cajero');
        $supervisor = $this->makeUser('supervisor');
        $flavor = $this->attribute('Sabor');

        $this->actingAs($cashier)->get(route('attributes.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('attributes.values.store', $flavor), ['value' => 'Mora'])->assertForbidden();

        $this->actingAs($supervisor)->get(route('attributes.index'))->assertOk();
        $this->actingAs($supervisor)->post(route('attributes.values.store', $flavor), ['value' => 'Mora'])->assertSessionHasNoErrors();
    }

    public function test_la_migracion_reparte_por_equivalencia(): void
    {
        $viewer = $this->makeUser('bodega');
        $editor = $this->makeUser('supervisor');

        $viewer->revokePermissionTo(['products.attributes.view']);
        $editor->revokePermissionTo(['products.attributes.view', 'products.attributes.manage']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (require database_path('migrations/2026_09_29_000140_grant_product_attribute_permissions.php'))->up();

        $this->assertTrue($viewer->fresh()->hasPermissionTo('products.attributes.view'));
        $this->assertFalse($viewer->fresh()->hasPermissionTo('products.attributes.manage'));
        $this->assertTrue($editor->fresh()->hasPermissionTo('products.attributes.manage'));
    }
}
