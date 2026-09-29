<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCompanySettingsRequest;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Ajustes > Empresa: nombre, razon social, NIT y contacto de la empresa activa. El logo no va
 * aqui: la marca vive en Ajustes > Marca.
 */
class CompanySettingController extends Controller
{
    public function update(UpdateCompanySettingsRequest $request): RedirectResponse
    {
        $company = TenantContext::company();

        abort_if($company === null, 409, 'Elige una empresa para continuar.');

        $company->update($request->validated());
        TenantContext::forgetCompany();

        return back()->with('success', 'Los datos de la empresa quedaron guardados.');
    }
}
