<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNavigationSettingsRequest;
use App\Services\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Ajustes > Menu: orden, nombres y lo oculto del menu lateral, y el inicio por rol.
 */
class NavigationSettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function update(UpdateNavigationSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany(TenantContext::requireCompanyId(), $request->values());

        return back()->with('success', 'El menú quedó guardado.');
    }
}
