<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCatalogSettingsRequest;
use App\Services\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Ajustes > Catalogo: cuantas fotos se le montan a cada articulo, articulo principal o servicio.
 */
class CatalogSettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function update(UpdateCatalogSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany(TenantContext::requireCompanyId(), $request->values());

        return back()->with('success', 'El ajuste del catálogo quedó guardado.');
    }
}
