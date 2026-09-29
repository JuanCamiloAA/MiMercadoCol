<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreBrandImageRequest;
use App\Services\BrandAssetService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Isotipo y logos de Ajustes > Marca. Cada imagen se sube en el acto, sin esperar al boton
 * Guardar, y se quita igual.
 */
class BrandImageController extends Controller
{
    /** Como se nombra cada lugar en los mensajes. */
    protected const NAMES = [
        'mark' => 'El isotipo',
        'logo_light' => 'El logo para fondo claro',
        'logo_dark' => 'El logo para fondo oscuro',
    ];

    public function __construct(protected BrandAssetService $assets) {}

    public function store(StoreBrandImageRequest $request, string $slot): RedirectResponse
    {
        $this->assets->store(TenantContext::requireCompanyId(), $slot, $request->file('file'));

        return back()->with('success', self::NAMES[$slot].' quedó guardado.');
    }

    public function destroy(string $slot): RedirectResponse
    {
        $this->assets->destroy(TenantContext::requireCompanyId(), $slot);

        return back()->with('success', self::NAMES[$slot].' se quitó.');
    }
}
