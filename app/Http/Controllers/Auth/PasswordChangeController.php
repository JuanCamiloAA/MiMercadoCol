<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\HomeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cambio obligatorio de la contrasena temporal.
 */
class PasswordChangeController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/ChangePassword', [
            'forced' => (bool) $request->user()->password_change_required,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'current_password.required' => 'Escribe tu contrasena actual.',
            'current_password.current_password' => 'La contrasena actual no es correcta.',
            'password.confirmed' => 'La confirmacion no coincide.',
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'password_change_required' => false,
            'password_changed_at' => now(),
        ])->save();

        return redirect()->to(HomeResolver::for($request->user()))->with('success', 'Tu contrasena quedo actualizada.');
    }
}
