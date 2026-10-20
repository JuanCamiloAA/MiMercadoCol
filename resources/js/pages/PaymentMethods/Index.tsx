import { router } from '@inertiajs/react';
import { DotsSixVerticalIcon, PencilSimpleIcon, PlusIcon, TrashIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';
import { paymentIcon } from '@/components/payments/payment-icons';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Toggle } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { ConfirmDialog } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import MethodFormModal, { type AccountOption, type KindOption, type MethodRow, type StoreOption } from '@/pages/PaymentMethods/components/MethodFormModal';
import PaymentTabs from '@/pages/PaymentMethods/components/PaymentTabs';

interface Props {
    methods: MethodRow[];
    kinds: KindOption[];
    accounts: AccountOption[];
    stores: StoreOption[];
    icons: string[];
    /** Los grupos del checkout de la tienda (en línea, al recibir, crédito). */
    groupLabels: Record<string, string>;
}

/** En qué canales sale un medio según su tipo y su regla `channels`. */
function channelsOf(method: MethodRow): string[] {
    const rule = method.availability.channels ?? [];

    return rule.length === 0 ? method.kind_channels : method.kind_channels.filter((channel) => rule.includes(channel));
}

/** Un resumen corto de las reglas, para leer la lista sin abrir cada medio. */
function rulesSummary(method: MethodRow, stores: StoreOption[]): string[] {
    const rules = method.availability;
    const parts: string[] = [];

    if ((rules.stores ?? []).length > 0) {
        parts.push(`${rules.stores!.length === 1 ? (stores.find((store) => store.id === rules.stores![0])?.name ?? '1 sede') : `${rules.stores!.length} sedes`}`);
    }

    if ((rules.registers ?? []).length > 0) {
        parts.push(`${rules.registers!.length} caja${rules.registers!.length === 1 ? '' : 's'}`);
    }

    if ((rules.schedule ?? []).length > 0) {
        parts.push(rules.schedule!.map((slot) => `${slot.from}–${slot.to}`).join(', '));
    }

    if (rules.valid_until) {
        parts.push(`hasta ${rules.valid_until}`);
    }

    if (rules.min_amount) {
        parts.push(`desde ${money(rules.min_amount)}`);
    }

    if (rules.max_amount) {
        parts.push(`hasta ${money(rules.max_amount)}`);
    }

    if (rules.customer?.registered) {
        parts.push('cliente registrado');
    }

    if (rules.combinable === false) {
        parts.push('no se combina');
    }

    return parts;
}

/**
 * Medios de pago: lo que la empresa ofrece para cobrar, en la caja y en la tienda. El orden de
 * esta lista es el orden en que aparecen.
 */
export default function PaymentMethodsIndex({ methods, kinds, accounts, stores, icons, groupLabels }: Props) {
    const { can } = usePermission();
    const canEdit = can('payment_methods.index.edit');
    const [editing, setEditing] = useState<MethodRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<MethodRow | null>(null);
    const [dragging, setDragging] = useState<number | null>(null);
    const [order, setOrder] = useState<number[] | null>(null);

    const ordered = useMemo(() => (order ? order.map((id) => methods.find((method) => method.id === id)).filter((method): method is MethodRow => method !== undefined) : methods), [order, methods]);

    const drop = (target: number) => {
        if (dragging === null || dragging === target) {
            setDragging(null);

            return;
        }

        const ids = ordered.map((method) => method.id);
        const [moved] = ids.splice(dragging, 1);
        ids.splice(target, 0, moved);
        setOrder(ids);
        setDragging(null);

        router.post(route('payment_methods.reorder'), { ids }, { preserveScroll: true, onFinish: () => setOrder(null) });
    };

    const preview = (channel: 'pos' | 'online') => ordered.filter((method) => method.is_active && channelsOf(method).includes(channel) && method.kind !== 'store_credit');

    return (
        <AppLayout title="Medios de pago">
            <PageHeader
                title="Medios de pago"
                description="Con qué se cobra en la caja y en la tienda en línea. El tipo decide cómo se comporta; las reglas, cuándo aparece."
                actions={
                    can('payment_methods.index.create') ? (
                        <Button icon={<PlusIcon size={16} />} onClick={() => setCreating(true)}>
                            Nuevo medio
                        </Button>
                    ) : undefined
                }
            />

            <PaymentTabs active="methods" />

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <Card padded={false}>
                    {ordered.length === 0 ? (
                        <EmptyState title="Todavía no hay medios de pago" description="Crea el primero para poder cobrar." />
                    ) : (
                        <ul className="divide-y divide-[var(--line)]">
                            {ordered.map((method, index) => {
                                const MethodIcon = paymentIcon(method.icon);
                                const summary = rulesSummary(method, stores);

                                return (
                                    <li
                                        key={method.id}
                                        draggable={canEdit}
                                        onDragStart={() => setDragging(index)}
                                        onDragOver={(event) => event.preventDefault()}
                                        onDrop={() => drop(index)}
                                        className={cn('flex items-center gap-3 px-4 py-3', dragging === index && 'opacity-50', !method.is_active && 'bg-[var(--surface-2)]/50')}
                                    >
                                        {canEdit && <DotsSixVerticalIcon size={18} className="shrink-0 cursor-grab text-[var(--muted)]" aria-label="Arrastra para ordenar" />}

                                        <span className={cn('grid size-10 shrink-0 place-items-center rounded-control', method.is_active ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'bg-[var(--surface-3)] text-[var(--muted)]')}>
                                            <MethodIcon size={20} />
                                        </span>

                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <span className={cn('truncate text-[0.875rem] font-semibold', !method.is_active && 'text-[var(--muted)]')}>{method.name}</span>
                                                <Badge>{method.kind_label}</Badge>
                                                {channelsOf(method).includes('pos') && <Badge tone="info">Caja</Badge>}
                                                {channelsOf(method).includes('online') && <Badge tone="info">Tienda</Badge>}
                                                {method.is_system && <Badge>Del sistema</Badge>}
                                                {!method.is_active && <Badge tone="warn">Apagado</Badge>}
                                            </div>
                                            <p className="mt-0.5 truncate text-[0.75rem] text-[var(--muted)]">
                                                {[method.gateway_label, ...summary, method.fee_percent > 0 || method.fee_fixed > 0 ? `comisión ${method.fee_percent} %${method.fee_fixed > 0 ? ` + ${money(method.fee_fixed)}` : ''}` : null, method.used > 0 ? `${method.used} cobros` : null]
                                                    .filter(Boolean)
                                                    .join(' · ') || 'Sin restricciones'}
                                            </p>
                                            {method.warning && (
                                                <p className="mt-1 flex items-center gap-1 text-[0.75rem] font-medium text-[var(--warn)]">
                                                    <WarningCircleIcon size={14} weight="fill" /> {method.warning}
                                                </p>
                                            )}
                                        </div>

                                        {canEdit && (
                                            <>
                                                <Toggle
                                                    checked={method.is_active}
                                                    onChange={() => router.patch(route('payment_methods.toggle', method.id), {}, { preserveScroll: true })}
                                                    label={<span className="sr-only">{method.is_active ? 'Apagar' : 'Encender'}</span>}
                                                />
                                                <Button size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />} onClick={() => setEditing(method)} aria-label={`Editar ${method.name}`} />
                                            </>
                                        )}

                                        {can('payment_methods.index.delete') && !method.is_system && method.used === 0 && (
                                            <Button size="sm" variant="ghost" icon={<TrashIcon size={15} />} onClick={() => setDeleting(method)} aria-label={`Borrar ${method.name}`} />
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Card>

                <div className="flex flex-col gap-5">
                    <Preview title="Así se ve en la caja" description="Los encendidos para la caja, en este orden. Las reglas de horario y monto se miden al cobrar." methods={preview('pos')} channel="pos" groupLabels={groupLabels} />
                    <Preview title="Así se ve en la tienda" description="Agrupados como los ve el cliente al pagar." methods={preview('online')} channel="online" groupLabels={groupLabels} />
                </div>
            </div>

            <MethodFormModal open={creating || editing !== null} onClose={() => (setCreating(false), setEditing(null))} method={editing} kinds={kinds} accounts={accounts} stores={stores} icons={icons} />

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && router.delete(route('payment_methods.destroy', deleting.id), { preserveScroll: true, onSuccess: () => setDeleting(null) })}
                title="Borrar el medio de pago"
                message={`«${deleting?.name}» nunca se usó, así que se puede borrar. Si solo quieres que deje de aparecer, apágalo.`}
                confirmLabel="Borrar"
                tone="danger"
            />
        </AppLayout>
    );
}

function Preview({
    title,
    description,
    methods,
    channel,
    groupLabels,
}: {
    title: string;
    description: string;
    methods: MethodRow[];
    channel: 'pos' | 'online';
    groupLabels: Record<string, string>;
}) {
    // En la tienda, en el orden de los grupos del checkout; en la caja, una sola lista.
    const groups = channel === 'online' ? Object.keys(groupLabels).filter((key) => methods.some((method) => method.online_group === key)) : [null];

    return (
        <Card>
            <CardHeader title={title} description={description} />
            {methods.length === 0 ? (
                <p className="text-[0.8125rem] text-[var(--muted)]">Ningún medio encendido para este canal.</p>
            ) : (
                <div className="flex flex-col gap-3">
                    {groups.map((group) => (
                        <div key={group ?? 'all'}>
                            {group && <p className="mb-1.5 text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--muted)]">{groupLabels[group]}</p>}
                            <div className={cn('grid gap-1.5', channel === 'pos' && 'grid-cols-2')}>
                                {methods
                                    .filter((method) => group === null || method.online_group === group)
                                    .map((method) => {
                                        const MethodIcon = paymentIcon(method.icon);
                                        const text = channel === 'pos' ? method.display.description_pos : method.display.description_online;

                                        return (
                                            <div
                                                key={method.id}
                                                className={cn(
                                                    'flex items-center gap-2 rounded-control border bg-[var(--surface-2)] px-2.5 py-2',
                                                    method.display.highlight ? 'border-[var(--accent)]' : 'border-[var(--line)]',
                                                )}
                                            >
                                                <MethodIcon size={16} className="shrink-0 text-[var(--muted)]" />
                                                <span className="min-w-0">
                                                    <span className="block truncate text-[0.8125rem] font-medium">{method.name}</span>
                                                    {text && <span className="block truncate text-[0.6875rem] text-[var(--muted)]">{text}</span>}
                                                </span>
                                            </div>
                                        );
                                    })}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </Card>
    );
}
