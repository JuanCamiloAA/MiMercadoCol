<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\MembershipCharge;
use App\Models\PlatformLead;
use App\Models\Sale;
use App\Services\Membership\MembershipPricing;
use App\Services\Membership\MembershipService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El inicio del super usuario en «Todas las empresas»: como va la plataforma.
 *
 * Todo sale de datos reales. Las ventas se leen sin `CompanyScope` y agrupadas por empresa: es
 * el unico lugar del sistema que las cruza, y solo para contarlas.
 */
class PlatformOverviewService
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $now = now();
        $today = Carbon::now('America/Bogota');
        $companies = Company::query()->with(['plan', 'cycle'])->get();

        $byStatus = [];

        foreach (Company::MEMBERSHIP_STATUSES as $status) {
            $byStatus[$status] = $companies->where('membership_status', $status)->where('is_active', true)->count();
        }

        // Ingreso recurrente mensual: lo que cada empresa al dia paga al mes en su ciclo.
        $mrr = $companies
            ->filter(fn (Company $company) => $company->is_active && $company->membership_status === Company::MEMBERSHIP_ACTIVE && $company->plan && $company->cycle)
            ->sum(fn (Company $company) => MembershipPricing::monthlyEquivalent($company->plan, $company->cycle, $company));

        $expiring = $companies
            ->filter(fn (Company $company) => $company->is_active
                && in_array($company->membership_status, [Company::MEMBERSHIP_ACTIVE, Company::MEMBERSHIP_TRIAL], true)
                && $company->membership_ends_at !== null
                && $company->membership_ends_at->between($now, $now->copy()->addDays(7)))
            ->sortBy('membership_ends_at')
            ->values()
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'plan' => $company->plan?->name,
                'status_label' => $company->membershipLabel(),
                'ends_at' => $company->membership_ends_at?->toIso8601String(),
                'days_left' => MembershipService::daysLeft($company),
                'auto_renew' => $company->auto_renew,
            ]);

        $monthStart = $today->copy()->startOfMonth();
        $charges = MembershipCharge::query()
            ->where('charged_at', '>=', $monthStart)
            ->select('status', DB::raw('COUNT(*) as total_count'), DB::raw('COALESCE(SUM(amount), 0) as total_amount'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $declinedStatuses = [MembershipCharge::STATUS_DECLINED, MembershipCharge::STATUS_ERROR];

        $salesToday = DB::table('sales')
            ->join('companies', 'companies.id', '=', 'sales.company_id')
            ->whereBetween('sales.sold_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->groupBy('sales.company_id', 'companies.name')
            ->orderByDesc(DB::raw('SUM(sales.total)'))
            ->get([
                'sales.company_id',
                'companies.name',
                DB::raw('COUNT(*) as tickets'),
                DB::raw('COALESCE(SUM(sales.total), 0) as total'),
            ])
            ->map(fn ($row) => [
                'company_id' => (int) $row->company_id,
                'name' => $row->name,
                'tickets' => (int) $row->tickets,
                'total' => (int) $row->total,
            ]);

        return [
            'companies' => [
                'total' => $companies->count(),
                'inactive' => $companies->where('is_active', false)->count(),
                'by_status' => $byStatus,
            ],
            'mrr' => (int) $mrr,
            'expiring' => $expiring,
            'charges' => [
                'approved_count' => (int) ($charges[MembershipCharge::STATUS_APPROVED]->total_count ?? 0),
                'approved_amount' => (int) ($charges[MembershipCharge::STATUS_APPROVED]->total_amount ?? 0),
                'declined_count' => (int) collect($declinedStatuses)->sum(fn ($status) => $charges[$status]->total_count ?? 0),
                'declined_amount' => (int) collect($declinedStatuses)->sum(fn ($status) => $charges[$status]->total_amount ?? 0),
                'pending_count' => (int) ($charges[MembershipCharge::STATUS_PENDING]->total_count ?? 0),
            ],
            'leads' => [
                'new' => PlatformLead::query()->where('status', PlatformLead::STATUS_NEW)->count(),
                'latest' => PlatformLead::query()->latest('id')->limit(5)->get()->map(fn (PlatformLead $lead) => [
                    'id' => $lead->id,
                    'company_name' => $lead->company_name,
                    'contact_name' => $lead->contact_name,
                    'status' => $lead->status,
                    'status_label' => PlatformLead::STATUS_LABELS[$lead->status] ?? $lead->status,
                    'created_at' => $lead->created_at?->toIso8601String(),
                ]),
            ],
            'sales_today' => [
                'rows' => $salesToday,
                'total' => (int) $salesToday->sum('total'),
                'tickets' => (int) $salesToday->sum('tickets'),
            ],
            'status_labels' => Company::MEMBERSHIP_LABELS,
        ];
    }
}
