<?php

namespace App\Services\Membership;

use App\Models\Company;
use App\Models\CompanyMembershipEvent;
use App\Models\CompanyPaymentMethod;
use App\Models\MembershipCharge;
use App\Models\User;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\WompiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Cobrar la membresia: con la tarjeta guardada, por el checkout de la pasarela o a mano.
 *
 * El orden importa: primero se crea el cobro `pending` y despues se llama a la pasarela. Al
 * reves, un cobro exitoso seguido de una caida dejaria a la empresa cobrada sin rastro. El
 * resultado final se liquida en `settle()`, que es idempotente por referencia.
 */
class MembershipBillingService
{
    public function __construct(
        protected PaymentGatewayResolver $gateways,
        protected MembershipService $membership,
    ) {}

    public function gateway(): WompiService
    {
        return $this->gateways->wompi();
    }

    /**
     * La tarjeta con que se puede cobrar hoy: activa y del mismo entorno de la pasarela. Una
     * tarjeta guardada en pruebas no sirve para cobrar en produccion.
     */
    public function usableCard(Company $company): ?CompanyPaymentMethod
    {
        $card = $company->paymentMethod;

        if (! $card || $card->status !== CompanyPaymentMethod::STATUS_ACTIVE) {
            return null;
        }

        return $card->environment === $this->gateway()->environment() ? $card : null;
    }

    /**
     * Guarda la tarjeta: crea la fuente de pago con el token de un solo uso que tokenizo el
     * navegador. El token no se guarda: solo el id de la fuente y lo visible que devuelve Wompi.
     */
    public function saveCard(Company $company, string $token, string $acceptanceToken, string $personalAuthToken, string $email, User $user): CompanyPaymentMethod
    {
        $wompi = $this->gateway();

        if (! $wompi->isUsable()) {
            throw ValidationException::withMessages(['token' => 'El pago en línea no está disponible en este momento.']);
        }

        try {
            $source = $wompi->createPaymentSource($token, $email, $acceptanceToken, $personalAuthToken);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['token' => $exception->getMessage()]);
        }

        $card = CompanyPaymentMethod::query()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'provider' => 'wompi',
                'environment' => $wompi->environment(),
                'payment_source_id' => $source['id'],
                'brand' => $source['brand'],
                'last4' => $source['last4'],
                'exp_month' => $source['exp_month'],
                'exp_year' => $source['exp_year'],
                'holder_name' => $source['holder'],
                'customer_email' => Str::lower($email),
                'status' => CompanyPaymentMethod::STATUS_ACTIVE,
                'updated_by_user_id' => $user->id,
            ],
        );

        $this->membership->record($company, CompanyMembershipEvent::CARD_UPDATED, [
            'brand' => $card->brand,
            'last4' => $card->last4,
            'environment' => $card->environment,
        ], $user);

        $company->setRelation('paymentMethod', $card);

        return $card;
    }

    /**
     * Cuanto y que cubre el proximo cobro.
     *
     * @return array{amount: int, plan: ?\App\Models\MembershipPlan, cycle: ?\App\Models\BillingCycle, starts: \Illuminate\Support\Carbon, ends: \Illuminate\Support\Carbon, concept: string}
     */
    public function quote(Company $company): array
    {
        $company->loadMissing(['plan', 'cycle', 'nextPlan', 'nextCycle']);
        $period = $this->membership->nextPeriod($company);

        if (! $period['plan'] || ! $period['cycle']) {
            throw ValidationException::withMessages(['membership' => 'La empresa no tiene plan asignado. Comunícate con soporte.']);
        }

        $amount = MembershipPricing::priceFor($period['plan'], $period['cycle'], $company);

        return [
            'amount' => $amount,
            'plan' => $period['plan'],
            'cycle' => $period['cycle'],
            'starts' => $period['starts'],
            'ends' => $period['ends'],
            'concept' => "Membresía {$period['plan']->name} · {$period['cycle']->name}",
        ];
    }

    /**
     * Cobra ya a la tarjeta guardada. `automatic` es el cobro de la renovacion programada.
     */
    public function chargeSavedCard(Company $company, ?User $user, bool $automatic = false): MembershipCharge
    {
        $wompi = $this->gateway();
        $card = $this->usableCard($company);

        if (! $wompi->isUsable()) {
            throw ValidationException::withMessages(['method' => 'El pago en línea no está disponible en este momento.']);
        }

        if (! $card) {
            throw ValidationException::withMessages(['method' => 'No hay una tarjeta guardada para cobrar. Agrégala primero.']);
        }

        $this->assertNoChargeInFlight($company);

        $charge = $this->createPending($company, MembershipCharge::METHOD_SAVED_CARD, $user);

        try {
            $transaction = $wompi->chargePaymentSource(
                $card->payment_source_id,
                $charge->amount * 100,
                $charge->currency,
                $charge->reference,
                (string) ($card->customer_email ?: $company->email ?: $user?->email),
                recurrent: $automatic,
            );
        } catch (RuntimeException $exception) {
            // No se sabe si la pasarela lo alcanzo a procesar: queda pendiente y la
            // reconciliacion lo busca por referencia antes de darlo por perdido.
            $charge->forceFill(['failure_reason' => $exception->getMessage()])->save();

            return $charge->refresh();
        }

        $transactionId = isset($transaction['id']) ? (string) $transaction['id'] : null;
        $charge->forceFill(['gateway_transaction_id' => $transactionId])->save();

        // Casi siempre nace PENDING: se relee una vez para darle a la persona el resultado en
        // el acto. Si sigue pendiente, lo liquida el evento o la reconciliacion.
        $fresh = $transactionId ? ($wompi->fetchTransaction($transactionId) ?? $transaction) : $transaction;
        $this->settle($charge->reference, $fresh);

        return $charge->refresh();
    }

    /**
     * Abre el checkout web (PSE, Nequi, tarjeta) para pagar el proximo periodo.
     *
     * @return array{charge: MembershipCharge, url: string}
     */
    public function startCheckout(Company $company, User $user, string $redirectUrl): array
    {
        $wompi = $this->gateway();

        if (! $wompi->isUsable()) {
            throw ValidationException::withMessages(['method' => 'El pago en línea no está disponible en este momento.']);
        }

        $this->assertNoChargeInFlight($company, ignoreCheckout: true);

        $charge = $this->createPending($company, MembershipCharge::METHOD_CHECKOUT, $user);
        $expiresAt = now()->utc()->addMinutes((int) config('wompi.checkout_expiration_minutes', 60))->format('Y-m-d\TH:i:s.v\Z');

        $url = $wompi->checkoutUrl($charge->reference, $charge->amount * 100, $charge->currency, $redirectUrl, [
            'email' => $company->email ?: $user->email,
            'full-name' => $company->legal_name ?: $company->name,
            'legal-id' => $company->tax_id ? preg_replace('/\D/', '', explode('-', $company->tax_id)[0]) : null,
            'legal-id-type' => $company->tax_id ? 'NIT' : null,
        ], $expiresAt);

        return ['charge' => $charge, 'url' => $url];
    }

    /**
     * Liquida un cobro con lo que dice la API de la pasarela. Idempotente: un evento repetido
     * encuentra el cobro ya liquidado y no hace nada.
     *
     * @param  array<string, mixed>  $transaction  la transaccion releida de la API
     */
    public function settle(string $reference, array $transaction): ?MembershipCharge
    {
        $charge = MembershipCharge::query()->where('reference', $reference)->first();

        if (! $charge) {
            Log::warning('Membresia: evento de pago con referencia desconocida.', ['reference' => $reference]);

            return null;
        }

        if ($charge->status === MembershipCharge::STATUS_APPROVED) {
            return $charge;
        }

        $status = (string) ($transaction['status'] ?? '');
        $transactionId = isset($transaction['id']) ? (string) $transaction['id'] : null;

        // Monto y moneda tienen que ser los registrados: si no, alguien toco el cobro.
        if ($status === WompiService::STATUS_APPROVED
            && ((int) ($transaction['amount_in_cents'] ?? -1) !== $charge->amount * 100 || ($transaction['currency'] ?? null) !== $charge->currency)) {
            Log::error('Membresia: el pago aprobado no coincide con el cobro registrado.', [
                'reference' => $reference,
                'transaction_id' => $transactionId,
                'expected_cents' => $charge->amount * 100,
                'received_cents' => $transaction['amount_in_cents'] ?? null,
            ]);

            $this->membership->failCharge($charge, MembershipCharge::STATUS_ERROR, 'El monto o la moneda pagados no coinciden con el cobro.', $transactionId);

            return $charge->refresh();
        }

        match ($status) {
            WompiService::STATUS_APPROVED => $this->membership->approveCharge($charge, $transactionId),
            WompiService::STATUS_DECLINED => $this->membership->failCharge($charge, MembershipCharge::STATUS_DECLINED, $this->reason($transaction, 'La entidad rechazó el pago.'), $transactionId),
            WompiService::STATUS_VOIDED => $this->membership->failCharge($charge, MembershipCharge::STATUS_VOIDED, 'El pago fue anulado.', $transactionId),
            WompiService::STATUS_ERROR => $this->membership->failCharge($charge, MembershipCharge::STATUS_ERROR, $this->reason($transaction, 'La pasarela reportó un error.'), $transactionId),
            default => $transactionId ? $charge->forceFill(['gateway_transaction_id' => $transactionId])->save() : null,
        };

        return $charge->refresh();
    }

    /**
     * Cobros pendientes a los que no les llego respuesta: se buscan en la pasarela. Un cobro por
     * checkout que nadie pago se da por vencido cuando caduca su enlace.
     */
    public function reconcilePending(int $olderThanMinutes = 10): int
    {
        $wompi = $this->gateway();
        $settled = 0;

        $pending = MembershipCharge::query()
            ->where('status', MembershipCharge::STATUS_PENDING)
            ->where('method', '!=', MembershipCharge::METHOD_MANUAL)
            ->where('created_at', '<=', now()->subMinutes($olderThanMinutes))
            ->get();

        foreach ($pending as $charge) {
            $transaction = $charge->gateway_transaction_id
                ? $wompi->fetchTransaction($charge->gateway_transaction_id)
                : $wompi->findTransactionByReference($charge->reference);

            if ($transaction !== null) {
                $this->settle($charge->reference, $transaction);
                $settled++;

                continue;
            }

            $expiry = (int) config('wompi.checkout_expiration_minutes', 60) + 30;

            if ($charge->method === MembershipCharge::METHOD_CHECKOUT && $charge->created_at->lte(now()->subMinutes($expiry))) {
                $charge->forceFill(['status' => MembershipCharge::STATUS_VOIDED, 'failure_reason' => 'El enlace de pago caducó sin pagarse.'])->save();
                $settled++;
            }
        }

        return $settled;
    }

    /**
     * Un pago recibido por fuera (transferencia, efectivo) que registra el super usuario.
     */
    public function recordManual(Company $company, User $user, string $note): MembershipCharge
    {
        $charge = $this->createPending($company, MembershipCharge::METHOD_MANUAL, $user, $note);
        $this->membership->approveCharge($charge);

        return $charge->refresh();
    }

    protected function createPending(Company $company, string $method, ?User $user, ?string $note = null): MembershipCharge
    {
        $quote = $this->quote($company);

        if ($quote['amount'] <= 0) {
            throw ValidationException::withMessages(['method' => 'Este plan no tiene costo: no hay nada que cobrar.']);
        }

        return MembershipCharge::query()->create([
            'company_id' => $company->id,
            'membership_plan_id' => $quote['plan']?->id,
            'billing_cycle_id' => $quote['cycle']?->id,
            'amount' => $quote['amount'],
            'currency' => 'COP',
            'concept' => $note ? mb_substr($quote['concept'].' · '.$note, 0, 160) : $quote['concept'],
            'period_starts_at' => $quote['starts'],
            'period_ends_at' => $quote['ends'],
            'status' => MembershipCharge::STATUS_PENDING,
            'method' => $method,
            'reference' => self::newReference($company),
            'attempts' => 1,
            'charged_at' => now(),
            'created_by_user_id' => $user?->id,
        ]);
    }

    /**
     * Un segundo cobro mientras el primero sigue en el aire podria cobrar dos veces el mismo
     * periodo. Un checkout abierto no bloquea otro checkout: la persona pudo cerrar la pestaña.
     */
    protected function assertNoChargeInFlight(Company $company, bool $ignoreCheckout = false): void
    {
        $checkoutWindow = now()->subMinutes((int) config('wompi.checkout_expiration_minutes', 60));

        $inFlight = MembershipCharge::query()
            ->where('company_id', $company->id)
            ->where('status', MembershipCharge::STATUS_PENDING)
            ->where(function ($query) use ($ignoreCheckout, $checkoutWindow) {
                $query->where(fn ($q) => $q->where('method', MembershipCharge::METHOD_SAVED_CARD)->where('created_at', '>=', now()->subDay()));

                // Un checkout abierto solo estorba mientras su enlace sigue vivo.
                if (! $ignoreCheckout) {
                    $query->orWhere(fn ($q) => $q->where('method', MembershipCharge::METHOD_CHECKOUT)->where('created_at', '>=', $checkoutWindow));
                }
            })
            ->exists();

        if ($inFlight) {
            throw ValidationException::withMessages(['method' => 'Ya hay un pago en proceso. Espera unos minutos a que la pasarela lo confirme.']);
        }
    }

    public static function newReference(Company $company): string
    {
        do {
            $reference = MembershipCharge::REFERENCE_PREFIX.$company->id.'-'.Str::upper(Str::random(12));
        } while (MembershipCharge::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    protected function reason(array $transaction, string $fallback): string
    {
        $message = $transaction['status_message'] ?? null;

        return is_string($message) && $message !== '' ? mb_substr($message, 0, 250) : $fallback;
    }
}
