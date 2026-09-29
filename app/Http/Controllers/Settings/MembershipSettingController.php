<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PayMembershipRequest;
use App\Http\Requests\Settings\ScheduleMembershipChangeRequest;
use App\Http\Requests\Settings\ToggleAutoRenewRequest;
use App\Http\Requests\Settings\UpdatePaymentCardRequest;
use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\MembershipCharge;
use App\Models\MembershipPlan;
use App\Services\Membership\MembershipBillingService;
use App\Services\Membership\MembershipService;
use App\Services\PlatformSettingsService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ajustes > Membresia: la tarjeta, la renovacion automatica, pagar y cambiar de plan.
 *
 * Estas rutas siguen abiertas con la empresa suspendida (`EnsureMembershipActive`): son el
 * unico camino para volver a estar al dia.
 */
class MembershipSettingController extends Controller
{
    public function __construct(
        protected MembershipBillingService $billing,
        protected MembershipService $membership,
    ) {}

    /**
     * Lo que el formulario de tarjeta necesita para tokenizar en el navegador: la llave publica,
     * la direccion de la pasarela y los tokens de aceptacion con sus enlaces. Se pide al abrir
     * el formulario y no con la pantalla, para no consultar la pasarela en cada visita.
     */
    public function acceptance(): JsonResponse
    {
        $wompi = $this->billing->gateway();

        if (! $wompi->isUsable()) {
            return response()->json(['message' => 'El pago en línea no está disponible.'], 409);
        }

        $tokens = $wompi->acceptanceTokens();

        if ($tokens === null) {
            return response()->json(['message' => 'No pudimos comunicarnos con la pasarela. Intenta en un momento.'], 502);
        }

        return response()->json([
            'public_key' => $wompi->publicKey(),
            'api_base' => $wompi->apiBase(),
            ...$tokens,
        ]);
    }

    public function updateCard(UpdatePaymentCardRequest $request): RedirectResponse
    {
        $company = $this->company();
        $data = $request->validated();

        $card = $this->billing->saveCard($company, $data['token'], $data['acceptance_token'], $data['personal_auth_token'], $data['email'], $request->user());

        return back()->with('success', 'Tarjeta guardada'.($card->last4 ? " (•••• {$card->last4})" : '').'.');
    }

    public function autoRenew(ToggleAutoRenewRequest $request): RedirectResponse
    {
        $company = $this->company();
        $enabled = $request->boolean('auto_renew');

        // Sin tarjeta no se enciende: el error se dice ahora, no el dia del cobro.
        if ($enabled && ! $this->billing->usableCard($company)) {
            throw ValidationException::withMessages(['auto_renew' => 'Agrega una tarjeta antes de encender la renovación automática.']);
        }

        $this->membership->setAutoRenew($company, $enabled, $request->user());

        return back()->with('success', $enabled ? 'La renovación automática quedó encendida.' : 'La renovación automática quedó apagada.');
    }

    public function pay(PayMembershipRequest $request): Response
    {
        $company = $this->company();

        if ($request->validated('method') === MembershipCharge::METHOD_CHECKOUT) {
            $checkout = $this->billing->startCheckout($company, $request->user(), route('settings.membership.return'));

            // Salir de la aplicacion hacia la pasarela: una visita de Inertia no puede.
            return Inertia::location($checkout['url']);
        }

        $charge = $this->billing->chargeSavedCard($company, $request->user());

        return back()->with(...match ($charge->status) {
            MembershipCharge::STATUS_APPROVED => ['success', 'Pago aprobado. La membresía quedó renovada.'],
            MembershipCharge::STATUS_PENDING => ['info', 'El pago está en proceso. Te avisamos aquí apenas la pasarela lo confirme.'],
            default => ['error', 'El pago no pasó: '.($charge->failure_reason ?: $charge->statusLabel()).'.'],
        });
    }

    /**
     * La vuelta del checkout. Wompi agrega `?id=` con la transaccion; se relee en la API y se
     * liquida si es de esta empresa. Si no llega nada, el evento lo liquidara igual.
     */
    public function checkoutReturn(Request $request): RedirectResponse
    {
        $company = $this->company();
        $transactionId = (string) $request->query('id', '');
        $destination = redirect()->route('settings.index', ['pestana' => 'membresia']);

        if ($transactionId === '' || ! preg_match('/^[A-Za-z0-9\-]{1,80}$/', $transactionId)) {
            return $destination->with('info', 'Cuando la pasarela confirme el pago, lo verás en el historial.');
        }

        $transaction = $this->billing->gateway()->fetchTransaction($transactionId);
        $reference = is_array($transaction) ? (string) ($transaction['reference'] ?? '') : '';

        $charge = $reference !== ''
            ? MembershipCharge::query()->where('reference', $reference)->where('company_id', $company->id)->first()
            : null;

        if (! $charge) {
            return $destination->with('info', 'Cuando la pasarela confirme el pago, lo verás en el historial.');
        }

        $charge = $this->billing->settle($reference, $transaction) ?? $charge;

        return $destination->with(...match ($charge->status) {
            MembershipCharge::STATUS_APPROVED => ['success', 'Pago aprobado. La membresía quedó renovada.'],
            MembershipCharge::STATUS_PENDING => ['info', 'El pago está en proceso. Lo verás aprobado apenas la entidad lo confirme.'],
            default => ['error', 'El pago no pasó: '.($charge->failure_reason ?: $charge->statusLabel()).'.'],
        });
    }

    public function schedule(ScheduleMembershipChangeRequest $request): RedirectResponse
    {
        $company = $this->company();
        $plan = MembershipPlan::query()->findOrFail($request->validated('membership_plan_id'));
        $cycle = BillingCycle::query()->findOrFail($request->validated('billing_cycle_id'));

        $this->membership->scheduleChange($company, $plan, $cycle, $request->user());

        $same = (int) $plan->id === (int) $company->membership_plan_id && (int) $cycle->id === (int) $company->billing_cycle_id;

        return back()->with('success', $same
            ? 'Se canceló el cambio programado: sigues en tu plan actual.'
            : "Cambiará a {$plan->name} · {$cycle->name} en la próxima renovación.");
    }

    public function receipt(MembershipCharge $charge, PlatformSettingsService $platform): Response
    {
        $company = $this->company();

        abort_unless((int) $charge->company_id === (int) $company->id, 404);
        abort_unless($charge->status === MembershipCharge::STATUS_APPROVED, 404, 'Solo los cobros aprobados tienen comprobante.');

        $charge->loadMissing(['plan', 'cycle']);

        return Pdf::loadView('pdf.membership-receipt', [
            'charge' => $charge,
            'company' => $company,
            'platformName' => $platform->name(),
            'supportEmail' => $platform->get('platform.support_email'),
        ])->setPaper('letter')->download("comprobante-{$charge->reference}.pdf");
    }

    protected function company(): Company
    {
        $company = TenantContext::company();

        abort_if($company === null, 409, 'Elige una empresa para continuar.');

        return $company;
    }
}
