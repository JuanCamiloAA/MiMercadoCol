<?php

namespace App\Imports;

use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Services\PaymentTermService;
use Illuminate\Database\Eloquent\Model;

class CustomerImport extends MasterImport
{
    public function module(): string
    {
        return 'customers';
    }

    public function title(): string
    {
        return 'Importar clientes';
    }

    public function description(): string
    {
        return 'Se reconoce por el numero de documento; si ya existe, se actualizan sus datos.';
    }

    public function model(): string
    {
        return Customer::class;
    }

    public function columns(): array
    {
        return [
            'documento' => 'Numero de cedula o NIT, sin puntos',
            'tipo_documento' => implode(' / ', Customer::DOCUMENT_TYPES),
            'nombre' => 'Nombre completo',
            'telefono' => 'Opcional',
            'correo' => 'Necesario si va a entrar a la tienda en linea',
            'direccion' => 'Para los domicilios',
            'barrio' => 'Opcional',
            'cupo' => 'Cuanto se le fia, en pesos. 0 es sin credito',
            'plazo_dias' => 'Cuantos dias tiene para pagar',
        ];
    }

    public function required(): array
    {
        return ['documento', 'nombre'];
    }

    public function example(): array
    {
        return ['43112233', 'CC', 'Luz Marina Agudelo', '3123334455', 'luzma@correo.co', 'Calle 30 # 65-12', 'Laureles', '300000', '15'];
    }

    public function notes(): array
    {
        return [
            'Un cupo mayor que cero habilita el credito; en cero el cliente paga de contado.',
            'La importacion no crea contrasenas: el cliente las define al registrarse en la tienda.',
        ];
    }

    public function existing(array $row, int $companyId): ?Model
    {
        return Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('document_number', $this->text($row, 'documento'))
            ->first();
    }

    public function payload(array $row, int $companyId): ?array
    {
        $type = mb_strtoupper((string) $this->text($row, 'tipo_documento'));
        $limit = $this->integer($row, 'cupo');

        return [
            'document_number' => $this->text($row, 'documento'),
            'document_type' => in_array($type, Customer::DOCUMENT_TYPES, true) ? $type : 'CC',
            'name' => $this->text($row, 'nombre'),
            'phone' => $this->text($row, 'telefono'),
            'email' => $this->text($row, 'correo'),
            'address' => $this->text($row, 'direccion'),
            'neighborhood' => $this->text($row, 'barrio'),
            'credit_limit' => $limit,
            'credit_term_days' => $this->integer($row, 'plazo_dias'),
            // El plazo con esos dias, si la empresa lo tiene; si no, queda el numero solo.
            'payment_term_id' => $limit > 0 ? app(PaymentTermService::class)->forDays($companyId, (int) $this->integer($row, 'plazo_dias'))?->id : null,
            'credit_enabled' => $limit > 0,
            'is_active' => true,
        ];
    }

    /** Sin su fila de saldo el cliente no aparece en cartera. */
    public function created(Model $record): void
    {
        CustomerBalance::query()->firstOrCreate(
            ['customer_id' => $record->getKey()],
            ['balance' => 0, 'overdue_balance' => 0],
        );
    }
}
