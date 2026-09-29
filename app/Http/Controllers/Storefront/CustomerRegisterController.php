<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerActivation;
use App\Models\CustomerBalance;
use App\Notifications\CustomerActivationCode;
use App\Support\StorefrontContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Registro de clientes de la tienda en linea, en la empresa de la sede elegida.
 *
 * Un documento que ya existe en esa empresa porque se creo en la caja no recibe contraseña asi
 * nomas: seria tomarse la cuenta (y el credito) de otra persona sabiendo solo su cedula. Se
 * manda un codigo al correo de su ficha y la cuenta se activa al confirmarlo.
 */
class CustomerRegisterController extends Controller
{
    public const ACTIVATION_KEY = 'storefront.activation_id';

    public function create(): Response
    {
        return Inertia::render('Storefront/Register', [
            'store' => StorefrontContext::store()?->only(['id', 'name']),
            'documentTypes' => Customer::DOCUMENT_TYPES,
            'termsUrl' => route('legal.terms'),
            'privacyUrl' => route('legal.privacy'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = StorefrontContext::requireStore();
        $data = $request->validate([
            'document_type' => ['required', Rule::in(Customer::DOCUMENT_TYPES)],
            'document_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:160'],
            'neighborhood' => ['nullable', 'string', 'max:80'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
        ], [
            'document_number.required' => 'Escribe tu documento.',
            'document_number.regex' => 'El documento solo lleva números y letras.',
            'name.required' => 'Escribe tu nombre.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'El correo no es válido.',
            'phone.required' => 'Escribe tu celular.',
            'password.required' => 'Elige una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'accept_terms.accepted' => 'Acepta los términos y el tratamiento de tus datos para registrarte.',
        ]);

        $existing = Customer::query()->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->where('document_number', trim($data['document_number']))
            ->first();

        if ($existing?->is_online_user) {
            throw ValidationException::withMessages(['document_number' => 'Ya tienes cuenta en esta tienda. Ingresa con tu documento.']);
        }

        if ($existing) {
            return $this->startActivation($request, $existing, $data, $store->id);
        }

        $customer = Customer::query()->withoutGlobalScopes()->create([
            'company_id' => $store->company_id,
            'preferred_store_id' => $store->id,
            'document_type' => $data['document_type'],
            'document_number' => trim($data['document_number']),
            'name' => trim($data['name']),
            'email' => Str::lower(trim($data['email'])),
            'phone' => $data['phone'],
            'address' => $data['address'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'password' => Hash::make($data['password']),
            'is_online_user' => true,
            'accepted_terms_at' => now(),
            'credit_limit' => 0,
            'credit_term_days' => 0,
            'credit_enabled' => false,
            'is_active' => true,
        ]);

        CustomerBalance::query()->firstOrCreate(['customer_id' => $customer->id], ['balance' => 0, 'overdue_balance' => 0]);

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->intended(route('storefront.home'))->with('success', '¡Listo! Ya tienes cuenta en '.$store->name.'.');
    }

    public function activation(Request $request): Response|RedirectResponse
    {
        $activation = $this->pendingActivation($request);

        if (! $activation) {
            return redirect()->route('storefront.register');
        }

        return Inertia::render('Storefront/Activate', [
            'store' => StorefrontContext::store()?->only(['id', 'name']),
            'maskedEmail' => $this->mask((string) $activation->customer->email),
            'expiresAt' => $activation->expires_at->toIso8601String(),
        ]);
    }

    public function activate(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']], ['code.required' => 'Escribe el código.', 'code.digits' => 'El código tiene 6 números.']);

        $activation = $this->pendingActivation($request);

        if (! $activation || ! $activation->isUsable()) {
            throw ValidationException::withMessages(['code' => 'El código venció o ya se usó. Pide uno nuevo.']);
        }

        if (! Hash::check((string) $request->input('code'), $activation->code_hash)) {
            $activation->increment('attempts');

            throw ValidationException::withMessages([
                'code' => $activation->attempts >= CustomerActivation::MAX_ATTEMPTS
                    ? 'Demasiados intentos. Pide un código nuevo.'
                    : 'El código no coincide.',
            ]);
        }

        $customer = $activation->customer;
        $profile = (array) ($activation->profile ?? []);

        // Lo que ya estaba en la ficha de la caja se respeta; lo que faltaba se completa.
        $customer->forceFill([
            'is_online_user' => true,
            'password' => $activation->password_hash,
            'accepted_terms_at' => now(),
            'preferred_store_id' => $activation->store_id,
            'phone' => $customer->phone ?: ($profile['phone'] ?? null),
            'address' => $customer->address ?: ($profile['address'] ?? null),
            'neighborhood' => $customer->neighborhood ?: ($profile['neighborhood'] ?? null),
        ])->save();

        $activation->forceFill(['consumed_at' => now()])->save();
        $request->session()->forget(self::ACTIVATION_KEY);

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->intended(route('storefront.home'))->with('success', 'Tu cuenta quedó activa.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $activation = $this->pendingActivation($request);

        if (! $activation) {
            return redirect()->route('storefront.register');
        }

        $this->issueCode($activation);

        return back()->with('success', 'Te enviamos un código nuevo.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function startActivation(Request $request, Customer $customer, array $data, int $storeId): RedirectResponse
    {
        if (! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'document_number' => 'Ese documento ya es cliente de la tienda, pero sin correo registrado. Acércate a la sede para activar tu cuenta en línea.',
            ]);
        }

        // Una solicitud a la vez: las anteriores dejan de servir.
        CustomerActivation::query()->where('customer_id', $customer->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $activation = CustomerActivation::query()->create([
            'customer_id' => $customer->id,
            'store_id' => $storeId,
            'code_hash' => '',
            'password_hash' => Hash::make($data['password']),
            'profile' => array_filter([
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'neighborhood' => $data['neighborhood'] ?? null,
            ]),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(CustomerActivation::TTL_MINUTES),
        ]);

        $this->issueCode($activation);
        $request->session()->put(self::ACTIVATION_KEY, $activation->id);

        return redirect()->route('storefront.register.activation')
            ->with('info', 'Ya eras cliente de la tienda. Te enviamos un código al correo de tu ficha para activar la cuenta.');
    }

    /**
     * Un codigo nuevo, guardado con hash, al correo de la ficha (no al del formulario).
     */
    protected function issueCode(CustomerActivation $activation): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $activation->forceFill([
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(CustomerActivation::TTL_MINUTES),
        ])->save();

        try {
            Notification::route('mail', $activation->customer->email)
                ->notify(new CustomerActivationCode($code, StorefrontContext::store()?->name ?? 'la tienda', CustomerActivation::TTL_MINUTES));
        } catch (Throwable $exception) {
            Log::warning('Registro: no se pudo enviar el codigo de activacion.', ['customer_id' => $activation->customer_id, 'error' => class_basename($exception)]);
        }
    }

    protected function pendingActivation(Request $request): ?CustomerActivation
    {
        $id = $request->session()->get(self::ACTIVATION_KEY);
        $company = StorefrontContext::company();

        if (! $id || ! $company) {
            return null;
        }

        $activation = CustomerActivation::query()->with('customer')->whereNull('consumed_at')->find($id);

        // La activacion es de un cliente de la empresa de esta tienda, o no es de aqui.
        return $activation && (int) $activation->customer?->company_id === (int) $company->id ? $activation : null;
    }

    protected function mask(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 2).str_repeat('•', max(1, mb_strlen($user) - 2)).'@'.$domain;
    }
}
