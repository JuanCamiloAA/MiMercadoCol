<?php

namespace App\Http\Controllers;

use App\Support\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cambio de sede activa desde la barra superior.
 */
class StoreSwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        if (! StoreContext::setActiveStore((int) $validated['store_id'])) {
            return back()->with('error', 'No tienes acceso a esa sede.');
        }

        return back()->with('success', 'Cambiaste de sede.');
    }
}
