import { router } from '@inertiajs/react';
import {
    ArrowsClockwiseIcon,
    CheckCircleIcon,
    ScalesIcon,
    TrendDownIcon,
    TrendUpIcon,
    WarningCircleIcon,
} from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Input, Select, Textarea } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { dateTime, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated, SelectOption } from '@/types';

interface Movement {
    id: number;
    type: 'in' | 'out';
    amount: number;
    reason: string | null;
}

interface DifferenceRow {
    id: number;
    register: string | null;
    cashier: string | null;
    opened_at: string | null;
    closed_at: string | null;
    opening_amount: number;
    expected_amount: number;
    counted_amount: number;
    difference: number;
    kind: 'faltante' | 'sobrante';
    closing_note: string | null;
    status: 'pending' | 'resolved';
    resolution: string | null;
    resolution_label: string | null;
    resolution_note: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
    report_url: string;
    movements: Movement[];
}

interface DifferencesProps {
    differences: Paginated<DifferenceRow>;
    filters: { status: string; register: string; user: string; from: string; to: string };
    metrics: { pending: number; shortfall: number; surplus: number; sessions: number };
    resolutions: SelectOption[];
    registers: SelectOption[];
    cashiers: SelectOption[];
}

export default function CashDifferences({ differences, filters: initialFilters, metrics, resolutions, registers, cashiers }: DifferencesProps) {
    const { can } = usePermission();
    const canResolve = can('registers.differences.resolve');

    const { filters, update } = useFilters('registers.differences.index', initialFilters);
    const [open, setOpen] = useState<DifferenceRow | null>(null);

    return (
        <AppLayout title="Diferencias de caja">
            <PageHeader
                title="Diferencias de caja"
                description="Los turnos que no cuadraron y que falta decidir que se hizo con esa plata."
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Sin revisar"
                    value={number(metrics.pending)}
                    tone={metrics.pending > 0 ? 'warn' : 'accent'}
                    icon={<WarningCircleIcon size={16} weight="bold" />}
                />
                <StatCard label="Faltante del rango" value={money(metrics.shortfall)} tone="danger" />
                <StatCard label="Sobrante del rango" value={money(metrics.surplus)} tone="neutral" />
                <StatCard label="Turnos descuadrados" value={number(metrics.sessions)} tone="neutral" />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <Select
                        options={[
                            { value: 'pending', label: 'Sin revisar' },
                            { value: 'resolved', label: 'Ya resueltas' },
                            { value: 'all', label: 'Todas' },
                        ]}
                        value={filters.status}
                        onChange={(event) => update('status', event.target.value)}
                        aria-label="Estado"
                    />
                    <Input type="date" aria-label="Desde" value={filters.from} onChange={(event) => update('from', event.target.value)} />
                    <Input type="date" aria-label="Hasta" value={filters.to} onChange={(event) => update('to', event.target.value)} />
                    <Select
                        options={registers}
                        placeholder="Cualquier caja"
                        value={filters.register}
                        onChange={(event) => update('register', event.target.value)}
                    />
                    <Select
                        options={cashiers}
                        placeholder="Cualquier cajero"
                        value={filters.user}
                        onChange={(event) => update('user', event.target.value)}
                    />
                </div>
            </Card>

            {differences.data.length === 0 ? (
                <EmptyState
                    title={filters.status === 'pending' ? 'No hay diferencias sin revisar' : 'Ninguna diferencia en ese rango'}
                    description="Cuando un turno cierre con descuadre, aparece aqui para que decidas que hacer."
                    icon={<ScalesIcon size={24} />}
                />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Turno</TH>
                                <TH>Cajero</TH>
                                <TH align="right">Esperado</TH>
                                <TH align="right">Contado</TH>
                                <TH align="right">Diferencia</TH>
                                <TH align="center">Estado</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {differences.data.map((row) => (
                                <TR key={row.id}>
                                    <TD>
                                        <p className="text-[0.84375rem] font-medium text-[var(--text)]">{row.register ?? 'Caja eliminada'}</p>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">{row.closed_at ? dateTime(row.closed_at) : '—'}</p>
                                    </TD>
                                    <TD className="text-[0.8125rem]">{row.cashier ?? '—'}</TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular text-[var(--muted)]">
                                        {money(row.expected_amount)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular text-[var(--muted)]">
                                        {money(row.counted_amount)}
                                    </TD>
                                    <TD align="right">
                                        <span
                                            className={cn(
                                                'font-mono text-[0.875rem] font-semibold tabular',
                                                row.difference < 0 ? 'text-[var(--danger)]' : 'text-[var(--warn)]',
                                            )}
                                        >
                                            {row.difference > 0 ? '+' : '−'} {money(Math.abs(row.difference))}
                                        </span>
                                        <span className="block text-[0.6875rem] text-[var(--muted)]">{row.kind}</span>
                                    </TD>
                                    <TD align="center">
                                        {row.status === 'resolved' ? (
                                            <Badge tone="accent">{row.resolution_label ?? 'Resuelta'}</Badge>
                                        ) : (
                                            <Badge tone="warn">Sin revisar</Badge>
                                        )}
                                    </TD>
                                    <TD align="right">
                                        <Button variant="ghost" size="sm" onClick={() => setOpen(row)}>
                                            {row.status === 'resolved' ? 'Ver' : 'Revisar'}
                                        </Button>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={differences} />
                </>
            )}

            <ResolveModal
                row={open}
                resolutions={resolutions}
                canResolve={canResolve}
                onClose={() => setOpen(null)}
            />
        </AppLayout>
    );
}

interface ResolveModalProps {
    row: DifferenceRow | null;
    resolutions: SelectOption[];
    canResolve: boolean;
    onClose: () => void;
}

function ResolveModal({ row, resolutions, canResolve, onClose }: ResolveModalProps) {
    const [resolution, setResolution] = useState('');
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!row) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (note.trim().length < 5) {
            setError('Explica que paso: es lo que va a leer quien revise esto despues.');

            return;
        }

        setSaving(true);
        setError(null);

        router.post(
            route('registers.differences.resolve', row.id),
            { resolution, note },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setResolution('');
                    setNote('');
                    onClose();
                },
                onError: (errors) => setError(Object.values(errors)[0] ?? 'No se pudo guardar.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const resolved = row.status === 'resolved';

    return (
        <Modal
            open
            onClose={onClose}
            title={`Diferencia en ${row.register ?? 'la caja'}`}
            description={`Turno de ${row.cashier ?? 'un cajero'} · cerrado ${row.closed_at ? dateTime(row.closed_at) : ''}`}
            size="lg"
            footer={
                resolved ? (
                    <>
                        <Button variant="secondary" onClick={onClose}>
                            Cerrar
                        </Button>
                        {canResolve && (
                            <Button
                                variant="warn"
                                icon={<ArrowsClockwiseIcon size={16} />}
                                onClick={() =>
                                    router.post(route('registers.differences.reopen', row.id), {}, { preserveScroll: true, onSuccess: onClose })
                                }
                            >
                                Volver a dejarla pendiente
                            </Button>
                        )}
                    </>
                ) : (
                    <>
                        <Button variant="secondary" onClick={onClose}>
                            Cancelar
                        </Button>
                        {canResolve && (
                            <Button icon={<CheckCircleIcon size={16} weight="bold" />} loading={saving} disabled={resolution === ''} onClick={submit}>
                                Resolver la diferencia
                            </Button>
                        )}
                    </>
                )
            }
        >
            <div className="grid gap-4 md:grid-cols-2">
                <div className="flex flex-col gap-3">
                    <div className="rounded-card border border-[var(--line)] bg-[var(--surface-2)] p-4">
                        <Row label="Base de apertura" value={money(row.opening_amount)} />
                        <Row label="Esperado al cierre" value={money(row.expected_amount)} />
                        <Row label="Contado por el cajero" value={money(row.counted_amount)} />

                        <div className="mt-2 flex items-baseline justify-between border-t border-[var(--line)] pt-2">
                            <span className="text-[0.84375rem] font-semibold">{row.difference < 0 ? 'Faltante' : 'Sobrante'}</span>
                            <span
                                className={cn(
                                    'font-mono text-[1.5rem] font-semibold tabular',
                                    row.difference < 0 ? 'text-[var(--danger)]' : 'text-[var(--warn)]',
                                )}
                            >
                                {money(Math.abs(row.difference))}
                            </span>
                        </div>
                    </div>

                    {row.closing_note && (
                        <div className="rounded-control border border-[var(--line)] px-3 py-2">
                            <p className="text-[0.71875rem] font-semibold uppercase tracking-wide text-[var(--muted)]">Lo que anoto el cajero</p>
                            <p className="mt-0.5 text-[0.8125rem]">{row.closing_note}</p>
                        </div>
                    )}

                    {row.movements.length > 0 && (
                        <div>
                            <p className="mb-1.5 text-[0.71875rem] font-semibold uppercase tracking-wide text-[var(--muted)]">
                                Movimientos de efectivo del turno
                            </p>
                            <ul className="flex flex-col gap-1">
                                {row.movements.map((movement) => (
                                    <li key={movement.id} className="flex items-baseline justify-between gap-2 text-[0.78125rem]">
                                        <span className="flex min-w-0 items-center gap-1.5 text-[var(--muted)]">
                                            {movement.type === 'in' ? (
                                                <TrendUpIcon size={14} className="text-[var(--accent)]" />
                                            ) : (
                                                <TrendDownIcon size={14} className="text-[var(--warn)]" />
                                            )}
                                            <span className="truncate">{movement.reason ?? (movement.type === 'in' ? 'Ingreso' : 'Sangria')}</span>
                                        </span>
                                        <span className="shrink-0 font-mono tabular">{money(movement.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <a
                        href={row.report_url}
                        target="_blank"
                        rel="noreferrer"
                        className="text-[0.78125rem] font-medium text-[var(--accent)] hover:underline"
                    >
                        Ver el informe Z del turno
                    </a>
                </div>

                <div className="flex flex-col gap-3">
                    {resolved ? (
                        <div className="rounded-card border border-[var(--accent)]/30 bg-[var(--accent-soft)] p-4">
                            <p className="text-[0.71875rem] font-semibold uppercase tracking-wide text-[var(--accent)]">Ya resuelta</p>
                            <p className="mt-1 text-[0.875rem] font-medium text-[var(--text)]">{row.resolution_label}</p>
                            <p className="mt-2 whitespace-pre-line text-[0.8125rem] text-[var(--muted)]">{row.resolution_note}</p>
                            <p className="mt-3 border-t border-[var(--accent)]/20 pt-2 text-[0.71875rem] text-[var(--muted)]">
                                {row.resolved_by} · {row.resolved_at ? dateTime(row.resolved_at) : ''}
                            </p>
                        </div>
                    ) : (
                        <form onSubmit={submit} className="flex flex-col gap-3">
                            <Select
                                label="Que se hizo con la diferencia"
                                options={resolutions}
                                placeholder="Elige una decision"
                                value={resolution}
                                onChange={(event) => setResolution(event.target.value)}
                                disabled={!canResolve}
                            />

                            <Textarea
                                label="Que paso"
                                rows={5}
                                maxLength={200}
                                value={note}
                                onChange={(event) => setNote(event.target.value)}
                                disabled={!canResolve}
                                hint="Queda guardado con tu nombre y la fecha."
                                placeholder="Se le dio mal el vuelto a un cliente en la tarde; se le aviso a la cajera."
                            />

                            {error && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{error}</p>}

                            {!canResolve && (
                                <p className="rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">
                                    No tienes permiso para resolver diferencias. Puedes verlas y avisarle a quien lo tenga.
                                </p>
                            )}
                        </form>
                    )}
                </div>
            </div>
        </Modal>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between py-0.5">
            <span className="text-[0.8125rem] text-[var(--muted)]">{label}</span>
            <span className="font-mono text-[0.8125rem] tabular text-[var(--text)]">{value}</span>
        </div>
    );
}
