<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Services\Payments\PaymentContext;
use App\Services\Payments\PaymentMethodAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\CreatesPaymentGateways;
use Tests\TestCase;

/**
 * `PaymentMethodAvailability` es la unica respuesta a «¿que medios valen aqui y ahora?». Cada
 * regla de `availability` se prueba en su borde.
 */
class PaymentMethodAvailabilityTest extends TestCase
{
    use CreatesCompanyEnvironment, CreatesPaymentGateways, RefreshDatabase;

    protected PaymentMethodAvailability $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->availability = app(PaymentMethodAvailability::class);
    }

    /** Un lunes de octubre de 2026, a la hora dada en Bogota. */
    protected function monday(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-10-05 {$time}:00", 'America/Bogota');
    }

    protected function pos(?CarbonImmutable $at = null, ?Customer $customer = null, ?int $registerId = null): PaymentContext
    {
        return PaymentContext::pos($this->store->id, $registerId ?? $this->register->id, $customer, null, $at);
    }

    public function test_la_empresa_nace_con_los_medios_de_fabrica(): void
    {
        $keys = PaymentMethod::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('system_key')->all();

        foreach (['cash', 'card', 'transfer', 'credit', 'cash_on_delivery', 'card_on_delivery', 'store_credit', 'exchange'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_el_horario_incluye_el_inicio_y_no_el_fin(): void
    {
        $nequi = $this->manualMethod('Nequi', PaymentMethod::KIND_TRANSFER, [
            'availability' => ['schedule' => [['days' => [1, 2, 3, 4, 5], 'from' => '07:00', 'to' => '21:00']]],
        ]);

        $this->assertNotNull($this->availability->reason($nequi, $this->pos($this->monday('06:59'))));
        $this->assertNull($this->availability->reason($nequi, $this->pos($this->monday('07:00'))));
        $this->assertNull($this->availability->reason($nequi, $this->pos($this->monday('20:59'))));
        $this->assertSame('«Nequi» no está disponible a esta hora.', $this->availability->reason($nequi, $this->pos($this->monday('21:00'))));

        // El domingo no esta en la franja.
        $sunday = CarbonImmutable::parse('2026-10-04 10:00:00', 'America/Bogota');
        $this->assertNotNull($this->availability->reason($nequi, $this->pos($sunday)));
    }

    public function test_la_hora_es_la_de_colombia_aunque_el_reloj_este_en_utc(): void
    {
        $nequi = $this->manualMethod('Nequi', PaymentMethod::KIND_TRANSFER, [
            'availability' => ['schedule' => [['days' => [1], 'from' => '07:00', 'to' => '21:00']]],
        ]);

        // 01:30 UTC del martes son las 20:30 del lunes en Bogota.
        $utc = CarbonImmutable::parse('2026-10-06 01:30:00', 'UTC');

        $this->assertNull($this->availability->reason($nequi, $this->pos($utc)));
    }

    public function test_una_franja_que_pasa_la_medianoche_vale_al_otro_dia(): void
    {
        $night = $this->manualMethod('Turno de noche', PaymentMethod::KIND_OTHER, [
            'availability' => ['schedule' => [['days' => [1], 'from' => '22:00', 'to' => '02:00']]],
        ]);

        $this->assertNull($this->availability->reason($night, $this->pos($this->monday('23:30'))));
        $this->assertNull($this->availability->reason($night, $this->pos(CarbonImmutable::parse('2026-10-06 01:59:00', 'America/Bogota'))));
        $this->assertNotNull($this->availability->reason($night, $this->pos(CarbonImmutable::parse('2026-10-06 02:00:00', 'America/Bogota'))));
        $this->assertNotNull($this->availability->reason($night, $this->pos($this->monday('21:59'))));
    }

    public function test_la_vigencia_cuenta_los_dos_extremos(): void
    {
        $bono = $this->manualMethod('Bono navideño', PaymentMethod::KIND_OTHER, [
            'availability' => ['valid_from' => '2026-12-01', 'valid_until' => '2026-12-31'],
        ]);

        $this->assertStringContainsString('empieza a regir', (string) $this->availability->reason($bono, $this->pos(CarbonImmutable::parse('2026-11-30 23:59', 'America/Bogota'))));
        $this->assertNull($this->availability->reason($bono, $this->pos(CarbonImmutable::parse('2026-12-01 00:00', 'America/Bogota'))));
        $this->assertNull($this->availability->reason($bono, $this->pos(CarbonImmutable::parse('2026-12-31 23:59', 'America/Bogota'))));
        $this->assertSame('«Bono navideño» ya no está vigente.', $this->availability->reason($bono, $this->pos(CarbonImmutable::parse('2027-01-01 00:00', 'America/Bogota'))));
    }

    public function test_minimo_y_maximo_se_miden_contra_lo_que_se_cobra_con_ese_medio(): void
    {
        $daviplata = $this->manualMethod('Daviplata', PaymentMethod::KIND_TRANSFER, [
            'availability' => ['min_amount' => 5000, 'max_amount' => 500000],
        ]);

        $this->assertSame('Con «Daviplata» se cobra desde $ 5.000.', $this->availability->reason($daviplata, $this->pos(), 4999));
        $this->assertNull($this->availability->reason($daviplata, $this->pos(), 5000));
        $this->assertNull($this->availability->reason($daviplata, $this->pos(), 500000));
        $this->assertNotNull($this->availability->reason($daviplata, $this->pos(), 500001));

        // Al cobrar, lo mismo: un renglon de 3.000 se rechaza aunque la venta sea de 50.000.
        $this->expectException(ValidationException::class);
        $this->availability->resolve($this->company->id, $this->pos(), [
            ['payment_method_id' => $daviplata->id, 'amount' => 3000],
            ['method' => PaymentMethod::KIND_CASH, 'amount' => 47000],
        ]);
    }

    public function test_sede_caja_y_canal_acotan(): void
    {
        $otherRegister = Register::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Caja 2',
            'code' => 'C2',
            'is_active' => true,
        ]);

        $onlyBox2 = $this->manualMethod('Bono empresa', PaymentMethod::KIND_OTHER, ['availability' => ['registers' => [$otherRegister->id]]]);
        $onlyOnline = $this->manualMethod('Transferencia al recibir', PaymentMethod::KIND_TRANSFER, ['availability' => ['channels' => ['online']]]);

        $names = fn (PaymentContext $context) => $this->availability->available($this->company->id, $context)->pluck('name')->all();

        $this->assertNotContains('Bono empresa', $names($this->pos()));
        $this->assertContains('Bono empresa', $names($this->pos(registerId: $otherRegister->id)));
        $this->assertNotContains('Transferencia al recibir', $names($this->pos()));
        $this->assertContains('Transferencia al recibir', $names(PaymentContext::online($this->store->id, 20000)));
        $this->assertNotNull($onlyBox2->id);
        $this->assertNotNull($onlyOnline->id);
    }

    public function test_las_reglas_del_cliente(): void
    {
        $sodexo = $this->manualMethod('Bono Sodexo', PaymentMethod::KIND_OTHER, ['availability' => ['customer' => ['registered' => true]]]);
        $generic = $this->makeCustomer(['document_number' => '222222222222', 'name' => 'Consumidor final']);
        $known = $this->makeCustomer();

        $this->assertSame('«Bono Sodexo» pide un cliente registrado.', $this->availability->reason($sodexo, $this->pos()));
        $this->assertNotNull($this->availability->reason($sodexo, $this->pos(customer: $generic)));
        $this->assertNull($this->availability->reason($sodexo, $this->pos(customer: $known)));
    }

    public function test_un_medio_que_no_se_combina_tiene_que_pagar_todo(): void
    {
        $bono = $this->manualMethod('Bono', PaymentMethod::KIND_OTHER, ['availability' => ['combinable' => false]]);

        try {
            $this->availability->resolve($this->company->id, $this->pos(), [
                ['payment_method_id' => $bono->id, 'amount' => 10000],
                ['method' => PaymentMethod::KIND_CASH, 'amount' => 2000],
            ]);
            $this->fail('Se aceptó un medio que no se combina junto con otro.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no se combina', collect($exception->errors())->flatten()->first());
        }

        $lines = $this->availability->resolve($this->company->id, $this->pos(), [['payment_method_id' => $bono->id, 'amount' => 12000]]);
        $this->assertSame(PaymentMethod::KIND_OTHER, $lines[0]['method']);
    }

    public function test_apagado_no_aparece_ni_se_cobra(): void
    {
        $nequi = $this->manualMethod('Nequi', PaymentMethod::KIND_TRANSFER, ['is_active' => false]);

        $this->assertNotContains('Nequi', $this->availability->available($this->company->id, $this->pos())->pluck('name')->all());
        $this->expectException(ValidationException::class);
        $this->availability->resolve($this->company->id, $this->pos(), [['payment_method_id' => $nequi->id, 'amount' => 1000]]);
    }

    public function test_un_medio_de_otra_empresa_no_se_reconoce(): void
    {
        $other = $this->makeOtherCompany()['company'];
        $foreign = PaymentMethod::query()->withoutGlobalScopes()->where('company_id', $other->id)->where('system_key', 'cash')->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->availability->resolve($this->company->id, $this->pos(), [['payment_method_id' => $foreign->id, 'amount' => 1000]]);
    }

    public function test_la_pasarela_sin_cuenta_encendida_no_aparece(): void
    {
        $account = $this->wompiAccount();
        $qr = $this->gatewayMethod(PaymentMethod::KIND_GATEWAY_LINK, $account);

        $this->assertNull($this->availability->reason($qr, $this->pos()));

        $account->forceFill(['is_enabled' => false])->save();
        $qr->unsetRelation('gatewayAccount');

        $this->assertStringContainsString('no tiene una cuenta de pasarela encendida', (string) $this->availability->reason($qr->fresh(), $this->pos()));
    }
}
