<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordResetController extends Controller
{
    public function requestForm(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => 'El correo es obligatorio.', 'email.email' => 'El correo no es valido.']
        );

        try {
            $status = Password::sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            Log::warning('Contrasena: no se pudo enviar el enlace.', ['error' => class_basename($exception)]);
            $status = null;
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => 'No pudimos enviar el enlace a ese correo.',
            ]);
        }

        return back()->with('status', 'Te enviamos un enlace para restablecer la contrasena.');
    }

    public function resetForm(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->string('email')->toString(),
            'token' => $token,
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'password.confirmed' => 'La confirmacion no coincide.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->string('password')),
                    'remember_token' => Str::random(60),
                    'password_change_required' => false,
                    'password_changed_at' => now(),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'El enlace ya no es valido. Pide uno nuevo.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Tu contrasena quedo actualizada. Ya puedes entrar.');
    }
}
