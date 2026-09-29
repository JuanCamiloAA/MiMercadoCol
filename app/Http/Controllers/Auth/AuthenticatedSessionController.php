<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AccessLog;
use App\Support\HomeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => true,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = $request->user();

        AccessLog::log(AccessLog::ACTION_LOGIN, $user->id, ['mode' => $request->input('login_mode')]);

        if ($user->password_change_required) {
            return redirect()->route('password.change.show');
        }

        // Cada rol entra a su pagina de inicio (Ajustes > Menu); `intended` sigue mandando
        // cuando la persona venia de un enlace.
        return redirect()->intended(HomeResolver::for($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $userId = $request->user()?->id;

        AccessLog::log(AccessLog::ACTION_LOGOUT, $userId);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
