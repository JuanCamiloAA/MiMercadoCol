import { useForm } from '@inertiajs/react';
import { PlusIcon, StopCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useMemo, useState } from 'react';
import { EntitlementTree, type EntitlementTreeModule } from '@/components/membership/PlanTree';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select, Textarea } from '@/components/ui/Field';
import { Badge, type BadgeTone } from '@/components/ui/Misc';
import { Modal } from '@/components/ui/Modal';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { date, money } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { PermissionCatalogueModule } from '@/types';

type RowStatus = 'active' | 'scheduled' | 'ended';

export interface PlanAccess {
    tree: EntitlementTreeModule[];
    catalogue: PermissionCatalogueModule[];
    always: string[];
    exceptions: {
        id: number;
        node: string;
        label: string;
        effect: 'grant' | 'revoke';
        starts_at: string | null;
        ends_at: string | null;
        reason: string;
        status: RowStatus;
        created_by: string | null;
        ended_by: string | null;
    }[];
    prices: {
        id: number;
        plan: string | null;
        membership_plan_id: number;
        description: string;
        starts_at: string | null;
        ends_at: string | null;
        reason: string;
        status: RowStatus;
        created_by: string | null;
    }[];
    pricing: { cycle: string; list: number; company: number }[];
}

const STATUS: Record<RowStatus, { label: string; tone: BadgeTone }> = {
    active: { label: 'Vigente', tone: 'accent' },
    scheduled: { label: 'Programada', tone: 'info' },
    ended: { label: 'Terminada', tone: 'neutral' },
};

const period = (starts: string | null, ends: string | null) => `${starts ? date(starts) : '—'} → ${ends ? date(ends) : 'sin vencimiento'}`;

/**
 * Plataforma > Empresas > Plan y funciones: lo que tiene la empresa y de donde sale, sus
 * excepciones al plan y su precio especial. Los topes no tienen excepcion: son del plan.
 */
export function PlanAccessTab({ companyId, access, plans }: { companyId: number; access: PlanAccess; plans: { value: string | number; label: string }[] }) {
    const { can } = usePermission();
    const canManage = can('companies.index.entitlements');
    const [adding, setAdding] = useState(false);
    const [pricing, setPricing] = useState(false);
    const [ending, setEnding] = useState<{ kind: 'exception' | 'price'; id: number; label: string } | null>(null);

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader
                    title="Qué tiene la empresa"
                    description="El plan y sus excepciones, con el origen de cada función. Lo que no está marcado no lo tiene: sus datos se conservan, el acceso no."
                />
                <EntitlementTree tree={access.tree} />
            </Card>

            <Card padded={false}>
                <div className="p-5 pb-3">
                    <CardHeader
                        className="mb-0"
                        title="Excepciones al plan"
                        description="Sumar o quitar una función a esta empresa, con vencimiento opcional. Quitar gana a sumar."
                        action={
                            canManage ? (
                                <Button size="sm" icon={<PlusIcon size={14} />} onClick={() => setAdding(true)}>
                                    Agregar excepción
                                </Button>
                            ) : undefined
                        }
                    />
                </div>
                {access.exceptions.length === 0 ? (
                    <div className="px-5 pb-5">
                        <EmptyState title="Sin excepciones" description="La empresa tiene exactamente lo que trae su plan." />
                    </div>
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>Función</TH>
                                <TH>Efecto</TH>
                                <TH>Vigencia</TH>
                                <TH>Motivo</TH>
                                <TH>Estado</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {access.exceptions.map((row) => (
                                <TR key={row.id}>
                                    <TD>
                                        <span className="block font-medium">{row.label}</span>
                                        <span className="font-mono text-[0.71875rem] text-[var(--muted)]">{row.node}</span>
                                    </TD>
                                    <TD>
                                        <Badge tone={row.effect === 'grant' ? 'accent' : 'danger'}>{row.effect === 'grant' ? 'Suma' : 'Quita'}</Badge>
                                    </TD>
                                    <TD className="text-[0.78125rem]">{period(row.starts_at, row.ends_at)}</TD>
                                    <TD className="text-[0.78125rem]">
                                        {row.reason}
                                        {row.created_by && <span className="block text-[var(--muted)]">{row.created_by}</span>}
                                        {row.ended_by && <span className="block text-[var(--muted)]">Terminó: {row.ended_by}</span>}
                                    </TD>
                                    <TD>
                                        <Badge tone={STATUS[row.status].tone}>{STATUS[row.status].label}</Badge>
                                    </TD>
                                    <TD align="right">
                                        {canManage && row.status !== 'ended' && (
                                            <Button size="sm" variant="ghost" icon={<StopCircleIcon size={14} />} onClick={() => setEnding({ kind: 'exception', id: row.id, label: row.label })}>
                                                Terminar
                                            </Button>
                                        )}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            <Card padded={false}>
                <div className="p-5 pb-3">
                    <CardHeader
                        className="mb-0"
                        title="Precio especial"
                        description="Precio mensual fijo o descuento sobre un plan. Es de ese plan: si la empresa cambia de plan, no se arrastra. La landing nunca lo muestra."
                        action={
                            canManage ? (
                                <Button size="sm" icon={<PlusIcon size={14} />} onClick={() => setPricing(true)}>
                                    Poner precio especial
                                </Button>
                            ) : undefined
                        }
                    />
                </div>

                {access.pricing.length > 0 && (
                    <div className="grid gap-2 px-5 pb-4 sm:grid-cols-2 lg:grid-cols-4">
                        {access.pricing.map((row) => (
                            <div key={row.cycle} className="rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[0.8125rem]">
                                <p className="text-[var(--muted)]">{row.cycle}</p>
                                <p className="font-mono font-semibold tabular">{money(row.company)}</p>
                                {row.company !== row.list && <p className="font-mono text-[0.75rem] text-[var(--muted)] line-through tabular">{money(row.list)}</p>}
                            </div>
                        ))}
                    </div>
                )}

                {access.prices.length === 0 ? (
                    <div className="px-5 pb-5">
                        <EmptyState title="Sin precio especial" description="Paga el precio de su plan." />
                    </div>
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>Plan</TH>
                                <TH>Precio</TH>
                                <TH>Vigencia</TH>
                                <TH>Motivo</TH>
                                <TH>Estado</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {access.prices.map((row) => (
                                <TR key={row.id}>
                                    <TD className="font-medium">{row.plan ?? '—'}</TD>
                                    <TD>{row.description}</TD>
                                    <TD className="text-[0.78125rem]">{period(row.starts_at, row.ends_at)}</TD>
                                    <TD className="text-[0.78125rem]">
                                        {row.reason}
                                        {row.created_by && <span className="block text-[var(--muted)]">{row.created_by}</span>}
                                    </TD>
                                    <TD>
                                        <Badge tone={STATUS[row.status].tone}>{STATUS[row.status].label}</Badge>
                                    </TD>
                                    <TD align="right">
                                        {canManage && row.status !== 'ended' && (
                                            <Button size="sm" variant="ghost" icon={<StopCircleIcon size={14} />} onClick={() => setEnding({ kind: 'price', id: row.id, label: `${row.plan ?? ''} · ${row.description}` })}>
                                                Terminar
                                            </Button>
                                        )}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            {adding && <ExceptionModal companyId={companyId} catalogue={access.catalogue} onClose={() => setAdding(false)} />}
            {pricing && <PriceModal companyId={companyId} plans={plans} onClose={() => setPricing(false)} />}
            {ending && <EndModal companyId={companyId} target={ending} onClose={() => setEnding(null)} />}
        </div>
    );
}

/** Elegir un nodo del arbol: el modulo completo, una pagina completa o una accion. */
function ExceptionModal({ companyId, catalogue, onClose }: { companyId: number; catalogue: PermissionCatalogueModule[]; onClose: () => void }) {
    const form = useForm({ node: '', effect: 'grant', starts_at: '', ends_at: '', reason: '' });
    const [module, setModule] = useState('');
    const [page, setPage] = useState('');
    const [action, setAction] = useState('');

    const current = catalogue.find((item) => item.key === module);
    const currentPage = current?.groups.find((group) => group.key === page);

    const node = useMemo(() => {
        if (!module) {
            return '';
        }

        if (!page) {
            return `${module}.*`;
        }

        return action ? action : `${module}.${page}.*`;
    }, [module, page, action]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({ ...values, node }));
        form.post(route('companies.entitlements.store', companyId), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal
            open
            onClose={() => !form.processing && onClose()}
            title="Agregar excepción al plan"
            description="Aplica a esta empresa sin tocar su plan. Lo que siempre va incluido (inicio, ajustes, membresía) no se puede quitar."
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        Cancelar
                    </Button>
                    <Button type="submit" form="exception-form" loading={form.processing} disabled={node === ''}>
                        Guardar excepción
                    </Button>
                </>
            }
        >
            <form id="exception-form" onSubmit={submit} className="flex flex-col gap-3">
                <div className="grid gap-3 sm:grid-cols-3">
                    <Select
                        label="Módulo"
                        required
                        placeholder="Elige"
                        options={catalogue.map((item) => ({ value: item.key, label: item.display }))}
                        value={module}
                        onChange={(e) => {
                            setModule(e.target.value);
                            setPage('');
                            setAction('');
                        }}
                    />
                    <Select
                        label="Página"
                        placeholder="Todo el módulo"
                        options={(current?.groups ?? []).map((group) => ({ value: group.key, label: group.display }))}
                        value={page}
                        disabled={!current}
                        onChange={(e) => {
                            setPage(e.target.value);
                            setAction('');
                        }}
                    />
                    <Select
                        label="Acción"
                        placeholder="Toda la página"
                        options={(currentPage?.permissions ?? []).map((permission) => ({ value: permission.name, label: permission.label }))}
                        value={action}
                        disabled={!currentPage}
                        onChange={(e) => setAction(e.target.value)}
                    />
                </div>
                {node && (
                    <p className="text-[0.78125rem] text-[var(--muted)]">
                        Nodo: <span className="font-mono">{node}</span>
                        {node.endsWith('.*') && ' · incluye lo que se agregue después'}
                    </p>
                )}
                {form.errors.node && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{form.errors.node}</p>}

                <div className="flex gap-2" role="radiogroup" aria-label="Efecto">
                    {[
                        { value: 'grant', label: 'Sumar al plan' },
                        { value: 'revoke', label: 'Quitar del plan' },
                    ].map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            role="radio"
                            aria-checked={form.data.effect === option.value}
                            onClick={() => form.setData('effect', option.value)}
                            className={
                                form.data.effect === option.value
                                    ? 'flex-1 rounded-control border border-[var(--accent)] bg-[var(--accent-soft)] px-3 py-2 text-[0.84375rem] font-semibold text-[var(--accent)]'
                                    : 'flex-1 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[0.84375rem]'
                            }
                        >
                            {option.label}
                        </button>
                    ))}
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Input label="Desde" type="date" hint="Vacío: desde ya." value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} error={form.errors.starts_at} />
                    <Input label="Hasta" type="date" hint="Vacío: sin vencimiento. Vale todo ese día." value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} error={form.errors.ends_at} />
                </div>
                <Textarea label="Motivo" required maxLength={250} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} error={form.errors.reason} />
            </form>
        </Modal>
    );
}

function PriceModal({ companyId, plans, onClose }: { companyId: number; plans: { value: string | number; label: string }[]; onClose: () => void }) {
    const [mode, setMode] = useState<'price' | 'discount'>('discount');
    const form = useForm({ membership_plan_id: String(plans[0]?.value ?? ''), monthly_price: '', discount_percent: '', starts_at: '', ends_at: '', reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            monthly_price: mode === 'price' && values.monthly_price !== '' ? Number(values.monthly_price) : null,
            discount_percent: mode === 'discount' && values.discount_percent !== '' ? Number(values.discount_percent) : null,
        }));
        form.post(route('companies.prices.store', companyId), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal
            open
            onClose={() => !form.processing && onClose()}
            title="Precio especial"
            description="Afecta solo los cobros nuevos: un cobro ya generado conserva su monto."
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        Cancelar
                    </Button>
                    <Button type="submit" form="price-form" loading={form.processing}>
                        Guardar precio
                    </Button>
                </>
            }
        >
            <form id="price-form" onSubmit={submit} className="flex flex-col gap-3">
                <Select label="Plan" required options={plans} value={form.data.membership_plan_id} onChange={(e) => form.setData('membership_plan_id', e.target.value)} error={form.errors.membership_plan_id} />
                <div className="flex gap-2" role="radiogroup" aria-label="Tipo de precio">
                    {[
                        { value: 'discount' as const, label: 'Descuento' },
                        { value: 'price' as const, label: 'Precio mensual fijo' },
                    ].map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            role="radio"
                            aria-checked={mode === option.value}
                            onClick={() => setMode(option.value)}
                            className={
                                mode === option.value
                                    ? 'flex-1 rounded-control border border-[var(--accent)] bg-[var(--accent-soft)] px-3 py-2 text-[0.84375rem] font-semibold text-[var(--accent)]'
                                    : 'flex-1 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[0.84375rem]'
                            }
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
                {mode === 'discount' ? (
                    <Input label="Descuento" suffix="%" inputMode="numeric" hint="De 1 a 90. Después se aplica el descuento del ciclo." value={form.data.discount_percent} onChange={(e) => form.setData('discount_percent', e.target.value.replace(/\D/g, '').slice(0, 2))} error={form.errors.discount_percent} />
                ) : (
                    <Input label="Precio mensual" prefix="$" inputMode="numeric" value={form.data.monthly_price} onChange={(e) => form.setData('monthly_price', e.target.value.replace(/\D/g, ''))} error={form.errors.monthly_price} />
                )}
                {mode === 'discount' && form.errors.monthly_price && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{form.errors.monthly_price}</p>}
                <div className="grid gap-3 sm:grid-cols-2">
                    <Input label="Desde" type="date" hint="Vacío: desde ya." value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} error={form.errors.starts_at} />
                    <Input label="Hasta" type="date" hint="Vacío: sin vencimiento." value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} error={form.errors.ends_at} />
                </div>
                <Textarea label="Motivo" required maxLength={250} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} error={form.errors.reason} />
            </form>
        </Modal>
    );
}

function EndModal({ companyId, target, onClose }: { companyId: number; target: { kind: 'exception' | 'price'; id: number; label: string }; onClose: () => void }) {
    const form = useForm({ reason: '' });

    const submit = () => {
        const url = target.kind === 'exception' ? route('companies.entitlements.end', [companyId, target.id]) : route('companies.prices.end', [companyId, target.id]);
        form.post(url, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal
            open
            onClose={() => !form.processing && onClose()}
            title={target.kind === 'exception' ? 'Terminar la excepción' : 'Terminar el precio especial'}
            description={`${target.label}. Termina ahora y queda en la historia; no se borra.`}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        Cancelar
                    </Button>
                    <Button variant="danger" onClick={submit} loading={form.processing}>
                        Terminar
                    </Button>
                </>
            }
        >
            <Textarea label="Motivo" required maxLength={250} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} error={form.errors.reason ?? (form.errors as Record<string, string | undefined>).exception ?? (form.errors as Record<string, string | undefined>).price} />
        </Modal>
    );
}
