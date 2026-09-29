<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateReceiptSettingsRequest;
use App\Http\Requests\Settings\UpdateStoreReceiptSettingsRequest;
use App\Models\Store;
use App\Services\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes > Tiquete: el de la empresa y las excepciones de cada sede.
 */
class ReceiptSettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function update(UpdateReceiptSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany(TenantContext::requireCompanyId(), $request->values());

        return back()->with('success', 'El tiquete de la empresa quedó guardado.');
    }

    /**
     * Guarda lo propio de una sede; lo que no es propio vuelve a heredarse de la empresa.
     *
     * `{store}` pasa por `CompanyScope`: una sede de otra empresa da 404.
     */
    public function updateStore(UpdateStoreReceiptSettingsRequest $request, Store $store): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        DB::transaction(function () use ($request, $store, $companyId) {
            foreach ($request->values() as $key => $value) {
                $this->settings->setForStore($companyId, (int) $store->id, $key, $value);
            }
        });

        return back()->with('success', "El tiquete de la sede {$store->name} quedó guardado.");
    }
}
