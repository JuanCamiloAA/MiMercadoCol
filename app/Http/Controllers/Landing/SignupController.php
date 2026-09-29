<?php

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landing\ContactRequest;
use App\Http\Requests\Landing\SignupRequest;
use App\Models\BillingCycle;
use App\Models\CompanySignup;
use App\Models\MembershipPlan;
use App\Models\PlatformLead;
use App\Models\User;
use App\Notifications\Platform\NewPlatformLead;
use App\Services\Landing\LandingRenderer;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Platform\CompanyProvisioner;
use App\Services\Platform\SignupService;
use App\Services\PlatformSettingsService;
use App\Support\HomeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Contratar en linea: con prueba gratis se crea la cuenta en el acto; si no, se paga por el
 * checkout de la pasarela y la cuenta nace cuando el pago se confirma. O «que me contacten».
 */
class SignupController extends Controller
{
    /** La contratacion que abrio este navegador: solo el puede entrar solo al quedar pagada. */
    public const SESSION_KEY = 'signup_reference';

    public function __construct(
        protected SignupService $signups,
        protected LandingRenderer $renderer,
        protected PaymentGatewayResolver $gateways,
        protected PlatformSettingsService $platform,
    ) {}

    public function create(Request $request): Response
    {
        $catalogue = $this->renderer->plans();
        $plan = collect($catalogue['plans'])->firstWhere('slug', (string) $request->query('plan'))
            ?? collect($catalogue['plans'])->firstWhere('is_featured', true)
            ?? ($catalogue['plans'][0] ?? null);
        $cycle = collect($catalogue['cycles'])->firstWhere('code', (string) $request->query('ciclo'))
            ?? ($catalogue['cycles'][0] ?? null);

        return Inertia::render('Signup/Create', [
            ...app(LandingController::class)->pageProps($request),
            'plans' => $catalogue['plans'],
            'cycles' => $catalogue['cycles'],
            'selectedPlanId' => $plan['id'] ?? null,
            'selectedCycleId' => $cycle['id'] ?? null,
            // Sin pasarela no se ofrece pagar en linea: solo «que me contacten» (y la prueba gratis).
            'gatewayEnabled' => $this->gateways->wompi()->isUsable(),
        ]);
    }

    public function store(SignupRequest $request): RedirectResponse|HttpResponse
    {
        $data = $request->validated();
        $plan = MembershipPlan::query()->findOrFail($data['membership_plan_id']);
        $cycle = BillingCycle::query()->findOrFail($data['billing_cycle_id']);

        // Con prueba gratis no se pasa por la pasarela: la cuenta nace ya, en prueba.
        if ((int) $plan->trial_days > 0) {
            $result = app(CompanyProvisioner::class)->provision([
                'company' => [
                    'name' => $data['company_name'],
                    'tax_id' => $data['tax_id'],
                    'email' => $data['company_email'],
                    'phone' => $data['company_phone'] ?? null,
                    'city' => $data['city'],
                ],
                'store' => ['city' => $data['city']],
                'admin' => [
                    'name' => $data['admin_name'],
                    'last_name' => $data['admin_last_name'] ?? null,
                    'email' => $data['admin_email'],
                    'document' => $data['admin_document'] ?? null,
                    'password_hash' => Hash::make($data['admin_password']),
                ],
                'plan_id' => $plan->id,
                'cycle_id' => $cycle->id,
            ]);

            Auth::guard('web')->login($result->admin);
            $request->session()->regenerate();

            return redirect()->to(HomeResolver::for($result->admin))
                ->with('success', "Bienvenido. {$result->company->name} tiene {$plan->trial_days} días de prueba gratis.");
        }

        $wompi = $this->gateways->wompi();

        if (! $wompi->isUsable()) {
            return back()->withErrors(['membership_plan_id' => 'El pago en línea no está disponible ahora. Déjanos tus datos y te contactamos.']);
        }

        $signup = $this->signups->createPending($data, $plan, $cycle);
        $request->session()->put(self::SESSION_KEY, $signup->reference);

        $url = $wompi->checkoutUrl(
            $signup->reference,
            $signup->amount * 100,
            $signup->currency,
            route('signup.status', $signup->reference),
            [
                'email' => $signup->admin_email,
                'full-name' => trim($signup->admin_name.' '.$signup->admin_last_name),
                'phone-number' => $signup->company_phone,
            ],
            $signup->expires_at->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        );

        return Inertia::location($url);
    }

    /**
     * La vuelta del checkout. Si Wompi trae `?id=`, se relee la transaccion y se liquida (el
     * evento puede llegar despues). La pagina consulta cada pocos segundos hasta que la cuenta
     * este lista; entonces entra sola, pero solo en el navegador que contrato.
     */
    public function status(Request $request, string $reference): Response|RedirectResponse|JsonResponse
    {
        $signup = CompanySignup::query()->where('reference', $reference)->firstOrFail();
        $transactionId = (string) $request->query('id', '');

        if ($signup->status === CompanySignup::STATUS_PENDING && preg_match('/^[A-Za-z0-9\-]{1,80}$/', $transactionId)) {
            $transaction = $this->gateways->wompi()->fetchTransaction($transactionId);

            if (is_array($transaction) && ($transaction['reference'] ?? null) === $reference) {
                $signup = $this->signups->settle($reference, $transaction) ?? $signup;
            }
        }

        $signup->refresh();
        $ready = $signup->status === CompanySignup::STATUS_PAID && $signup->company_id !== null;
        $mine = $request->session()->get(self::SESSION_KEY) === $reference;

        if ($ready && $mine && $request->boolean('entrar')) {
            $admin = User::query()->where('company_id', $signup->company_id)->where('email', $signup->admin_email)->first();

            if ($admin) {
                $request->session()->forget(self::SESSION_KEY);
                Auth::guard('web')->login($admin);
                $request->session()->regenerate();

                return redirect()->to(HomeResolver::for($admin))->with('success', 'Tu cuenta quedó lista. ¡Bienvenido!');
            }
        }

        $payload = [
            'reference' => $signup->reference,
            'status' => $signup->status,
            'ready' => $ready,
            'can_enter' => $ready && $mine,
            'company_name' => $signup->company_name,
            'amount' => $signup->amount,
            'failure_reason' => $signup->status === CompanySignup::STATUS_FAILED ? $signup->failure_reason : null,
        ];

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render('Signup/Status', [
            ...app(LandingController::class)->pageProps($request),
            'signup' => $payload,
        ]);
    }

    public function contact(ContactRequest $request): RedirectResponse
    {
        // Un robot llena el campo trampa: se le responde igual, sin guardar nada.
        if ($request->isBot()) {
            return back()->with('success', 'Recibimos tus datos. Te contactamos pronto.');
        }

        $data = $request->validated();
        unset($data['website']);

        $lead = PlatformLead::query()->create([
            ...$data,
            'source' => $data['source'] ?? PlatformLead::SOURCE_CONTACT,
            'status' => PlatformLead::STATUS_NEW,
        ]);

        $to = $this->platform->get('platform.leads_notify_email');

        if (is_string($to) && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            try {
                Notification::route('mail', $to)->notify(new NewPlatformLead($lead));
            } catch (Throwable $exception) {
                Log::warning('Solicitud: no se pudo avisar por correo.', ['lead_id' => $lead->id, 'error' => class_basename($exception)]);
            }
        }

        return back()->with('success', 'Recibimos tus datos. Te contactamos pronto.');
    }
}
