import { ArrowCounterClockwiseIcon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { Option } from './types';

interface FieldBlockProps {
    label: ReactNode;
    hint?: ReactNode;
    error?: string;
    /** A la derecha de la etiqueta: «Propio de la sede», un contador, etc. */
    aside?: ReactNode;
    children: ReactNode;
    className?: string;
    htmlFor?: string;
}

/** Un ajuste: su nombre en negrilla, el control y debajo la ayuda o el error. */
export function FieldBlock({ label, hint, error, aside, children, className, htmlFor }: FieldBlockProps) {
    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <div className="flex min-h-5 items-center justify-between gap-2">
                <label htmlFor={htmlFor} className="text-[0.84375rem] font-semibold text-[var(--text)]">
                    {label}
                </label>
                {aside}
            </div>
            {children}
            {error ? (
                <p className="text-[0.75rem] font-medium text-[var(--danger)]">{error}</p>
            ) : hint ? (
                <p className="text-[0.75rem] leading-snug text-[var(--muted)]">{hint}</p>
            ) : null}
        </div>
    );
}

interface SegmentedProps<T extends string | number> {
    value: T;
    options: Option<T>[];
    onChange: (value: T) => void;
    disabled?: boolean;
    label?: string;
    className?: string;
}

/**
 * Opciones a la vista, una al lado de la otra. Para listas cortas es mas rapido que un
 * desplegable: se ve todo de una vez y se cambia con un clic.
 */
export function Segmented<T extends string | number>({ value, options, onChange, disabled, label, className }: SegmentedProps<T>) {
    return (
        <div role="radiogroup" aria-label={label} className={cn('flex flex-wrap gap-1.5', className)}>
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={String(option.value)}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        disabled={disabled}
                        title={option.hint}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'h-9 rounded-control border px-3.5 text-[0.8125rem] font-medium transition-colors',
                            'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent)]',
                            'disabled:cursor-not-allowed disabled:opacity-60',
                            active
                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                : 'border-[var(--line)] bg-[var(--surface)] text-[var(--text)] hover:bg-[var(--surface-2)]',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

interface CheckRowProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: ReactNode;
    hint?: ReactNode;
    disabled?: boolean;
}

/** Una casilla con su texto; la ayuda va debajo, en gris. */
export function CheckRow({ checked, onChange, label, hint, disabled }: CheckRowProps) {
    return (
        <label className={cn('flex items-start gap-2.5', disabled ? 'cursor-not-allowed opacity-70' : 'cursor-pointer')}>
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-0.5 size-[1.0625rem] shrink-0 rounded-chip accent-[var(--accent)]"
            />
            <span className="min-w-0 text-[0.84375rem] leading-tight text-[var(--text)]">
                <span className="font-medium">{label}</span>
                {hint && <span className="mt-0.5 block text-[0.75rem] font-normal text-[var(--muted)]">{hint}</span>}
            </span>
        </label>
    );
}

/**
 * La marca de un campo que la sede tiene como propio, con el atajo para volver al de la
 * empresa. Solo aparece editando una sede.
 */
export function OwnChip({ own, onInherit, disabled }: { own: boolean; onInherit: () => void; disabled?: boolean }) {
    if (!own) {
        return <span className="shrink-0 whitespace-nowrap text-[0.71875rem] text-[var(--muted)]">De la empresa</span>;
    }

    return (
        <span className="flex items-center gap-2">
            <span className="rounded-full bg-[var(--accent-soft)] px-2 py-0.5 text-[0.6875rem] font-semibold text-[var(--accent)]">Propio de la sede</span>
            {!disabled && (
                <button
                    type="button"
                    onClick={onInherit}
                    className="inline-flex items-center gap-1 text-[0.71875rem] font-medium text-[var(--muted)] underline-offset-2 hover:text-[var(--text)] hover:underline"
                >
                    <ArrowCounterClockwiseIcon size={12} />
                    Usar el de la empresa
                </button>
            )}
        </span>
    );
}

/** La cabecera de una pestana: su alcance y el boton de valores de fabrica. */
export function TabHeader({ scope, onFactory, children }: { scope: string; onFactory?: () => void; children?: ReactNode }) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-[0.78125rem] text-[var(--muted)]">Alcance: {scope}</p>
                {onFactory && (
                    <button
                        type="button"
                        onClick={onFactory}
                        className="h-8 rounded-chip border border-[var(--line)] bg-[var(--surface)] px-3 text-[0.78125rem] font-medium text-[var(--text)] transition-colors hover:bg-[var(--surface-2)]"
                    >
                        Valores de fábrica
                    </button>
                )}
            </div>
            {children}
        </div>
    );
}

interface SaveBarProps {
    dirty: boolean;
    processing: boolean;
    onSave: () => void;
    label: string;
    /** Algo mas a la izquierda del boton, p. ej. el aviso de cambios sin guardar. */
    note?: ReactNode;
}

/** El guardado de la pestana, pegado abajo para que no haya que buscarlo. */
export function SaveBar({ dirty, processing, onSave, label, note }: SaveBarProps) {
    return (
        <div className="sticky bottom-0 z-10 -mx-1 mt-2 flex items-center justify-between gap-3 border-t border-[var(--line)] bg-[var(--bg)]/95 px-1 py-3 backdrop-blur">
            <p className="min-w-0 text-[0.78125rem] text-[var(--muted)]">{note ?? (dirty ? 'Tienes cambios sin guardar.' : 'Todo está guardado.')}</p>
            <button
                type="button"
                onClick={onSave}
                disabled={processing || !dirty}
                className="inline-flex h-10 shrink-0 items-center justify-center rounded-control bg-[var(--accent)] px-4 text-sm font-medium text-[var(--accent-ink)] transition-[filter] hover:brightness-110 disabled:pointer-events-none disabled:opacity-50"
            >
                {processing ? 'Guardando…' : label}
            </button>
        </div>
    );
}

/** El marco de la vista previa, con su titulo arriba y una nota abajo. */
export function PreviewFrame({ title, note, children, className }: { title: ReactNode; note?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('flex flex-col gap-2', className)}>
            <p className="text-[0.78125rem] text-[var(--muted)]">{title}</p>
            <div className="rounded-card border border-[var(--line)] bg-[var(--surface-2)] p-4">{children}</div>
            {note && <p className="text-center text-[0.75rem] font-medium text-[var(--muted)]">{note}</p>}
        </section>
    );
}
