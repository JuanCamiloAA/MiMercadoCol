<?php

namespace App\Services\Platform;

use App\Models\BillingCycle;
use App\Models\CompanySignup;
use App\Models\MembershipCharge;
use App\Models\MembershipPlan;
use App\Models\PlatformLead;
use App\Notifications\Platform\CompanyWelcome;
use App\Services\Membership\MembershipBillingService;
use App\Services\Membership\MembershipPricing;
use App\Services\Payments\WompiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * La contratacion en linea: se guarda antes de ir a la pasarela y la empresa nace cuando el
 * pago se confirma.
 *
 * Dos caminos llegan a `settle()` —el evento de la pasarela y la pagina de estado— y pueden
 * llegar a la vez. Todo ocurre con la fila bloqueada: el primero la deja pagada y el segundo
 * encuentra una contratacion que ya no esta pendiente. Sin ese bloqueo, un evento rapido y una
 * persona impaciente crearian la empresa dos veces.
 */
class SignupService
{
    public function __construct(protected CompanyProvisioner $provisioner) {}

    /**
     * @param  array<string, mixed>  $data  ya validado por `SignupRequest`
     */
    public function createPending(array $data, MembershipPlan $plan, BillingCycle $cycle): CompanySignup
    {
        do {
            $reference = CompanySignup::REFERENCE_PREFIX.Str::upper(Str::random(16));
        } while (CompanySignup::query()->where('reference', $reference)->exists());

        return CompanySignup::query()->create([
            'reference' => $reference,
            'membership_plan_id' => $plan->id,
            'billing_cycle_id' => $cycle->id,
            'amount' => MembershipPricing::priceFor($plan, $cycle),
            'currency' => 'COP',
            'company_name' => trim($data['company_name']),
            'tax_id' => trim($data['tax_id']),
            'city' => $data['city'] ?? null,
            'business_category_id' => ! empty($data['business_category_id']) ? (int) $data['business_category_id'] : null,
            'company_phone' => $data['company_phone'] ?? null,
            'company_email' => Str::lower(trim($data['company_email'])),
            'admin_name' => trim($data['admin_name']),
            'admin_last_name' => $data['admin_last_name'] ?? null,
            'admin_email' => Str::lower(trim($data['admin_email'])),
            'admin_document' => $data['admin_document'] ?? null,
            // La contraseña se guarda ya cifrada: nunca en claro, ni un instante.
            'admin_password_hash' => Hash::make($data['admin_password']),
            'estimated_stores' => $data['estimated_stores'] ?? null,
            'message' => $data['message'] ?? null,
            'accepted_terms_at' => now(),
            'status' => CompanySignup::STATUS_PENDING,
            'expires_at' => now()->addHours(CompanySignup::TTL_HOURS),
        ]);
    }

    /**
     * Liquida una contratacion con la transaccion releida de la API.
     *
     * @param  array<string, mixed>  $transaction
     */
    public function settle(string $reference, array $transaction): ?CompanySignup
    {
        $status = (string) ($transaction['status'] ?? '');
        $transactionId = isset($transaction['id']) ? (string) $transaction['id'] : null;
        $provisioned = null;

        $signup = DB::transaction(function () use ($reference, $transaction, $status, $transactionId, &$provisioned) {
            $signup = CompanySignup::query()->where('reference', $reference)->lockForUpdate()->first();

            if ($signup === null || $signup->status !== CompanySignup::STATUS_PENDING) {
                return $signup;
            }

            $signup->transaction_id = $transactionId ?? $signup->transaction_id;

            if ($status !== WompiService::STATUS_APPROVED) {
                if (in_array($status, [WompiService::STATUS_DECLINED, WompiService::STATUS_VOIDED, WompiService::STATUS_ERROR], true)) {
                    $signup->status = CompanySignup::STATUS_FAILED;
                    $signup->failure_reason = is_string($transaction['status_message'] ?? null) && $transaction['status_message'] !== ''
                        ? mb_substr($transaction['status_message'], 0, 250)
                        : 'El pago no fue aprobado.';
                }

                $signup->save();

                return $signup;
            }

            // Aprobada, pero por el monto y la moneda que se pidieron; si no, no nace nada.
            if ((int) ($transaction['amount_in_cents'] ?? -1) !== $signup->amount * 100 || ($transaction['currency'] ?? null) !== $signup->currency) {
                Log::error('Contratacion: pago aprobado con monto o moneda que no cuadran.', [
                    'reference' => $reference,
                    'transaction_id' => $transactionId,
                ]);

                $signup->forceFill(['status' => CompanySignup::STATUS_FAILED, 'failure_reason' => 'El monto pagado no coincide con el del plan.'])->save();
                $this->flagForSupport($signup, 'El pago se aprobó por un monto distinto al del plan. Revisar y devolver o completar.');

                return $signup;
            }

            $signup->forceFill(['status' => CompanySignup::STATUS_PAID, 'paid_at' => now()])->save();

            try {
                // Punto de guardado propio: si el aprovisionamiento falla, la contratacion
                // queda registrada como pagada-sin-empresa para que soporte la resuelva.
                $provisioned = DB::transaction(fn () => $this->provision($signup, $transactionId));
                $signup->forceFill(['company_id' => $provisioned->company->id])->save();
            } catch (Throwable $exception) {
                Log::error('Contratacion: el pago se aprobo pero la empresa no se pudo crear.', [
                    'reference' => $reference,
                    'error' => $exception->getMessage(),
                ]);

                $signup->forceFill(['failure_reason' => mb_substr('Pagada sin empresa: '.$exception->getMessage(), 0, 250)])->save();
                $this->flagForSupport($signup, 'Pago aprobado, pero la empresa no se pudo crear: '.$exception->getMessage());
            }

            return $signup;
        });

        if ($provisioned instanceof ProvisionResult) {
            $this->sendWelcome($provisioned);
        }

        return $signup?->refresh();
    }

    /**
     * Las contrataciones pendientes cuyo tiempo paso.
     */
    public function expire(bool $dryRun = false): int
    {
        $query = CompanySignup::query()
            ->where('status', CompanySignup::STATUS_PENDING)
            ->where('expires_at', '<', now());

        return $dryRun ? $query->count() : $query->update(['status' => CompanySignup::STATUS_EXPIRED, 'updated_at' => now()]);
    }

    protected function provision(CompanySignup $signup, ?string $transactionId): ProvisionResult
    {
        $result = $this->provisioner->provision([
            'company' => [
                'name' => $signup->company_name,
                'tax_id' => $signup->tax_id,
                'email' => $signup->company_email,
                'phone' => $signup->company_phone,
                'city' => $signup->city,
                'business_category_id' => $signup->business_category_id,
            ],
            'store' => ['city' => $signup->city],
            'admin' => [
                'name' => $signup->admin_name,
                'last_name' => $signup->admin_last_name,
                'email' => $signup->admin_email,
                'document' => $signup->admin_document,
                'password_hash' => $signup->admin_password_hash,
            ],
            'plan_id' => $signup->membership_plan_id,
            'cycle_id' => $signup->billing_cycle_id,
            'paid' => true,
            'signup_id' => $signup->id,
        ]);

        $company = $result->company;

        // El cobro que pago la contratacion, para que aparezca en el historial de la empresa.
        MembershipCharge::query()->create([
            'company_id' => $company->id,
            'membership_plan_id' => $signup->membership_plan_id,
            'billing_cycle_id' => $signup->billing_cycle_id,
            'amount' => $signup->amount,
            'currency' => $signup->currency,
            'concept' => mb_substr('Contratación en línea · '.($signup->plan?->name ?? 'Plan').' · '.($signup->cycle?->name ?? ''), 0, 160),
            'period_starts_at' => $company->membership_started_at,
            'period_ends_at' => $company->membership_ends_at,
            'status' => MembershipCharge::STATUS_APPROVED,
            'method' => MembershipCharge::METHOD_CHECKOUT,
            'reference' => MembershipBillingService::newReference($company),
            'gateway_transaction_id' => $transactionId,
            'attempts' => 1,
            'charged_at' => $signup->created_at,
            'paid_at' => now(),
        ]);

        return $result;
    }

    /**
     * Algo que una persona tiene que mirar: queda como solicitud nueva en Plataforma.
     */
    protected function flagForSupport(CompanySignup $signup, string $note): void
    {
        PlatformLead::query()->create([
            'source' => PlatformLead::SOURCE_SIGNUP,
            'company_name' => $signup->company_name,
            'tax_id' => $signup->tax_id,
            'city' => $signup->city,
            'contact_name' => trim($signup->admin_name.' '.$signup->admin_last_name),
            'email' => $signup->admin_email,
            'phone' => $signup->company_phone,
            'membership_plan_id' => $signup->membership_plan_id,
            'billing_cycle_id' => $signup->billing_cycle_id,
            'message' => "Contratación {$signup->reference}",
            'status' => PlatformLead::STATUS_NEW,
            'notes' => $note,
        ]);
    }

    protected function sendWelcome(ProvisionResult $result): void
    {
        try {
            $result->admin->notify(new CompanyWelcome($result->company));
        } catch (Throwable $exception) {
            // El correo es cortesia: si el servidor de correo no esta, la empresa igual existe.
            Log::warning('Contratacion: no se pudo enviar el correo de bienvenida.', ['company_id' => $result->company->id, 'error' => class_basename($exception)]);
        }
    }
}
