<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePosSettingsRequest;
use App\Services\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Ajustes > Caja: tema, tamano, disposicion, riel, atajos y sonidos.
 */
class PosSettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function update(UpdatePosSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany(TenantContext::requireCompanyId(), $request->values());

        return back()->with('success', 'La caja quedó guardada.');
    }
}
