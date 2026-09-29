<?php

namespace App\Imports;

use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\MembershipPlan;
use App\Services\Platform\CompanyProvisioner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Empresas en bloque, para la carga masiva de plataforma.
 *
 * No crea filas sueltas: cada empresa nace por `CompanyProvisioner`, igual que desde la
 * pantalla de Empresas, con sede, bodega, caja, roles, plazos y administrador. La contraseña
 * no va en ningun archivo: el administrador recibe un correo para crearla.
 */
class CompanyImport extends MasterImport
{
    public function module(): string
    {
        return 'companies';
    }

    public function title(): string
    {
        return 'Empresas';
    }

    public function description(): string
    {
        return 'Crea empresas completas por NIT. Un NIT que ya existe se omite: las empresas no se actualizan por archivo.';
    }

    public function model(): string
    {
        return Company::class;
    }

    public function columns(): array
    {
        return [
            'nombre' => 'Nombre comercial',
            'nit' => 'NIT con digito de verificacion, unico en la plataforma',
            'razon_social' => 'Opcional; si falta, el nombre',
            'correo' => 'Correo de la empresa',
            'telefono' => 'Opcional',
            'ciudad' => 'Opcional',
            'plan' => 'Nombre o direccion del plan (basico, pro, cadena)',
            'ciclo' => 'Nombre o codigo del ciclo (monthly, Mensual, annual...)',
            'sede' => 'Nombre de la primera sede; si falta, «Principal»',
            'codigo_sede' => 'Codigo de la sede; si falta, PRI',
            'direccion_sede' => 'Opcional',
            'latitud' => 'Opcional, para el buscador de la landing',
            'longitud' => 'Opcional',
            'admin_nombre' => 'Nombre del administrador',
            'admin_apellido' => 'Opcional',
            'admin_correo' => 'Correo del administrador, unico en la plataforma',
        ];
    }

    public function required(): array
    {
        return ['nombre', 'nit', 'plan', 'ciclo', 'admin_nombre', 'admin_correo'];
    }

    public function example(): array
    {
        return [
            'Mercado La Esquina', '901234567-8', 'Mercado La Esquina S.A.S.', 'hola@laesquina.co', '6045551234', 'Itagüí',
            'pro', 'monthly', 'Centro', 'CEN', 'Calle 50 # 50-20', '6.1846', '-75.5991', 'Gloria', 'Mejía', 'gloria@laesquina.co',
        ];
    }

    public function notes(): array
    {
        return [
            'Cada empresa nace completa: sede con su bodega, una caja, roles, plazos de pago y la tienda en línea apagada.',
            'El administrador recibe un correo para crear su contraseña; ninguna contraseña viaja en el archivo.',
            'Los topes del plan se revisan: una fila que no quepa en su plan se omite con el motivo.',
        ];
    }

    public function allowsUpdate(): bool
    {
        return false;
    }

    public function existing(array $row, int $companyId): ?Model
    {
        return Company::query()->where('tax_id', $this->text($row, 'nit'))->first();
    }

    public function payload(array $row, int $companyId): ?array
    {
        $plan = $this->plan((string) $this->text($row, 'plan'));
        $cycle = $this->cycle((string) $this->text($row, 'ciclo'));
        $email = Str::lower((string) $this->text($row, 'admin_correo'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('el correo del administrador no es valido');
        }

        $latitude = $this->coordinate($row, 'latitud', 90);
        $longitude = $this->coordinate($row, 'longitud', 180);

        return [
            'company' => [
                'name' => $this->text($row, 'nombre'),
                'legal_name' => $this->text($row, 'razon_social'),
                'tax_id' => $this->text($row, 'nit'),
                'email' => $this->text($row, 'correo'),
                'phone' => $this->text($row, 'telefono'),
                'city' => $this->text($row, 'ciudad'),
            ],
            'store' => [
                'name' => $this->text($row, 'sede'),
                'code' => $this->text($row, 'codigo_sede'),
                'address' => $this->text($row, 'direccion_sede'),
                'city' => $this->text($row, 'ciudad'),
                'latitude' => $latitude !== null && $longitude !== null ? $latitude : null,
                'longitude' => $latitude !== null && $longitude !== null ? $longitude : null,
            ],
            'admin' => [
                'name' => $this->text($row, 'admin_nombre'),
                'last_name' => $this->text($row, 'admin_apellido'),
                'email' => $email,
            ],
            'plan_id' => $plan->id,
            'cycle_id' => $cycle->id,
        ];
    }

    public function store(array $payload, int $companyId): Model
    {
        $result = app(CompanyProvisioner::class)->provision($payload, auth('web')->user());

        return $result->admin;
    }

    /**
     * El correo para que el administrador cree su contraseña. Si el correo no esta configurado,
     * la empresa igual queda: el administrador puede pedir el enlace desde el ingreso.
     */
    public function created(Model $record): void
    {
        $email = (string) $record->getAttribute('email');
        $userId = $record->getKey();

        // Despues de confirmar: en la vista previa todo se deshace y no debe salir ningun correo.
        DB::afterCommit(function () use ($email, $userId) {
            try {
                Password::sendResetLink(['email' => $email]);
            } catch (Throwable $exception) {
                Log::warning('Carga masiva: no se pudo enviar el enlace de contraseña.', ['user_id' => $userId, 'error' => class_basename($exception)]);
            }
        });
    }

    public function rowLabel(array $row): string
    {
        return trim(($this->text($row, 'nombre') ?? '—').' · NIT '.($this->text($row, 'nit') ?? '—'));
    }

    protected function plan(string $value): MembershipPlan
    {
        $plan = MembershipPlan::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('slug', Str::slug($value))->orWhere('name', $value))
            ->first();

        if (! $plan) {
            throw new RuntimeException("el plan «{$value}» no existe o esta desactivado");
        }

        return $plan;
    }

    protected function cycle(string $value): BillingCycle
    {
        $cycle = BillingCycle::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('code', Str::lower($value))->orWhere('name', $value))
            ->first();

        if (! $cycle) {
            throw new RuntimeException("el ciclo «{$value}» no existe o esta desactivado");
        }

        return $cycle;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function coordinate(array $row, string $key, int $max): ?float
    {
        $value = $this->text($row, $key);

        if ($value === null) {
            return null;
        }

        $number = (float) str_replace(',', '.', $value);

        if (! is_numeric(str_replace(',', '.', $value)) || abs($number) > $max) {
            throw new RuntimeException("la {$key} no es valida");
        }

        return $number;
    }
}
