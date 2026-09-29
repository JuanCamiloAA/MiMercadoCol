<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAppearanceSettingsRequest;
use App\Services\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Ajustes > Apariencia: tipografia, esquinas, densidad, escala, tema y menu del panel.
 */
class AppearanceSettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function update(UpdateAppearanceSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany(TenantContext::requireCompanyId(), $request->values());

        return back()->with('success', 'La apariencia quedó guardada.');
    }
}
