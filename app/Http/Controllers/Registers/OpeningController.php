<?php

namespace App\Http\Controllers\Registers;

use App\Http\Controllers\Controller;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Store;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OpeningController extends Controller
{
    /** Denominaciones del peso colombiano en circulacion, de mayor a menor. */
    public const DENOMINATIONS = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50];

    public function __construct(protected RegisterSessionService $sessions) {}

    public function create(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();
        $store = Store::query()->findOrFail($storeId);

        $registers = Register::query()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->with(['openSession.user:id,name,last_name'])
            ->orderBy('code')
            ->get()
            ->map(fn (Register $register) => [
                'id' => $register->id,
                'name' => $register->name,
                'code' => $register->code,
                'is_busy' => $register->openSession !== null,
                'busy_by' => $register->openSession?->user?->full_name,
                'opened_at' => $register->openSession?->opened_at?->toIso8601String(),
            ])
            ->values();

        $cashiers = User::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereHas('stores', fn ($query) => $query->where('stores.id', $storeId))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->can('registers.open.create'))
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->full_name,
                'has_pin' => $user->pos_pin !== null,
                'uses_session' => false,
            ])
            ->values();

        // El super usuario no es empleado de ninguna empresa ni tiene PIN: abre a su nombre con
        // la sesion con la que ya entro. A nombre de otro cajero sigue haciendo falta el PIN de ese.
        if ($request->user()->isSuperAdmin()) {
            $cashiers->prepend([
                'id' => $request->user()->id,
                'name' => $request->user()->full_name.' (tú)',
                'has_pin' => true,
                'uses_session' => true,
            ]);
        }

        $lastClosed = RegisterSession::query()
            ->where('store_id', $storeId)
            ->where('status', RegisterSession::STATUS_CLOSED)
            ->with('register:id,name')
            ->orderByDesc('closed_at')
            ->first();

        return Inertia::render('Pos/Opening', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'suggested_cash_base' => $store->suggested_cash_base,
            ],
            'registers' => $registers,
            'cashiers' => $cashiers,
            'denominations' => self::DENOMINATIONS,
            'currentUserId' => $request->user()->id,
            'lastClosing' => $lastClosed ? [
                'register' => $lastClosed->register?->name,
                'difference' => $lastClosed->difference,
                'closed_at' => $lastClosed->closed_at?->toIso8601String(),
                // El cajero necesita saber si ese descuadre ya lo resolvio alguien.
                'resolved' => $lastClosed->isResolved(),
                'resolution_label' => $lastClosed->resolutionLabel(),
                'can_review' => $request->user()->can('registers.differences.view'),
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();
        $actor = $request->user();
        $superAdminSelf = $actor->isSuperAdmin() && (int) $request->input('user_id') === $actor->id;

        $validated = $request->validate([
            'register_id' => ['required', 'integer', Rule::exists('registers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('store_id', $storeId))],
            'user_id' => ['required', 'integer', $superAdminSelf
                ? Rule::exists('users', 'id')
                : Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'pin' => $superAdminSelf ? ['nullable'] : ['required', 'digits:4'],
            'breakdown' => ['array'],
            'breakdown.*' => ['integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:160'],
            'checks' => ['array'],
        ], [
            'register_id.required' => 'Elige la caja.',
            'user_id.required' => 'Elige el cajero.',
            'pin.required' => 'Escribe el PIN del cajero.',
            'pin.digits' => 'El PIN tiene 4 digitos.',
        ]);

        /** @var User $cashier */
        $cashier = User::query()->findOrFail($validated['user_id']);

        if (! $superAdminSelf && (! $cashier->pos_pin || ! Hash::check((string) $validated['pin'], $cashier->pos_pin))) {
            throw ValidationException::withMessages(['pin' => ['El PIN no coincide.']]);
        }

        if (! $cashier->can('registers.open.create')) {
            throw ValidationException::withMessages(['user_id' => ['Esa persona no tiene permiso para abrir caja.']]);
        }

        /** @var Register $register */
        $register = Register::query()->findOrFail($validated['register_id']);

        $breakdown = array_filter($validated['breakdown'] ?? [], fn ($count) => (int) $count > 0);
        $openingAmount = 0;

        foreach ($breakdown as $denomination => $count) {
            $openingAmount += (int) $denomination * (int) $count;
        }

        $this->sessions->open($register, $cashier, $openingAmount, $breakdown, $validated['note'] ?? null);

        return redirect()->route('pos.index')->with('success', 'Caja abierta. Buen turno.');
    }
}
