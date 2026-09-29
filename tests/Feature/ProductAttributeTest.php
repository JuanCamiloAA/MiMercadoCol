<?php

namespace Tests\Feature;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Services\ProductAttributeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El catalogo de caracteristicas: medidas con cantidad y unidad, valores sin repetir, en su
 * orden, que no se borran si alguien los usa, y cada empresa con lo suyo.
 */
class ProductAttributeTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
    }

    public function test_la_empresa_nace_con_medidas_y_textos(): void
    {
        $this->assertSame(
            ['Volumen', 'Peso', 'Unidades por paquete', 'Sabor', 'Tamaño', 'Color', 'Talla'],
            ProductAttribute::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('sort_order')->pluck('name')->all(),
        );

        $volume = $this->attribute('Volumen');
        $this->assertTrue($volume->isMeasure());
        $this->assertSame(['250 ml', '500 ml', '900 ml', '1 L', '1,5 L', '2 L', '3 L'], $volume->values->pluck('value')->all());

        // Sembrar otra vez no duplica nada.
        $this->seedAttributes();
        $this->assertSame(7, ProductAttribute::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_los_valores_se_normalizan_y_no_se_repiten(): void
    {
        $catalog = app(ProductAttributeCatalog::class);
        $size = $this->attribute('Talla');

        $this->assertSame($catalog->addValue($size, 'M')->id, $catalog->addValue($size, '  m ')->id);

        // En una medida el texto sale de la cantidad: «1 L» se escribe de una sola forma.
        $volume = $this->attribute('Volumen');
        $this->assertSame($this->value('Volumen', '1 L')->id, $catalog->addValue($volume, null, amount: 1, unit: 'l')->id);

        // Una cantidad nueva cae en su lugar, no al final.
        $catalog->addValue($volume, null, amount: 750, unit: 'ml');
        $this->assertSame(['250 ml', '500 ml', '750 ml', '900 ml', '1 L', '1,5 L', '2 L', '3 L'], $volume->fresh()->values->pluck('value')->all());
    }

    public function test_desde_el_formulario_se_crea_un_valor_al_vuelo(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->postJson(route('attributes.values.store', $this->attribute('Sabor')), ['value' => 'Mora'])
            ->assertCreated()
            ->assertJsonPath('value.value', 'Mora');

        $this->actingAs($admin)
            ->postJson(route('attributes.values.store', $this->attribute('Peso')), ['amount' => 750, 'unit' => 'l'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit');
    }

    public function test_un_valor_usado_no_se_borra(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 7900], ['1 L', null, 13900]], user: $admin);

        $used = $this->value('Volumen', '500 ml');
        $free = $this->value('Volumen', '250 ml');

        $this->actingAs($admin)->delete(route('attributes.values.destroy', $used))->assertSessionHas('error');
        $this->assertNotNull($used->fresh());

        $this->actingAs($admin)->delete(route('attributes.values.destroy', $free))->assertSessionHas('success');
        $this->assertNull($free->fresh());

        // Desactivado, los que lo usan lo conservan.
        $this->actingAs($admin)->put(route('attributes.values.update', $used), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($used->fresh()->is_active);
        $this->assertSame(2, ProductAttributeValue::query()->where('product_attribute_id', $used->product_attribute_id)->whereKey([$used->id, $this->value('Volumen', '1 L')->id])->count());
    }

    public function test_se_ordenan_arrastrando(): void
    {
        $admin = $this->makeUser('admin');
        $size = $this->attribute('Tamaño');
        $order = $size->values->pluck('id')->reverse()->values()->all();

        $this->actingAs($admin)->post(route('attributes.values.reorder', $size), ['order' => $order])->assertStatus(303);

        $this->assertSame(['Familiar', 'Grande', 'Mediano', 'Pequeño'], $size->fresh()->values->pluck('value')->all());
    }

    public function test_cada_empresa_con_lo_suyo(): void
    {
        ['company' => $other] = $this->makeOtherCompany();
        $this->seedAttributes($other->id);
        $foreign = $this->attribute('Sabor', $other->id);

        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->put(route('attributes.update', $foreign), ['name' => 'Robado', 'display' => 'buttons'])->assertNotFound();
        $this->actingAs($admin)->post(route('attributes.values.store', $foreign), ['value' => 'Ajeno'])->assertNotFound();

        $this->actingAs($admin)->get(route('attributes.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Attributes/Index')
            ->has('attributes', 7));
    }
}
