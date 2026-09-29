<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCreditSettingsRequest;
use App\Services\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Ajustes > Credito: a cuantos dias de mora se bloquea el credito.
 */
class CreditSettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function update(UpdateCreditSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany(TenantContext::requireCompanyId(), $request->values());

        return back()->with('success', 'El ajuste de crédito quedó guardado.');
    }
}
