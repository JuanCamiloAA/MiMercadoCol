import { router, useForm } from '@inertiajs/react';
import { FilePdfIcon, MinusCircleIcon, PlusCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import PosLayout from '@/layouts/PosLayout';
import { cn } from '@/lib/cn';
import { money, number, time } from '@/lib/format';

interface SessionOption {
    id: number;
    register: string | null;
    cashier: string | null;
    opened_at: string;
}

interface Summary {
    sales_count: number;
    sales_total: number;
    payments_by_method: { method: string; label: string; total: number; count: number }[];
    customer_payments_cash: number;
    cash_in: number;
    cash_out: number;
    movements: { id: number; type: string; amount: number; reason: string; occurred_at: string }[];
    expected_amount: number;
}

interface ClosingProps {
    sessions: SessionOption[];
    session: {
        id: number;
        register: string | null;
        register_code: string | null;
        cashier: string | null;
        opened_at: string;
        opening_amount: number;
    } | null;
    summary: Summary | null;
    denominations: number[];
    closedToday: {
        id: number;
        register: string | null;
        cashier: string | null;
        closed_at: string | null;
        expected_amount: number | null;
        counted_amount: number | null;
        difference: number | null;
    }[];
}

export default function PosClosing({ sessions, session, summary, denominations, closedToday }: ClosingProps) {
    const [movementModal, setMovementModal] = useState<'in' | 'out' | null>(null);

    const { data, setData, post, processing, errors } = useForm({
        register_session_id: session?.id ?? 0,
        breakdown: {} as Record<string, number>,
        closing_note: '',
    });

    useEffect(() => {
        setData('register_session_id', session?.id ?? 0);
    }, [session?.id]);

    const counted = useMemo(
        () => Object.entries(data.breakdown).reduce((sum, [denomination, count]) => sum + Number(denomination) * (Number(count) || 0), 0),
        [data.breakdown],
    );

    const expected = summary?.expected_amount ?? 0;
    const difference = counted - expected;

    const movementForm = useForm({ register_session_id: session?.id ?? 0, type: 'out', amount: 0, reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('registers.closing.store'));
    };

    const submitMovement = (event: FormEvent) => {
        event.preventDefault();
        movementForm.transform((payload) => ({ ...payload, register_session_id: session?.id ?? 0, type: movementModal ?? 'out' }));
        movementForm.post(route('registers.movements.store'), {
            preserveScroll: true,
            onSuccess: () => {
                movementForm.reset('amount', 'reason');
                setMovementModal(null);
            },
        });
    };

    if (!session || !summary) {
        return (
            <PosLayout title="Cierre de caja" current="closing">
                <div className="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center">
                    <h1 className="text-[1.375rem]">No hay turnos abiertos</h1>
                    <p className="max-w-md text-[0.84375rem] text-[var(--muted)]">
                        Todas las cajas de la sede estan cerradas. Abre una caja para empezar a vender.
                    </p>
                    <Button onClick={() => router.visit(route('registers.opening.create'))}>Ir a la apertura</Button>

                    {closedToday.length > 0 && (
                        <div className="mt-6 w-full max-w-2xl rounded-card border border-[var(--line)] bg-[var(--surface)] p-4 text-left">
                            <h2 className="mb-2 text-[0.875rem] font-semibold">Cierres de hoy</h2>
                            <ul className="flex flex-col gap-1.5">
                                {closedToday.map((item) => (
                                    <li key={item.id} className="flex items-center justify-between gap-3 text-[0.8125rem]">
                                        <span>
                                            {item.register} · {item.cashier}
                                        </span>
                                        <span className="flex items-center gap-3">
                                            <span className="font-mono tabular">{money(item.counted_amount ?? 0)}</span>
                                            <span
                                                className="font-mono tabular"
                                                style={{ color: (item.difference ?? 0) === 0 ? 'var(--accent)' : 'var(--danger)' }}
                                            >
                                                {(item.difference ?? 0) > 0 ? '+' : ''}
                                                {money(item.difference ?? 0)}
                                            </span>
                                            <a
                                                href={route('registers.closing.report', item.id)}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-[var(--accent)] hover:underline"
                                            >
                                                Informe Z
                                            </a>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </PosLayout>
        );
    }

    return (
        <PosLayout title="Cierre de caja" current="closing">
            {/* Cabecera fija, cuerpo que se desplaza, y abajo la diferencia con el boton:
                cerrar un turno no puede obligar a bajar la pantalla para encontrar el boton. */}
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="flex shrink-0 flex-wrap items-end justify-between gap-3 border-b border-[var(--line)] bg-[var(--surface)] px-6 py-4">
                    <div>
                        <h1 className="text-[1.375rem] leading-tight">Cierre de caja</h1>
                        <p className="mt-0.5 text-[0.8125rem] text-[var(--muted)]">
                            {session.register} · {session.cashier} · desde {time(session.opened_at)}
                        </p>
                    </div>

                    {sessions.length > 1 && (
                        <select
                            value={session.id}
                            onChange={(event) => router.get(route('registers.closing.create'), { session: event.target.value }, { preserveScroll: true })}
                            className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] focus:border-[var(--accent)] focus:outline-none"
                        >
                            {sessions.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.register} · {item.cashier}
                                </option>
                            ))}
                        </select>
                    )}
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto p-6">
                <form id="closing-form" onSubmit={submit} className="grid gap-5 xl:grid-cols-[1fr_420px]">
                    <div className="flex flex-col gap-5">
                        <section className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                            <h2 className="mb-3 text-[0.9375rem] font-semibold">Movimiento del turno</h2>
                            <dl className="flex flex-col gap-2 text-[0.84375rem]">
                                <Row label="Base inicial" value={money(session.opening_amount)} />
                                {summary.payments_by_method.map((method) => (
                                    <Row
                                        key={method.method}
                                        label={`Ventas en ${method.label.toLowerCase()} (${method.count})`}
                                        value={money(method.total)}
                                        muted={method.method !== 'cash'}
                                    />
                                ))}
                                <Row label="Abonos de cartera en efectivo" value={money(summary.customer_payments_cash)} />
                                <Row label="Ingresos de efectivo" value={money(summary.cash_in)} />
                                <Row label="Sangrias" value={`− ${money(summary.cash_out)}`} />
                                <div className="mt-1 flex items-center justify-between border-t border-[var(--line)] pt-2">
                                    <dt className="text-[0.9375rem] font-semibold">Efectivo esperado</dt>
                                    <dd className="font-mono text-[1.375rem] font-semibold tabular">{money(expected)}</dd>
                                </div>
                            </dl>

                            <div className="mt-4 flex gap-2 border-t border-[var(--line)] pt-4">
                                <Button variant="secondary" icon={<PlusCircleIcon size={16} />} onClick={() => setMovementModal('in')}>
                                    Ingreso de efectivo
                                </Button>
                                <Button variant="secondary" icon={<MinusCircleIcon size={16} />} onClick={() => setMovementModal('out')}>
                                    Sangria
                                </Button>
                            </div>
                        </section>

                        {summary.movements.length > 0 && (
                            <section className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                                <h2 className="mb-3 text-[0.9375rem] font-semibold">Movimientos de efectivo</h2>
                                <ul className="flex flex-col gap-2">
                                    {summary.movements.map((movement) => (
                                        <li key={movement.id} className="flex items-center justify-between gap-3 text-[0.8125rem]">
                                            <span>
                                                <span className="font-mono text-[0.75rem] text-[var(--muted)] tabular">{time(movement.occurred_at)}</span>
                                                <span className="ml-2">{movement.reason}</span>
                                            </span>
                                            <span
                                                className="font-mono tabular"
                                                style={{ color: movement.type === 'in' ? 'var(--accent)' : 'var(--warn)' }}
                                            >
                                                {movement.type === 'in' ? '+' : '−'} {money(movement.amount)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                            <h2 className="mb-1 text-[0.9375rem] font-semibold">Resumen de ventas</h2>
                            <p className="text-[0.8125rem] text-[var(--muted)]">
                                {summary.sales_count} tiquetes por <span className="font-mono tabular">{money(summary.sales_total)}</span>
                            </p>
                        </section>
                    </div>

                    <aside className="flex h-fit flex-col gap-4 rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                        <h2 className="text-[0.9375rem] font-semibold">Conteo del efectivo</h2>

                        <div className="flex flex-col gap-1.5">
                            {denominations.map((denomination) => {
                                const count = data.breakdown[denomination] ?? 0;

                                return (
                                    <div key={denomination} className="flex items-center gap-2">
                                        <span className="w-[5.375rem] shrink-0 font-mono text-[0.8125rem] text-[var(--muted)] tabular">{money(denomination)}</span>
                                        <input
                                            type="number"
                                            min={0}
                                            value={count || ''}
                                            placeholder="0"
                                            onChange={(event) =>
                                                setData('breakdown', { ...data.breakdown, [denomination]: Math.max(0, Number(event.target.value)) })
                                            }
                                            className="h-11 w-16 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-2 text-center font-mono text-[0.9375rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                        />
                                        <span className="ml-auto font-mono text-[0.8125rem] tabular">{money(denomination * count)}</span>
                                    </div>
                                );
                            })}
                        </div>

                        <textarea
                            value={data.closing_note}
                            onChange={(event) => setData('closing_note', event.target.value)}
                            placeholder={difference === 0 ? 'Observaciones (opcional)' : 'Explica la diferencia (obligatorio)'}
                            className="min-h-[4.75rem] rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none"
                        />
                        {errors.closing_note && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{errors.closing_note}</p>}
                    </aside>
                </form>

                {closedToday.length > 0 && (
                    <section className="mt-5 rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                        <h2 className="mb-3 text-[0.9375rem] font-semibold">Cierres de hoy</h2>
                        <ul className="flex flex-col gap-2">
                            {closedToday.map((item) => (
                                <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 text-[0.8125rem]">
                                    <span>
                                        {item.register} · {item.cashier} · <span className="font-mono tabular">{time(item.closed_at)}</span>
                                    </span>
                                    <span className="flex items-center gap-4">
                                        <span className="font-mono tabular">esperado {money(item.expected_amount ?? 0)}</span>
                                        <span className="font-mono tabular">contado {money(item.counted_amount ?? 0)}</span>
                                        <span
                                            className="font-mono font-semibold tabular"
                                            style={{ color: (item.difference ?? 0) === 0 ? 'var(--accent)' : 'var(--danger)' }}
                                        >
                                            {(item.difference ?? 0) > 0 ? '+' : ''}
                                            {money(item.difference ?? 0)}
                                        </span>
                                        <a
                                            href={route('registers.closing.report', item.id)}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-[var(--accent)] hover:underline"
                                        >
                                            Informe Z
                                        </a>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                </div>

                {/* La diferencia y el cierre viven fuera del area que se desplaza: es la cifra
                    que decide si el turno cuadra, y tiene que verse siempre. */}
                <footer className="flex shrink-0 flex-wrap items-center gap-4 border-t border-[var(--line)] bg-[var(--surface)] px-6 py-3">
                    <div>
                        <p className="text-[0.75rem] text-[var(--muted)]">Esperado</p>
                        <p className="font-mono text-[1.0625rem] font-semibold leading-tight tabular">{money(expected)}</p>
                    </div>

                    <div className="border-l border-[var(--line)] pl-4">
                        <p className="text-[0.75rem] text-[var(--muted)]">Contado</p>
                        <p className="font-mono text-[1.0625rem] font-semibold leading-tight tabular">{money(counted)}</p>
                    </div>

                    <div
                        className={cn(
                            'rounded-control px-3 py-1.5',
                            difference === 0 ? 'bg-[var(--accent-soft)]' : 'bg-[var(--danger-soft)]',
                        )}
                    >
                        <p className="text-[0.75rem]" style={{ color: difference === 0 ? 'var(--accent)' : 'var(--danger)' }}>
                            Diferencia
                        </p>
                        <p
                            className="font-mono text-[1.375rem] font-semibold leading-none tabular"
                            style={{ color: difference === 0 ? 'var(--accent)' : 'var(--danger)' }}
                        >
                            {difference > 0 ? '+' : ''}
                            {money(difference)}
                        </p>
                    </div>

                    <div className="ml-auto flex items-center gap-2">
                        <a
                            href={route('registers.closing.report', session.id)}
                            target="_blank"
                            rel="noreferrer"
                            className="flex h-11 items-center justify-center gap-2 rounded-control border border-[var(--line)] px-3 text-[0.84375rem] font-medium text-[var(--muted)] transition-colors hover:text-[var(--text)]"
                        >
                            <FilePdfIcon size={16} />
                            Informe Z
                        </a>

                        <Button
                            type="submit"
                            form="closing-form"
                            size="pos"
                            className="min-w-[12.5rem]"
                            loading={processing}
                            disabled={counted === 0 && expected !== 0}
                        >
                            Cerrar el turno
                        </Button>
                    </div>
                </footer>
            </div>

            <Modal
                open={movementModal !== null}
                onClose={() => setMovementModal(null)}
                title={movementModal === 'in' ? 'Ingreso de efectivo' : 'Sangria'}
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setMovementModal(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitMovement} loading={movementForm.processing}>
                            Registrar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitMovement} className="flex flex-col gap-3">
                    <input
                        autoFocus
                        type="number"
                        min={0}
                        value={movementForm.data.amount || ''}
                        onChange={(event) => movementForm.setData('amount', Number(event.target.value))}
                        placeholder="0"
                        className="h-14 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-center font-mono text-[1.625rem] tabular focus:border-[var(--accent)] focus:outline-none"
                    />
                    {movementForm.errors.amount && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{movementForm.errors.amount}</p>}

                    <input
                        type="text"
                        value={movementForm.data.reason}
                        onChange={(event) => movementForm.setData('reason', event.target.value)}
                        placeholder={movementModal === 'in' ? 'Motivo del ingreso' : 'Motivo de la sangria'}
                        className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] focus:border-[var(--accent)] focus:outline-none"
                    />
                    {movementForm.errors.reason && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{movementForm.errors.reason}</p>}

                    <p className="text-[0.78125rem] text-[var(--muted)]">
                        Efectivo disponible ahora: <span className="font-mono tabular">{money(expected)}</span>
                    </p>
                </form>
            </Modal>
        </PosLayout>
    );
}

function Row({ label, value, muted }: { label: string; value: string; muted?: boolean }) {
    return (
        <div className="flex items-center justify-between">
            <dt className={muted ? 'text-[var(--muted)]' : ''}>{label}</dt>
            <dd className={cn('font-mono tabular', muted && 'text-[var(--muted)]')}>{value}</dd>
        </div>
    );
}
