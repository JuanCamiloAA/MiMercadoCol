<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBrandSettingsRequest;
use App\Services\BrandAssetService;
use App\Services\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Ajustes > Marca: nombre, subtitulo y color. Las imagenes van por `BrandImageController`.
 */
class BrandSettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function update(UpdateBrandSettingsRequest $request, BrandAssetService $assets): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $before = $this->settings->get($companyId, 'brand.accent');

        $this->settings->setMany($companyId, $request->values());

        // El icono de iOS lleva de fondo el color de la marca.
        if ($this->settings->get($companyId, 'brand.accent') !== $before) {
            $assets->refreshTouchIcon($companyId);
        }

        return back()->with('success', 'La marca quedó guardada.');
    }
}
