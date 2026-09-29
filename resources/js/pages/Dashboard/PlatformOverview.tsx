import { Link } from '@inertiajs/react';
import { BuildingsIcon, CalendarXIcon, ChartLineUpIcon, CreditCardIcon, TrayIcon } from '@phosphor-icons/react';
import { Card, CardHeader, StatCard } from '@/components/ui/Card';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { date, money, relative } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { MembershipStatus } from '@/types';
import { MembershipBadge } from '@/components/membership/MembershipParts';

interface PlatformOverviewProps {
    overview: {
        companies: { total: number; inactive: number; by_status: Record<MembershipStatus, number> };
        mrr: number;
        expiring: { id: number; name: string; plan: string | null; status_label: string; ends_at: string | null; days_left: number | null; auto_renew: boolean }[];
        charges: { approved_count: number; approved_amount: number; declined_count: number; declined_amount: number; pending_count: number };
        leads: {
            new: number;
            latest: { id: number; company_name: string | null; contact_name: string; status: string; status_label: string; created_at: string | null }[];
        };
        sales_today: { rows: { company_id: number; name: string; tickets: number; total: number }[]; total: number; tickets: number };
        status_labels: Record<MembershipStatus, string>;
    };
}

/**
 * El inicio del super usuario en «Todas las empresas»: como va la plataforma hoy.
 */
export default function PlatformOverview({ overview }: PlatformOverviewProps) {
    const { can } = usePermission();
    const statuses = Object.keys(overview.status_labels) as MembershipStatus[];

    return (
        <AppLayout title="Plataforma">
            <PageHeader title="Resumen de la plataforma" description="Todas las empresas. Elige una arriba para trabajar como su administrador." />

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Empresas" value={overview.companies.total} caption={`${overview.companies.inactive} desactivadas`} icon={<BuildingsIcon size={17} />} />
                <StatCard label="Ingreso recurrente" value={money(overview.mrr)} caption="al mes, de las activas" icon={<ChartLineUpIcon size={17} />} />
                <StatCard
                    label="Cobros del mes"
                    value={money(overview.charges.approved_amount)}
                    caption={`${overview.charges.approved_count} aprobados · ${overview.charges.declined_count} rechazados`}
                    icon={<CreditCardIcon size={17} />}
                    tone={overview.charges.declined_count > 0 ? 'warn' : 'accent'}
                />
                <StatCard label="Solicitudes nuevas" value={overview.leads.new} caption="sin contactar" icon={<TrayIcon size={17} />} tone={overview.leads.new > 0 ? 'warn' : 'neutral'} />
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Membresías" description="Empresas activas por estado." />
                    <ul className="flex flex-col gap-2">
                        {statuses.map((status) => (
                            <li key={status} className="flex items-center justify-between gap-2">
                                <MembershipBadge status={status} label={overview.status_labels[status]} />
                                <span className="font-mono text-[0.9375rem] font-semibold tabular">{overview.companies.by_status[status] ?? 0}</span>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader title="Vencen en 7 días" description="Las que no renueven pasan a gracia." />
                    {overview.expiring.length === 0 ? (
                        <EmptyState title="Ninguna vence esta semana" icon={<CalendarXIcon size={24} />} />
                    ) : (
                        <ul className="flex flex-col divide-y divide-[var(--line)]">
                            {overview.expiring.map((company) => (
                                <li key={company.id} className="flex items-center justify-between gap-3 py-2 text-[0.8125rem]">
                                    <span className="min-w-0">
                                        {can('companies.index.edit') ? (
                                            <Link href={route('companies.edit', company.id)} className="font-medium hover:text-[var(--accent)]">
                                                {company.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">{company.name}</span>
                                        )}
                                        <span className="block text-[var(--muted)]">
                                            {company.plan ?? 'Sin plan'} · {company.status_label}
                                            {company.auto_renew ? ' · renovación automática' : ''}
                                        </span>
                                    </span>
                                    <span className="shrink-0 text-right">
                                        <span className="block">{company.ends_at ? date(company.ends_at, 'd MMM') : ''}</span>
                                        <span className="block text-[0.75rem] text-[var(--warn)]">
                                            {company.days_left === 0 ? 'Hoy' : `En ${company.days_left} d`}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader title="Ventas de hoy por empresa" description={`${overview.sales_today.tickets} tiquetes · ${money(overview.sales_today.total)}`} />
                    {overview.sales_today.rows.length === 0 ? (
                        <EmptyState title="Todavía no hay ventas hoy" />
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Empresa</TH>
                                    <TH align="right">Tiquetes</TH>
                                    <TH align="right">Total</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {overview.sales_today.rows.map((row) => (
                                    <TR key={row.company_id}>
                                        <TD>{row.name}</TD>
                                        <TD align="right" className="font-mono tabular">
                                            {row.tickets}
                                        </TD>
                                        <TD align="right" className="font-mono tabular">
                                            {money(row.total)}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>

                <Card>
                    <CardHeader
                        title="Últimas solicitudes"
                        action={
                            can('platform.leads.view') && route().has('platform.leads.index') ? (
                                <Link href={route('platform.leads.index')} className="text-[0.8125rem] font-medium text-[var(--accent)]">
                                    Ver todas
                                </Link>
                            ) : undefined
                        }
                    />
                    {overview.leads.latest.length === 0 ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">Sin solicitudes todavía.</p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {overview.leads.latest.map((lead) => (
                                <li key={lead.id} className="flex items-start justify-between gap-2 text-[0.8125rem]">
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">{lead.company_name ?? lead.contact_name}</span>
                                        <span className="block text-[0.75rem] text-[var(--muted)]">{lead.created_at ? relative(lead.created_at) : ''}</span>
                                    </span>
                                    <Badge tone={lead.status === 'new' ? 'warn' : lead.status === 'won' ? 'accent' : 'neutral'}>{lead.status_label}</Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
