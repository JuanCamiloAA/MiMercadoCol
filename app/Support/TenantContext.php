<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Empresa activa de la peticion.
 *
 * Un usuario de empresa siempre trabaja sobre la suya. El super administrador puede elegir
 * una en la barra superior: mientras no elija ninguna ve el consolidado.
 */
final class TenantContext
{
    public const SESSION_KEY = 'super_admin_active_company_id';

    public static function superAdminSelectedCompanyId(): ?int
    {
        $user = Auth::guard('web')->user();

        if (! $user?->isSuperAdmin()) {
            return null;
        }

        $raw = session(self::SESSION_KEY);

        return ($raw === null || $raw === '') ? null : (int) $raw;
    }

    public static function isConsolidatedSuperAdmin(?User $user = null): bool
    {
        $user = $user ?? Auth::guard('web')->user();

        return (bool) $user?->isSuperAdmin() && self::superAdminSelectedCompanyId() === null;
    }

    public static function activeCompanyId(?User $user = null): ?int
    {
        $user = $user ?? Auth::guard('web')->user();

        if (! $user) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return self::superAdminSelectedCompanyId();
        }

        return $user->company_id ? (int) $user->company_id : null;
    }

    /**
     * La empresa activa como modelo, o null en consolidado.
     *
     * Se lee siempre de aqui y nunca de `$user->company`: el super usuario no tiene empresa
     * propia, la elige en el selector. Se guarda en los atributos de la peticion, que es su
     * vida util: la siguiente peticion vuelve a leerla.
     */
    public static function company(?User $user = null): ?Company
    {
        $id = self::activeCompanyId($user);

        if ($id === null) {
            return null;
        }

        $request = request();
        $cached = $request->attributes->get('tenant.company');

        if ($cached instanceof Company && $cached->id === $id) {
            return $cached;
        }

        $company = Company::query()->find($id);
        $request->attributes->set('tenant.company', $company);

        return $company;
    }

    /**
     * Olvida la empresa guardada en la peticion (despues de editarla).
     */
    public static function forgetCompany(): void
    {
        request()->attributes->remove('tenant.company');
    }

    /**
     * company_id obligatorio para altas y modificaciones, que nunca pueden ser globales.
     *
     * @throws ValidationException
     */
    public static function requireCompanyId(?User $user = null): int
    {
        $user = $user ?? Auth::guard('web')->user();
        $id = self::activeCompanyId($user);

        if ($id !== null) {
            return $id;
        }

        throw ValidationException::withMessages([
            'company_id' => ['Selecciona una empresa en la parte superior para registrar datos.'],
        ]);
    }
}
