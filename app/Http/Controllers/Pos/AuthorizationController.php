<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Autorizacion de supervisor con PIN.
 *
 * Hay cosas que el cajero no decide solo —un descuento por encima del tope, una venta a
 * credito— pero que tampoco justifican cerrar la sesion y volver a entrar con otra cuenta.
 * El supervisor marca su PIN en la misma pantalla y la venta sigue, dejando constancia de
 * quien autorizo.
 */
class AuthorizationController extends Controller
{
    /** Cuanto vale la autorizacion antes de volver a pedirla, en segundos. */
    public const TTL = 180;

    /** @var array<string, string> */
    public const ACTIONS = [
        'discount' => 'pos.sale.discount',
        'credit' => 'pos.sale.credit',
        'service_remove' => 'pos.sale.service_remove',
        'service_reduce' => 'pos.sale.service_reduce',
        'service_increase' => 'pos.sale.service_increase',
        'service_add' => 'pos.sale.service_add',
        // Devolver fuera del plazo de Ajustes.
        'return_window' => 'sales.returns.approve',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'pin' => ['required', 'digits:4'],
        ], [
            'pin.required' => 'Escribe el PIN.',
            'pin.digits' => 'El PIN tiene 4 digitos.',
        ]);

        $key = 'pos-authorization:'.$request->user()->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'authorized' => false,
                'message' => 'Demasiados intentos. Espera '.RateLimiter::availableIn($key).' segundos.',
            ], 429);
        }

        $permission = self::ACTIONS[$validated['action']];

        $approver = User::query()
            ->where('company_id', TenantContext::requireCompanyId())
            ->where('is_active', true)
            ->whereNotNull('pos_pin')
            ->get()
            ->first(fn (User $user) => Hash::check($validated['pin'], $user->pos_pin) && $user->can($permission));

        if (! $approver) {
            RateLimiter::hit($key, 300);

            return response()->json([
                'authorized' => false,
                'message' => 'Ese PIN no corresponde a nadie que pueda autorizarlo.',
            ], 422);
        }

        RateLimiter::clear($key);

        $request->session()->put($this->sessionKey($validated['action']), [
            'user_id' => $approver->id,
            'expires_at' => now()->addSeconds(self::TTL)->timestamp,
        ]);

        AccessLog::log('pos_authorization', $request->user()->id, [
            'action' => $validated['action'],
            'approved_by' => $approver->id,
        ]);

        return response()->json([
            'authorized' => true,
            'by' => $approver->full_name,
            'expires_in' => self::TTL,
        ]);
    }

    /**
     * Autorizacion vigente para una accion, si la hay.
     */
    public static function approverFor(Request $request, string $action): ?User
    {
        $stored = $request->session()->get(self::sessionKeyFor($action));

        if (! is_array($stored) || ($stored['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        return User::query()->find($stored['user_id']);
    }

    public static function forget(Request $request, string $action): void
    {
        $request->session()->forget(self::sessionKeyFor($action));
    }

    protected function sessionKey(string $action): string
    {
        return self::sessionKeyFor($action);
    }

    protected static function sessionKeyFor(string $action): string
    {
        return "pos_authorization.{$action}";
    }
}
