<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePosRulesSettingsRequest;
use App\Services\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Ajustes > Reglas de caja: descuento maximo y redondeo del efectivo.
 */
class PosRulesSettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function update(UpdatePosRulesSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany(TenantContext::requireCompanyId(), $request->values());

        return back()->with('success', 'Las reglas de caja quedaron guardadas.');
    }
}
