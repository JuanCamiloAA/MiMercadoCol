<?php

namespace App\Services\Membership;

use App\Models\Company;
use App\Models\Register;
use App\Models\Store;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Los topes del plan: cuantas sedes, usuarios y cajas puede tener la empresa.
 *
 * Se revisa en cada alta (controladores, importaciones y aprovisionamiento). Sin plan asignado
 * —empresas de antes de las membresias— no hay tope.
 */
class MembershipLimiter
{
    public const RESOURCES = ['stores', 'users', 'registers'];

    /** Como se nombra cada recurso en el mensaje: singular y plural. */
    protected const NAMES = [
        'stores' => ['sede', 'sedes'],
        'users' => ['usuario', 'usuarios'],
        'registers' => ['caja', 'cajas'],
    ];

    /**
     * Cuantos hay hoy de un recurso. Se cuentan los activos: desactivar una sede libera cupo.
     */
    public function used(Company $company, string $resource): int
    {
        return match ($resource) {
            'stores' => Store::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true)->count(),
            'users' => User::query()->where('company_id', $company->id)->where('is_active', true)->count(),
            'registers' => Register::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true)->count(),
            default => 0,
        };
    }

    public function limit(Company $company, string $resource): ?int
    {
        return $company->plan?->limitFor($resource);
    }

    /**
     * Si cabe uno mas (o `$adding` mas).
     */
    public function canAdd(Company $company, string $resource, int $adding = 1): bool
    {
        $limit = $this->limit($company, $resource);

        return $limit === null || $this->used($company, $resource) + $adding <= $limit;
    }

    /**
     * @throws ValidationException
     */
    public function assertCanAdd(Company $company, string $resource, string $field = 'plan', int $adding = 1): void
    {
        if ($this->canAdd($company, $resource, $adding)) {
            return;
        }

        throw ValidationException::withMessages([$field => $this->message($company, $resource)]);
    }

    public function message(Company $company, string $resource): string
    {
        $limit = (int) $this->limit($company, $resource);
        [$singular, $plural] = self::NAMES[$resource] ?? [$resource, $resource];

        return "Tu plan permite {$limit} ".($limit === 1 ? $singular : $plural).'. Cámbialo en Ajustes > Membresía.';
    }

    /**
     * Uso contra el tope de cada recurso, para pintar las barras.
     *
     * @return array<string, array{used: int, limit: ?int}>
     */
    public function usage(Company $company): array
    {
        $usage = [];

        foreach (self::RESOURCES as $resource) {
            $usage[$resource] = ['used' => $this->used($company, $resource), 'limit' => $this->limit($company, $resource)];
        }

        return $usage;
    }
}
