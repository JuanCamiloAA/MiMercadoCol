<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Services\PaymentTermService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerSeeder extends Seeder
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: bool}>
     *                                                                                        nombre, documento, barrio, telefono, cupo, plazo en dias, credito habilitado
     */
    protected const CUSTOMERS = [
        ['Luz Marina Agudelo', '43112233', 'Laureles', '3104567890', 800000, 15, true],
        ['Tienda Don Jairo', '900112233', 'Boston', '3115678901', 2500000, 30, true],
        ['Restaurante La Esquina', '901223344', 'Centro', '3126789012', 1800000, 30, true],
        ['Carlos Andrés Ruiz', '71445566', 'Belén', '3137890123', 600000, 8, true],
        ['Panadería El Trigo', '900334455', 'Robledo', '3148901234', 1200000, 15, true],
        ['Marta Lucía Vega', '32556677', 'Envigado', '3159012345', 500000, 15, true],
        ['Juan Esteban Mora', '1017889900', 'Poblado', '3160123456', 0, 0, false],
        ['Sandra Milena López', '43667788', 'Aranjuez', '3171234567', 0, 0, false],
        ['Óscar Mauricio Tobón', '71778899', 'Manrique', '3182345678', 0, 0, false],
        ['Claudia Patricia Ríos', '32889900', 'Castilla', '3193456789', 0, 0, false],
        ['Andrés Felipe Cano', '1020445566', 'Buenos Aires', '3104567891', 0, 0, false],
        ['Gloria Inés Pérez', '43990011', 'La América', '3115678902', 0, 0, false],
        ['Miguel Ángel Duque', '71001122', 'Guayabal', '3126789013', 0, 0, false],
        ['Natalia Restrepo', '1018223344', 'Sabaneta', '3137890124', 0, 0, false],
        ['Consumidor final', '222222222', 'Centro', '0000000', 0, 0, false],
    ];

    public function run(): void
    {
        $company = Company::query()->firstOrFail();

        foreach (self::CUSTOMERS as $index => $row) {
            [$name, $document, $neighborhood, $phone, $creditLimit, $termDays, $creditEnabled] = $row;

            $isBusiness = str_starts_with($document, '900') || str_starts_with($document, '901');
            $isOnline = $index < 8;

            $customer = Customer::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'document_number' => $document],
                [
                    'document_type' => $isBusiness ? 'NIT' : 'CC',
                    'name' => $name,
                    'business_name' => $isBusiness ? $name.' S.A.S.' : null,
                    // `Str::slug` quita las tildes: «Carlos Andrés Ruiz» sigue entrando con carlos.andres.ruiz@correo.co.
                    'email' => $index === 14 ? null : Str::slug($name, '.').'@correo.co',
                    'phone' => $phone,
                    'address' => 'Calle '.random_int(1, 90).' # '.random_int(10, 99).'-'.random_int(10, 99),
                    'neighborhood' => $neighborhood,
                    'delivery_notes' => $index % 3 === 0 ? 'Timbre del apartamento, segundo piso.' : null,
                    'credit_limit' => $creditLimit,
                    'credit_term_days' => $termDays,
                    'payment_term_id' => $creditEnabled ? app(PaymentTermService::class)->forDays($company->id, (int) $termDays, true)?->id : null,
                    'credit_enabled' => $creditEnabled,
                    'credit_blocked' => false,
                    'is_online_user' => $isOnline,
                    'password' => $isOnline ? Hash::make('Cliente123*') : null,
                    'is_active' => true,
                ]
            );

            CustomerBalance::query()->firstOrCreate(
                ['customer_id' => $customer->id],
                ['balance' => 0, 'overdue_balance' => 0]
            );
        }
    }
}
