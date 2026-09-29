<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Los barrios a los que llega el domicilio, con su tarifa y su tiempo.
 */
class DeliveryZoneController extends Controller
{
    use ResolvesStorefront;

    public function store(Request $request): RedirectResponse
    {
        $storefront = $this->storefront();
        $validated = $this->validated($request);

        $storefront->zones()->create(array_merge($validated, [
            'sort_order' => (int) $storefront->zones()->max('sort_order') + 1,
        ]));

        return back()->with('success', 'Zona agregada.');
    }

    public function update(Request $request, DeliveryZone $zone): RedirectResponse
    {
        $this->assertOwned($zone->storefront_id);

        $zone->update($this->validated($request));

        return back(303);
    }

    public function destroy(DeliveryZone $zone): RedirectResponse
    {
        $this->assertOwned($zone->storefront_id);

        $zone->delete();

        return back()->with('success', 'Zona eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'fee' => ['required', 'integer', 'min:0'],
            'eta_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'is_active' => ['required', 'boolean'],
        ], [
            'name.required' => 'Escribe el nombre del barrio o la zona.',
            'fee.min' => 'La tarifa no puede ser negativa.',
        ]);
    }
}
