import { useForm } from '@inertiajs/react';
import { BuildingsIcon, EnvelopeSimpleIcon, PhoneIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Select, Textarea } from '@/components/ui/Field';
import { Badge, type BadgeTone, Chip, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { dateTime } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';

interface Lead {
    id: number;
    source: string;
    source_label: string;
    company_name: string | null;
    tax_id: string | null;
    city: string | null;
    contact_name: string;
    email: string;
    phone: string | null;
    estimated_stores: number | null;
    plan: string | null;
    cycle: string | null;
    message: string | null;
    status: 'new' | 'contacted' | 'won' | 'lost';
    status_label: string;
    notes: string | null;
    handled_by: string | null;
    company: { id: number; name: string } | null;
    created_at: string | null;
}

interface LeadsIndexProps {
    leads: Paginated<Lead>;
    filters: { estado: string; q: string };
    statusOptions: { value: string; label: string }[];
    counts: Record<string, number>;
    selectedId: number | null;
}

const TONES: Record<Lead['status'], BadgeTone> = { new: 'info', contacted: 'warn', won: 'accent', lost: 'neutral' };

/**
 * Plataforma > Solicitudes: quien pidió que lo contacten, y lo que se hizo con cada uno.
 */
export default function LeadsIndex({ leads, filters: initialFilters, statusOptions, counts, selectedId }: LeadsIndexProps) {
    const { can } = usePermission();
    const { filters, update } = useFilters('platform.leads.index', initialFilters);
    const [selected, setSelected] = useState<number | null>(selectedId ?? leads.data[0]?.id ?? null);
    const lead = leads.data.find((item) => item.id === selected) ?? null;

    return (
        <AppLayout title="Solicitudes">
            <PageHeader title="Solicitudes" description="Mercados que pidieron que los contacten y contrataciones que alguien tiene que mirar." />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Chip active={filters.estado === ''} onClick={() => update('estado', '')}>
                    Todas
                </Chip>
                {statusOptions.map((option) => (
                    <Chip key={option.value} active={filters.estado === option.value} onClick={() => update('estado', option.value)}>
                        {option.label} <span className="ml-1 font-mono tabular opacity-70">{counts[option.value] ?? 0}</span>
                    </Chip>
                ))}
                <SearchInput
                    value={filters.q}
                    onChange={(event) => update('q', event.target.value)}
                    placeholder="Empresa, contacto, correo o NIT"
                    containerClassName="ml-auto w-full sm:w-[18rem]"
                />
            </div>

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <Card padded={false}>
                    {leads.data.length === 0 ? (
                        <EmptyState title="No hay solicitudes" />
                    ) : (
                        <>
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>Fecha</TH>
                                        <TH>Empresa</TH>
                                        <TH>Contacto</TH>
                                        <TH>Origen</TH>
                                        <TH>Estado</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {leads.data.map((item) => (
                                        <TR key={item.id} onClick={() => setSelected(item.id)} className={cn('cursor-pointer', item.id === selected && 'bg-[var(--accent-soft)]')}>
                                            <TD>
                                                <span className="text-[0.8125rem]">{item.created_at ? dateTime(item.created_at) : '—'}</span>
                                            </TD>
                                            <TD>
                                                <span className="font-medium">{item.company_name ?? '—'}</span>
                                                {item.city && <span className="block text-[0.75rem] text-[var(--muted)]">{item.city}</span>}
                                            </TD>
                                            <TD>
                                                {item.contact_name}
                                                <span className="block text-[0.75rem] text-[var(--muted)]">{item.email}</span>
                                            </TD>
                                            <TD>{item.source_label}</TD>
                                            <TD>
                                                <Badge tone={TONES[item.status]}>{item.status_label}</Badge>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                            <Pagination meta={leads} />
                        </>
                    )}
                </Card>

                {lead && <LeadDetail key={lead.id} lead={lead} statusOptions={statusOptions} canManage={can('platform.leads.manage')} canCreateCompany={can('companies.index.create')} />}
            </div>
        </AppLayout>
    );
}

function LeadDetail({ lead, statusOptions, canManage, canCreateCompany }: { lead: Lead; statusOptions: { value: string; label: string }[]; canManage: boolean; canCreateCompany: boolean }) {
    const form = useForm({ status: lead.status as string, notes: lead.notes ?? '' });

    return (
        <Card className="h-fit">
            <CardHeader title={lead.company_name ?? lead.contact_name} description={`${lead.source_label} · ${lead.created_at ? dateTime(lead.created_at) : ''}`} action={<Badge tone={TONES[lead.status]}>{lead.status_label}</Badge>} />

            <dl className="grid gap-2 text-[0.875rem]">
                <Row label="Contacto" value={lead.contact_name} />
                <Row label="Correo" value={<a className="inline-flex items-center gap-1 text-[var(--accent)] hover:underline" href={`mailto:${lead.email}`}><EnvelopeSimpleIcon size={14} />{lead.email}</a>} />
                {lead.phone && <Row label="Teléfono" value={<a className="inline-flex items-center gap-1 text-[var(--accent)] hover:underline" href={`tel:${lead.phone}`}><PhoneIcon size={14} />{lead.phone}</a>} />}
                {lead.tax_id && <Row label="NIT" value={lead.tax_id} />}
                {lead.city && <Row label="Ciudad" value={lead.city} />}
                {lead.estimated_stores && <Row label="Sedes" value={String(lead.estimated_stores)} />}
                {lead.plan && <Row label="Plan" value={`${lead.plan}${lead.cycle ? ` · ${lead.cycle}` : ''}`} />}
                {lead.handled_by && <Row label="Atendió" value={lead.handled_by} />}
            </dl>

            {lead.message && <p className="mt-3 whitespace-pre-line rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.875rem]">{lead.message}</p>}

            {lead.company ? (
                <p className="mt-4 flex items-center gap-1.5 text-[0.875rem] font-medium text-[var(--accent)]">
                    <BuildingsIcon size={16} /> Empresa creada: {lead.company.name}
                </p>
            ) : (
                canCreateCompany && (
                    <ButtonLink href={`${route('companies.create')}?solicitud=${lead.id}`} variant="secondary" className="mt-4" icon={<BuildingsIcon size={16} />}>
                        Crear empresa desde esta solicitud
                    </ButtonLink>
                )
            )}

            {canManage && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(route('platform.leads.update', lead.id), { preserveScroll: true });
                    }}
                    className="mt-5 flex flex-col gap-3 border-t border-[var(--line)] pt-4"
                >
                    <Select label="Estado" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)} options={statusOptions} error={form.errors.status} />
                    <Textarea label="Notas" rows={4} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} error={form.errors.notes} />
                    <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
                        Guardar
                    </Button>
                </form>
            )}
        </Card>
    );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-[var(--muted)]">{label}</dt>
            <dd className="text-right">{value}</dd>
        </div>
    );
}
