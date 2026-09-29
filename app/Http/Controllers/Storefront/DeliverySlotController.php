<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\DeliverySlot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Las franjas en las que se puede pedir domicilio, con su cupo.
 */
class DeliverySlotController extends Controller
{
    use ResolvesStorefront;

    public function store(Request $request): RedirectResponse
    {
        $storefront = $this->storefront();

        $storefront->slots()->create($this->validated($request));

        return back()->with('success', 'Franja agregada.');
    }

    public function update(Request $request, DeliverySlot $slot): RedirectResponse
    {
        $this->assertOwned($slot->storefront_id);

        $slot->update($this->validated($request));

        return back(303);
    }

    public function destroy(DeliverySlot $slot): RedirectResponse
    {
        $this->assertOwned($slot->storefront_id);

        $slot->delete();

        return back()->with('success', 'Franja eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'is_active' => ['required', 'boolean'],
        ], [
            'ends_at.after' => 'La franja tiene que terminar después de empezar.',
            'capacity.min' => 'Una franja sin cupo no sirve de nada.',
        ]);
    }
}
