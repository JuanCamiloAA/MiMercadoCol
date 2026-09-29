<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyMembershipEvent;
use App\Models\MembershipCharge;
use App\Models\User;
use App\Notifications\Platform\MembershipReminder;
use App\Services\Membership\MembershipBillingService;
use App\Services\Membership\MembershipService;
use App\Services\Platform\SignupService;
use App\Services\PlatformSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La ronda diaria de membresias (03:00).
 *
 * El orden importa: primero se reconcilia y se cobra —una empresa que paga hoy no debe pasar a
 * gracia por llegar tarde en la misma ronda—, despues se mueve el calendario y al final se avisa.
 */
class ProcessMemberships extends Command
{
    protected $signature = 'membership:process {--dry-run : Muestra lo que haria sin cobrar ni cambiar nada}';

    protected $description = 'Cobra renovaciones, pasa a gracia o suspende lo vencido y envia recordatorios';

    /** Intentos automaticos por periodo antes de dejar de insistirle a una tarjeta. */
    public const MAX_AUTOMATIC_ATTEMPTS = 3;

    public function handle(MembershipBillingService $billing, MembershipService $membership, PlatformSettingsService $platform, SignupService $signups): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = now();

        if ($dry) {
            $this->warn('Simulacion: no se cobra ni se cambia nada.');
        }

        // 1. Cobros que quedaron sin respuesta.
        if (! $dry) {
            $this->line('Cobros reconciliados: '.$billing->reconcilePending());
        }

        // 2. Renovaciones automaticas.
        $this->chargeRenewals($billing, $dry);

        // 3. Vencidas sin renovar: a gracia.
        $toGrace = Company::query()
            ->whereIn('membership_status', [Company::MEMBERSHIP_ACTIVE, Company::MEMBERSHIP_TRIAL])
            ->whereNotNull('membership_ends_at')
            ->where('membership_ends_at', '<=', $now)
            ->get();

        foreach ($toGrace as $company) {
            $this->line("A gracia: {$company->name}");
            $dry || $membership->startGrace($company);
        }

        // 4. Gracia vencida: suspendida.
        $toSuspend = Company::query()
            ->where('membership_status', Company::MEMBERSHIP_GRACE)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', $now)
            ->get();

        foreach ($toSuspend as $company) {
            $this->line("Suspendida: {$company->name}");
            $dry || $membership->suspend($company);
        }

        // 5 y 6. Recordatorios y tarjetas por vencer.
        $this->sendReminders($platform, $membership, $billing, $dry);

        // Contrataciones en linea que nadie pago.
        $this->line('Contrataciones vencidas: '.$signups->expire($dry));

        $this->info('Listo.');

        return self::SUCCESS;
    }

    protected function chargeRenewals(MembershipBillingService $billing, bool $dry): void
    {
        $due = Company::query()
            ->where('auto_renew', true)
            ->where('is_active', true)
            ->whereNotIn('membership_status', [Company::MEMBERSHIP_CANCELLED])
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', now())
            ->with(['paymentMethod', 'plan', 'cycle', 'nextPlan', 'nextCycle'])
            ->get();

        if ($due->isEmpty()) {
            return;
        }

        if (! $billing->gateway()->isUsable()) {
            $this->warn("Hay {$due->count()} renovaciones pendientes, pero la pasarela no esta habilitada.");

            return;
        }

        foreach ($due as $company) {
            $attempts = MembershipCharge::query()
                ->where('company_id', $company->id)
                ->where('method', MembershipCharge::METHOD_SAVED_CARD)
                ->whereNull('created_by_user_id')
                ->whereIn('status', [MembershipCharge::STATUS_DECLINED, MembershipCharge::STATUS_ERROR])
                ->where('created_at', '>=', ($company->membership_ends_at ?? now())->copy()->subDays(3))
                ->count();

            if ($attempts >= self::MAX_AUTOMATIC_ATTEMPTS) {
                $this->line("Sin reintento: {$company->name} ya tuvo {$attempts} cobros rechazados en este periodo.");

                continue;
            }

            if ($dry) {
                $this->line("Cobraria: {$company->name}");

                continue;
            }

            try {
                $charge = $billing->chargeSavedCard($company, null, automatic: true);
                $this->line("Cobro {$charge->reference} de {$company->name}: {$charge->statusLabel()}");
            } catch (ValidationException $exception) {
                $this->line("No se cobro a {$company->name}: ".collect($exception->errors())->flatten()->first());
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Fallo el cobro de {$company->name}.");
            }

            // Si no quedo aprobado, se reintenta manana y no en la proxima pasada de hoy.
            $company->refresh();

            if ($company->next_charge_at !== null && $company->next_charge_at->lte(now())) {
                $company->forceFill(['next_charge_at' => now()->addDay()->startOfHour()])->save();
            }
        }
    }

    protected function sendReminders(PlatformSettingsService $platform, MembershipService $membership, MembershipBillingService $billing, bool $dry): void
    {
        $days = collect((array) $platform->get('membership.reminder_days'))->map(fn ($day) => (int) $day)->filter(fn ($day) => $day > 0)->unique()->values();

        $companies = Company::query()
            ->whereIn('membership_status', [Company::MEMBERSHIP_ACTIVE, Company::MEMBERSHIP_TRIAL])
            ->where('is_active', true)
            ->whereNotNull('membership_ends_at')
            ->where('membership_ends_at', '>', now())
            ->with('paymentMethod')
            ->get();

        foreach ($companies as $company) {
            $left = MembershipService::daysLeft($company);
            $endsKey = $company->membership_ends_at->toDateString();

            if ($left !== null && $days->contains($left) && ! $this->alreadySent($company, CompanyMembershipEvent::REMINDER_SENT, $endsKey, $left)) {
                $this->line("Recordatorio a {$company->name}: vence en {$left} dias");

                if (! $dry) {
                    $this->notify($company, new MembershipReminder($company, MembershipReminder::KIND_EXPIRING, $left, (bool) $company->auto_renew));
                    $membership->record($company, CompanyMembershipEvent::REMINDER_SENT, ['ends_at' => $endsKey, 'days' => $left]);
                }
            }

            $card = $billing->usableCard($company);

            if ($company->auto_renew && $card && $card->expiresBefore($company->membership_ends_at)
                && ! $this->alreadySent($company, CompanyMembershipEvent::CARD_EXPIRING, $endsKey)) {
                $this->line("Tarjeta por vencer: {$company->name}");

                if (! $dry) {
                    $this->notify($company, new MembershipReminder($company, MembershipReminder::KIND_CARD, (int) $left, true));
                    $membership->record($company, CompanyMembershipEvent::CARD_EXPIRING, ['ends_at' => $endsKey]);
                }
            }
        }
    }

    protected function alreadySent(Company $company, string $type, string $endsKey, ?int $days = null): bool
    {
        return CompanyMembershipEvent::query()
            ->where('company_id', $company->id)
            ->where('type', $type)
            ->get(['data'])
            ->contains(fn (CompanyMembershipEvent $event) => ($event->data['ends_at'] ?? null) === $endsKey
                && ($days === null || (int) ($event->data['days'] ?? -1) === $days));
    }

    /**
     * A quienes pueden ver la membresia de la empresa. Si el correo no esta configurado o falla,
     * la ronda sigue: un aviso que no sale no puede frenar los cobros.
     */
    protected function notify(Company $company, MembershipReminder $notification): void
    {
        /** @var Collection<int, User> $recipients */
        $recipients = User::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $user->can('settings.membership.view'));

        foreach ($recipients as $user) {
            try {
                $user->notify($notification);
            } catch (Throwable $exception) {
                $this->warn("No se pudo enviar el aviso a {$user->email}: ".class_basename($exception));
            }
        }
    }
}
