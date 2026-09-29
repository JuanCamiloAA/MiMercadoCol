<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Store;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class ReceiptSettingsTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, array<string, mixed>>
     */
    protected function receipt(array $overrides = [], ?string $footer = null): array
    {
        return [
            'receipt' => array_merge([
                'paper_width' => 80,
                'font_size' => 'normal',
                'show_logo' => false,
                'header' => "Responsable de IVA\nAtendemos de 7:00 a. m. a 9:00 p. m.",
                'fields' => ['store_address', 'store_phone', 'cashier', 'seller', 'customer'],
                'code' => 'none',
                'copies' => 1,
                'auto_print' => true,
            ], $overrides),
            'pos' => ['receipt_footer' => $footer ?? 'Gracias por su compra.'],
        ];
    }

    protected function sale(): Sale
    {
        $product = $this->makeProduct(['name' => 'Arroz 500 g', 'price' => 3250]);
        $session = app(RegisterSessionService::class)->open($this->register, $this->admin, 100000);

        return app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 6500, 'received_amount' => 10000]],
        ], $this->admin, $this->store, $session);
    }

    protected function printed(Sale $sale): string
    {
        return $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('pos.sales.print', $sale))
            ->assertOk()
            ->getContent();
    }

    public function test_de_fabrica_imprime_lo_de_siempre(): void
    {
        $html = $this->printed($this->sale());

        $this->assertStringContainsString('@page { size: 80mm auto; margin: 0; }', $html);
        $this->assertStringContainsString('font-size: 11px;', $html);
        $this->assertStringContainsString('<td>Cajero</td>', $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringNotContainsString('class="logo"', $html);
        $this->assertStringNotContainsString('class="code', $html);
        $this->assertSame(1, substr_count($html, '<div class="copy">'));
    }

    public function test_el_tiquete_de_la_empresa_respeta_papel_campos_codigo_y_copias(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.receipt.update'), $this->receipt([
                'paper_width' => 58,
                'font_size' => 'large',
                'show_logo' => true,
                'header' => 'Responsable de IVA',
                'fields' => ['store_address', 'customer'],
                'code' => 'barcode',
                'copies' => 3,
                'auto_print' => false,
            ]))
            ->assertSessionHasNoErrors();

        $html = $this->printed($this->sale());

        $this->assertStringContainsString('@page { size: 58mm auto; margin: 0; }', $html);
        $this->assertStringContainsString('font-size: 12px;', $html);
        $this->assertStringContainsString('Responsable de IVA', $html);
        $this->assertStringNotContainsString('<td>Cajero</td>', $html);
        // NIT y base/IVA salen siempre.
        $this->assertStringContainsString('NIT 900111222-3', $html);
        $this->assertStringContainsString('<td>IVA</td>', $html);
        $this->assertStringContainsString('class="code code-barcode" src="data:image/svg+xml;base64,', $html);
        // Sin logo subido sale la canasta en negro.
        $this->assertStringContainsString('class="logo" src="data:image/svg+xml;base64,', $html);
        $this->assertSame(3, substr_count($html, '<div class="copy">'));
        $this->assertStringNotContainsString("window.addEventListener('load'", $html);
    }

    public function test_el_pdf_sale_con_el_papel_de_la_sede(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.receipt.update'), $this->receipt(['paper_width' => 58, 'header' => 'Responsable de IVA', 'code' => 'qr', 'show_logo' => true]))
            ->assertSessionHasNoErrors();

        $sale = $this->sale();

        $response = $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->get(route('pos.sales.receipt', $sale))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        // 58 mm son 164,41 puntos de ancho.
        $this->assertMatchesRegularExpression('/MediaBox \[0(\.0+)? 0(\.0+)? 164\.4/', $response->getContent());
    }

    public function test_la_excepcion_de_la_sede_gana_y_null_la_quita(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->admin)
            ->put(route('settings.receipt.store.update', $this->store), $this->receipt(['paper_width' => 58, 'header' => 'Sede centro']) + [
                'own' => ['receipt.paper_width', 'receipt.header'],
            ])
            ->assertSessionHasNoErrors();

        $overrides = app(SettingsService::class)->overridesFor($this->company->id, $this->store->id);
        $this->assertSame(['receipt.paper_width' => 58, 'receipt.header' => 'Sede centro'], $overrides);

        $html = $this->printed($sale);
        $this->assertStringContainsString('@page { size: 58mm auto; margin: 0; }', $html);
        $this->assertStringContainsString('Sede centro', $html);

        // Sin nada propio, la sede vuelve a imprimir lo de la empresa.
        $this->actingAs($this->admin)
            ->put(route('settings.receipt.store.update', $this->store), $this->receipt() + ['own' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame([], app(SettingsService::class)->overridesFor($this->company->id, $this->store->id));
        $this->assertStringContainsString('@page { size: 80mm auto; margin: 0; }', $this->printed($sale));
    }

    public function test_un_encabezado_vacio_propio_no_se_confunde_con_heredar(): void
    {
        app(SettingsService::class)->set($this->company->id, 'receipt.header', 'Encabezado de la empresa');

        $this->actingAs($this->admin)
            ->put(route('settings.receipt.store.update', $this->store), $this->receipt(['header' => '']) + ['own' => ['receipt.header']])
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('Encabezado de la empresa', $this->printed($this->sale()));
    }

    public function test_un_encabezado_largo_falla_si_una_sede_imprime_en_58(): void
    {
        app(SettingsService::class)->setForStore($this->company->id, $this->store->id, 'receipt.paper_width', 58);

        $header = str_repeat('x', 45);

        $this->actingAs($this->admin)
            ->put(route('settings.receipt.update'), $this->receipt(['header' => $header]))
            ->assertSessionHasErrors(['receipt.header' => 'La línea 1 tiene 45 caracteres; en papel de 58 mm caben 32. La sede Centro imprime en 58 mm.']);

        // En 80 mm, sin sedes en 58, cabe.
        app(SettingsService::class)->setForStore($this->company->id, $this->store->id, 'receipt.paper_width', null);

        $this->actingAs($this->admin)
            ->put(route('settings.receipt.update'), $this->receipt(['header' => $header]))
            ->assertSessionHasNoErrors();
    }

    public function test_un_encabezado_heredado_se_mide_con_el_papel_propio_de_la_sede(): void
    {
        app(SettingsService::class)->set($this->company->id, 'receipt.header', str_repeat('x', 45));

        $this->actingAs($this->admin)
            ->put(route('settings.receipt.store.update', $this->store), $this->receipt(['paper_width' => 58]) + ['own' => ['receipt.paper_width']])
            ->assertSessionHasErrors('receipt.header');
    }

    public function test_mas_de_seis_lineas_se_rechaza(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.receipt.update'), $this->receipt(['header' => implode("\n", range(1, 7))]))
            ->assertSessionHasErrors(['receipt.header' => 'El encabezado tiene 7 líneas; caben máximo 6.']);
    }

    public function test_solo_el_tiquete_puede_ser_propio_de_una_sede(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.receipt.store.update', $this->store), $this->receipt() + ['own' => ['brand.accent']])
            ->assertSessionHasErrors('own');
    }

    public function test_la_sede_de_otra_empresa_da_404(): void
    {
        $other = Company::create(['name' => 'Otra', 'legal_name' => 'Otra S.A.S.', 'tax_id' => '800000000-1', 'currency' => 'COP', 'is_active' => true]);
        $foreign = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'name' => 'Ajena',
            'code' => 'AJE',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->put(route('settings.receipt.store.update', $foreign), $this->receipt() + ['own' => ['receipt.paper_width']])
            ->assertNotFound();

        $this->assertSame([], app(SettingsService::class)->overridesFor($other->id, $foreign->id));
    }

    public function test_sin_permiso_de_caja_no_se_cambia_el_tiquete(): void
    {
        $this->actingAs($this->makeUser('cajero'))
            ->put(route('settings.receipt.update'), $this->receipt())
            ->assertForbidden();
    }
}
