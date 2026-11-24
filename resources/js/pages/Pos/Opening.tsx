import { Link, useForm } from '@inertiajs/react';
import { CheckCircleIcon, LockKeyIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useMemo, useState } from 'react';
import { Button } from '@/components/ui/Button';
import PosLayout from '@/layouts/PosLayout';
import { cn } from '@/lib/cn';
import { money, time } from '@/lib/format';

interface OpeningProps {
    store: { id: number; name: string; suggested_cash_base: number };
    registers: { id: number; name: string; code: string; is_busy: boolean; busy_by: string | null; opened_at: string | null }[];
    /** `uses_session`: el super usuario abre a su nombre con la sesion con la que entro, sin PIN. */
    cashiers: { id: number; name: string; has_pin: boolean; uses_session: boolean }[];
    denominations: number[];
    currentUserId: number;
    lastClosing: {
        register: string | null;
        difference: number | null;
        closed_at: string | null;
        resolved: boolean;
        resolution_label: string | null;
        can_review: boolean;
    } | null;
}

const CHECKS = [
    { key: 'printer', label: 'La impresora tiene papel y responde' },
    { key: 'datafono', label: 'El datáfono está encendido y conectado' },
    { key: 'previous', label: 'Revise la diferencia del cierre anterior' },
];

export default function PosOpening({ store, registers, cashiers, denominations, currentUserId, lastClosing }: OpeningProps) {
    const [checks, setChecks] = useState<Record<string, boolean>>({});

    const { data, setData, post, processing, errors } = useForm({
        register_id: registers.find((register) => !register.is_busy)?.id ?? 0,
        user_id: cashiers.find((cashier) => cashier.id === currentUserId)?.id ?? (cashiers[0]?.id ?? 0),
        pin: '',
        breakdown: {} as Record<string, number>,
        note: '',
    });

    const total = useMemo(
        () => Object.entries(data.breakdown).reduce((sum, [denomination, count]) => sum + Number(denomination) * (Number(count) || 0), 0),
        [data.breakdown],
    );

    const setCount = (denomination: number, count: number) => {
        setData('breakdown', { ...data.breakdown, [denomination]: Math.max(0, count) });
    };

    const pressKey = (key: string) => {
        if (key === 'del') {
            setData('pin', data.pin.slice(0, -1));

            return;
        }

        if (data.pin.length < 4) {
            setData('pin', data.pin + key);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('registers.opening.store'));
    };

    const selectedCashier = cashiers.find((cashier) => cashier.id === data.user_id);
    const needsPin = !selectedCashier?.uses_session;
    const difference = total - store.suggested_cash_base;

    return (
        <PosLayout title="Apertura de caja" current="opening">
            {/* Cabecera fija, cuerpo que se desplaza, y abajo la base contada con el boton:
                abrir caja no puede obligar a bajar la pantalla para encontrar el boton. */}
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="shrink-0 border-b border-[var(--line)] bg-[var(--surface)] px-6 py-4">
                    <h1 className="text-[1.375rem] leading-tight">Apertura de caja</h1>
                    <p className="mt-0.5 text-[0.8125rem] text-[var(--muted)]">
                        Sede {store.name} · base sugerida <span className="font-mono tabular">{money(store.suggested_cash_base)}</span>
                    </p>
                </header>

                <form id="opening-form" onSubmit={submit} className="grid min-h-0 flex-1 gap-5 overflow-y-auto p-6 xl:grid-cols-[1fr_400px]">
                    <div className="flex flex-col gap-5">
                        <section className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                            <h2 className="mb-3 text-[0.9375rem] font-semibold">1. Cajero</h2>
                            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                {cashiers.map((cashier) => (
                                    <button
                                        key={cashier.id}
                                        type="button"
                                        onClick={() => setData('user_id', cashier.id)}
                                        className={cn(
                                            'flex min-h-[3.25rem] items-center justify-between gap-2 rounded-control border px-3 text-left text-[0.875rem] font-medium transition-colors',
                                            data.user_id === cashier.id
                                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                : 'border-[var(--line)] bg-[var(--surface-2)] text-[var(--text)] hover:border-[var(--line-2)]',
                                        )}
                                    >
                                        {cashier.name}
                                        {!cashier.has_pin && <span className="text-[0.6875rem] text-[var(--warn)]">sin PIN</span>}
                                    </button>
                                ))}
                                {cashiers.length === 0 && (
                                    <p className="text-[0.8125rem] text-[var(--muted)]">Ningún usuario de esta sede puede abrir caja.</p>
                                )}
                            </div>
                            {errors.user_id && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.user_id}</p>}

                            {!needsPin && (
                                <p className="mt-4 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2.5 text-[0.8125rem] text-[var(--muted)]">
                                    Abres a tu nombre con la sesión de super usuario: no hace falta PIN.
                                </p>
                            )}

                            <div className={cn('mt-4 flex flex-wrap items-start gap-5', !needsPin && 'hidden')}>
                                <div>
                                    <p className="mb-2 text-[0.8125rem] font-medium">PIN de {selectedCashier?.name ?? 'el cajero'}</p>
                                    <div className="flex gap-2">
                                        {[0, 1, 2, 3].map((index) => (
                                            <span
                                                key={index}
                                                className={cn(
                                                    'grid size-11 place-items-center rounded-control border font-mono text-[1.125rem]',
                                                    data.pin.length > index
                                                        ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                        : 'border-[var(--line)] bg-[var(--surface-2)]',
                                                )}
                                            >
                                                {data.pin.length > index ? '•' : ''}
                                            </span>
                                        ))}
                                    </div>
                                    {errors.pin && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.pin}</p>}
                                </div>

                                <div className="grid w-[11.5rem] grid-cols-3 gap-1.5">
                                    {['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'del'].map((key, index) =>
                                        key === '' ? (
                                            <span key={index} />
                                        ) : (
                                            <button
                                                key={index}
                                                type="button"
                                                onClick={() => pressKey(key)}
                                                className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] font-mono text-[1rem] transition-colors hover:bg-[var(--surface-3)]"
                                            >
                                                {key === 'del' ? '←' : key}
                                            </button>
                                        ),
                                    )}
                                </div>
                            </div>
                        </section>

                        <section className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                            <h2 className="mb-3 text-[0.9375rem] font-semibold">2. Caja</h2>
                            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                {registers.map((register) => (
                                    <button
                                        key={register.id}
                                        type="button"
                                        disabled={register.is_busy}
                                        onClick={() => setData('register_id', register.id)}
                                        className={cn(
                                            'flex min-h-[4rem] flex-col justify-center gap-0.5 rounded-control border px-3 text-left transition-colors',
                                            register.is_busy
                                                ? 'cursor-not-allowed border-[var(--line)] bg-[var(--surface-2)] opacity-60'
                                                : data.register_id === register.id
                                                  ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                                  : 'border-[var(--line)] bg-[var(--surface-2)] hover:border-[var(--line-2)]',
                                        )}
                                    >
                                        <span
                                            className="text-[0.875rem] font-semibold"
                                            style={{ color: data.register_id === register.id && !register.is_busy ? 'var(--accent)' : undefined }}
                                        >
                                            {register.name}
                                        </span>
                                        {register.is_busy ? (
                                            <span className="flex items-center gap-1 text-[0.71875rem] text-[var(--warn)]">
                                                <LockKeyIcon size={12} /> {register.busy_by} · {time(register.opened_at)}
                                            </span>
                                        ) : (
                                            <span className="text-[0.71875rem] text-[var(--muted)]">Libre · prefijo {register.code}</span>
                                        )}
                                    </button>
                                ))}
                            </div>
                            {errors.register_id && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.register_id}</p>}
                        </section>

                        <section className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                            <h2 className="mb-3 text-[0.9375rem] font-semibold">3. Verificación previa</h2>
                            <div className="flex flex-col gap-2">
                                {CHECKS.map((check) => (
                                    <button
                                        key={check.key}
                                        type="button"
                                        onClick={() => setChecks((current) => ({ ...current, [check.key]: !current[check.key] }))}
                                        className="flex min-h-[2.75rem] items-center gap-3 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-left text-[0.84375rem] transition-colors hover:border-[var(--line-2)]"
                                    >
                                        <CheckCircleIcon
                                            size={20}
                                            weight={checks[check.key] ? 'fill' : 'regular'}
                                            className={checks[check.key] ? 'text-[var(--accent)]' : 'text-[var(--muted)]'}
                                        />
                                        {check.label}
                                    </button>
                                ))}
                            </div>

                            {lastClosing && (
                                <div className="mt-3 flex items-start gap-2 rounded-control border border-[var(--line)] px-3 py-2.5 text-[0.78125rem] text-[var(--muted)]">
                                    <WarningCircleIcon size={16} className="mt-0.5 shrink-0" />
                                    <span className="min-w-0">
                                        Último cierre en {lastClosing.register}: diferencia de{' '}
                                        <span
                                            className="font-mono tabular"
                                            style={{ color: (lastClosing.difference ?? 0) === 0 ? 'var(--accent)' : 'var(--danger)' }}
                                        >
                                            {money(lastClosing.difference ?? 0)}
                                        </span>
                                        {/* Un descuadre sin resolver tiene a donde ir: no se queda como un aviso eterno. */}
                                        {(lastClosing.difference ?? 0) !== 0 &&
                                            (lastClosing.resolved ? (
                                                <span className="mt-0.5 block text-[var(--accent)]">
                                                    Ya resuelta: {lastClosing.resolution_label}
                                                </span>
                                            ) : lastClosing.can_review ? (
                                                <Link
                                                    href={route('registers.differences.index')}
                                                    className="mt-0.5 block font-medium text-[var(--accent)] hover:underline"
                                                >
                                                    Sin revisar · resolverla en Diferencias de caja
                                                </Link>
                                            ) : (
                                                <span className="mt-0.5 block text-[var(--warn)]">
                                                    Sin revisar. Avisale a un administrador.
                                                </span>
                                            ))}
                                    </span>
                                </div>
                            )}
                        </section>
                    </div>

                    <aside className="flex h-fit flex-col gap-4 rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                        <h2 className="text-[0.9375rem] font-semibold">4. Conteo de la base</h2>

                        <div className="flex flex-col gap-1.5">
                            {denominations.map((denomination) => {
                                const count = data.breakdown[denomination] ?? 0;

                                return (
                                    <div key={denomination} className="flex items-center gap-2">
                                        <span className="w-[5.375rem] shrink-0 font-mono text-[0.8125rem] text-[var(--muted)] tabular">
                                            {money(denomination)}
                                        </span>
                                        <input
                                            type="number"
                                            min={0}
                                            value={count || ''}
                                            placeholder="0"
                                            onChange={(event) => setCount(denomination, Number(event.target.value))}
                                            className="h-11 w-16 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-2 text-center font-mono text-[0.9375rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                        />
                                        <span className="ml-auto font-mono text-[0.8125rem] tabular">{money(denomination * count)}</span>
                                    </div>
                                );
                            })}
                        </div>

                        <textarea
                            value={data.note}
                            onChange={(event) => setData('note', event.target.value)}
                            placeholder="Observaciones de la apertura"
                            className="min-h-[4.5rem] rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none"
                        />
                    </aside>
                </form>

                {/* El total y el boton viven fuera del area que se desplaza: la cifra que se
                    esta contando y la accion se ven siempre, sin importar cuanto se baje. */}
                <footer className="flex shrink-0 flex-wrap items-center gap-4 border-t border-[var(--line)] bg-[var(--surface)] px-6 py-3">
                    <div>
                        <p className="text-[0.75rem] text-[var(--muted)]">Base contada</p>
                        <p className="font-mono text-[1.625rem] font-semibold leading-none text-[var(--accent)] tabular">{money(total)}</p>
                    </div>

                    <div className="border-l border-[var(--line)] pl-4">
                        <p className="text-[0.75rem] text-[var(--muted)]">Contra la sugerida</p>
                        <p
                            className="font-mono text-[1.0625rem] font-semibold leading-tight tabular"
                            style={{ color: difference === 0 ? 'var(--muted)' : difference > 0 ? 'var(--accent)' : 'var(--warn)' }}
                        >
                            {difference > 0 ? '+' : ''}
                            {money(difference)}
                        </p>
                    </div>

                    <Button
                        type="submit"
                        form="opening-form"
                        size="pos"
                        className="ml-auto min-w-[16.25rem]"
                        loading={processing}
                        disabled={(needsPin && data.pin.length !== 4) || !data.register_id || !data.user_id}
                    >
                        Abrir la caja e ir a vender
                    </Button>
                </footer>
            </div>
        </PosLayout>
    );
}
