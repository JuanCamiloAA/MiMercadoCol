<?php

namespace Tests\Feature;

use App\Services\BrandThemeService;
use App\Services\ReceiptService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Una empresa que no ha puesto nombre a su marca sale con el suyo, no con el de fabrica (que es
 * el de la primera empresa de la plataforma).
 */
class BrandDisplayNameTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_sin_nombre_de_marca_propio_sale_el_de_la_empresa(): void
    {
        ['company' => $other] = $this->makeOtherCompany('Moda Aurora', '901888777-5');

        $this->assertSame('Moda Aurora', app(SettingsService::class)->get($other->id, 'brand.display_name'));
        $this->assertSame('Moda Aurora', app(BrandThemeService::class)->forCompany($other->id)['display_name']);
        $this->assertSame('Moda Aurora', app(ReceiptService::class)->headline($other->id, $other));
    }

    public function test_el_que_la_empresa_puso_manda(): void
    {
        ['company' => $other] = $this->makeOtherCompany('Moda Aurora', '901888777-5');
        app(SettingsService::class)->set($other->id, 'brand.display_name', 'Aurora');

        $this->assertSame('Aurora', app(BrandThemeService::class)->forCompany($other->id)['display_name']);
        $this->assertSame('Aurora', app(ReceiptService::class)->headline($other->id, $other));
    }
}
