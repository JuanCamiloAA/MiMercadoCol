import { Link } from '@inertiajs/react';
import { type Icon, SquaresFourIcon, TableIcon, TrayIcon } from '@phosphor-icons/react';
import { type CSSProperties, type ReactNode, useLayoutEffect, useRef } from 'react';
import { type TableView, useTableView } from '@/hooks/use-table-view';
import { cn } from '@/lib/cn';
import type { Paginated } from '@/types';

interface TableProps {
    children: ReactNode;
    className?: string;
    /** Clases de la etiqueta `<table>` misma (un ancho minimo, otro tamaño de letra). */
    tableClassName?: string;
    /** Para que dos listados de la misma pantalla recuerden su vista por separado. */
    name?: string;
    /** `false` en lo que no es un listado de quien lo mira (la vista previa de Ajustes): sin conmutador. */
    switchable?: boolean;
}

/**
 * Un listado. Quien lo mira escoge verlo en tabla o en tarjetas, y la eleccion se recuerda por
 * pantalla (`useTableView`). Las tarjetas son la misma tabla con otro CSS (`.data-table` en
 * `app.css`): cada fila es una tarjeta y cada celda lleva el titulo de su columna, asi que las
 * pantallas no escriben nada aparte para tenerlas.
 */
export function Table({ children, className, tableClassName, name, switchable = true }: TableProps) {
    const [stored, setView] = useTableView(name);
    const view: TableView = switchable ? stored : 'table';
    const tableRef = useCardLabels(view === 'cards');

    return (
        <div data-view={view} className={cn('data-table overflow-hidden rounded-card border border-[var(--line)] bg-[var(--surface)]', className)}>
            {switchable && (
                <div className="flex items-center justify-end border-b border-[var(--line)] px-3 py-2">
                    <ViewToggle value={view} onChange={setView} />
                </div>
            )}
            <div className="data-table-body overflow-x-auto">
                <table ref={tableRef} className={cn('w-full border-collapse text-sm', tableClassName)}>
                    {children}
                </table>
            </div>
        </div>
    );
}

const VIEWS: { value: TableView; label: string; icon: Icon }[] = [
    { value: 'table', label: 'Tabla', icon: TableIcon },
    { value: 'cards', label: 'Tarjetas', icon: SquaresFourIcon },
];

function ViewToggle({ value, onChange }: { value: TableView; onChange: (view: TableView) => void }) {
    return (
        <div role="radiogroup" aria-label="Ver el listado como" className="inline-flex items-center gap-0.5 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-0.5">
            {VIEWS.map((option) => {
                const OptionIcon = option.icon;
                const active = value === option.value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'inline-flex h-7 items-center gap-1.5 rounded-chip border px-2.5 text-[0.75rem] font-medium transition-colors',
                            'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent)]',
                            active
                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                : 'border-transparent text-[var(--muted)] hover:text-[var(--text)]',
                        )}
                    >
                        <OptionIcon size={15} weight={active ? 'fill' : 'regular'} />
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

/** Columnas que en la tarjeta van sin rotulo: las de casillas, iconos y botones. */
const UNLABELED = /^(acciones?)?$/i;

/**
 * Le pone a cada celda el titulo de su columna (`data-label`) y su papel en la tarjeta
 * (`data-card`): la primera columna con titulo es el titulo de la tarjeta, las que no tienen van
 * de lado a lado, y una fila de una sola celda (un aviso, un subtitulo) ocupa todo el ancho.
 *
 * Se lee del DOM y no de los hijos de React: asi sirve igual con celdas sueltas, condicionales,
 * en fragmentos o dentro de otro componente. Solo corre con las tarjetas a la vista.
 */
function labelCells(table: HTMLTableElement): void {
    const head = table.tHead?.rows[table.tHead.rows.length - 1];
    const labels: string[] = [];

    for (const cell of Array.from(head?.cells ?? [])) {
        const text = (cell.textContent ?? '').trim() || cell.getAttribute('title') || cell.getAttribute('aria-label') || '';

        for (let span = 0; span < cell.colSpan; span++) {
            labels.push(text);
        }
    }

    const set = (element: HTMLElement, attribute: string, value: string) => {
        if (element.getAttribute(attribute) !== value) {
            element.setAttribute(attribute, value);
        }
    };

    for (const body of Array.from(table.tBodies)) {
        for (const row of Array.from(body.rows)) {
            const cells = Array.from(row.cells);
            let column = 0;
            let titled = false;

            set(row, 'data-card-row', cells.length === 1 ? 'full' : 'card');

            for (const cell of cells) {
                const label = labels[column] ?? '';
                const bare = cells.length === 1 || UNLABELED.test(label);
                let role = 'field';

                if (cells.length === 1) {
                    role = 'full';
                } else if (isEmpty(cell)) {
                    // Sin nada que mostrar (los botones que el permiso no deja pintar): no ocupa renglon.
                    role = 'empty';
                } else if (bare) {
                    role = titled ? 'bare' : 'lead';
                } else if (!titled) {
                    role = 'title';
                    titled = true;
                }

                // El titulo de la tarjeta ya dice que es: no repite el nombre de la columna.
                set(cell, 'data-label', bare || role === 'title' ? '' : label);
                set(cell, 'data-card', role);
                column += cell.colSpan;
            }
        }
    }
}

function isEmpty(cell: HTMLTableCellElement): boolean {
    return (cell.textContent ?? '').trim() === '' && cell.querySelector('a, button, input, select, textarea, img, svg, canvas') === null;
}

function useCardLabels(enabled: boolean) {
    const ref = useRef<HTMLTableElement>(null);

    useLayoutEffect(() => {
        const table = ref.current;

        if (!enabled || !table) {
            return;
        }

        let frame = 0;
        labelCells(table);

        // Filas que llegan o se van sin que la tabla se vuelva a pintar (un filtro, una fila que
        // se abre): se rotulan antes del siguiente cuadro.
        const observer = new MutationObserver(() => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => labelCells(table));
        });
        observer.observe(table, { childList: true, subtree: true, characterData: true });

        return () => {
            observer.disconnect();
            cancelAnimationFrame(frame);
        };
    }, [enabled]);

    return ref;
}

export function THead({ children }: { children: ReactNode }) {
    return <thead className="bg-[var(--surface-2)] text-left">{children}</thead>;
}

export function TH({ children, className, align }: { children?: ReactNode; className?: string; align?: 'left' | 'right' | 'center' }) {
    return (
        <th
            className={cn(
                'whitespace-nowrap border-b border-[var(--line)] px-4 py-[calc(0.625rem*var(--density))] text-[0.75rem] font-semibold uppercase tracking-wide text-[var(--muted)]',
                align === 'right' && 'text-right',
                align === 'center' && 'text-center',
                className,
            )}
        >
            {children}
        </th>
    );
}

export function TBody({ children }: { children: ReactNode }) {
    return <tbody>{children}</tbody>;
}

export function TR({ children, className, onClick }: { children: ReactNode; className?: string; onClick?: () => void }) {
    return (
        <tr
            onClick={onClick}
            className={cn(
                'border-b border-[var(--line)] last:border-b-0 transition-colors hover:bg-[var(--surface-2)]/60',
                onClick && 'cursor-pointer',
                className,
            )}
        >
            {children}
        </tr>
    );
}

export function TD({
    children,
    className,
    align,
    colSpan,
    style,
}: {
    children?: ReactNode;
    className?: string;
    align?: 'left' | 'right' | 'center';
    colSpan?: number;
    style?: CSSProperties;
}) {
    return (
        <td
            colSpan={colSpan}
            style={style}
            className={cn('px-4 py-[calc(0.75rem*var(--density))] align-middle text-[var(--text)]', align === 'right' && 'text-right', align === 'center' && 'text-center', className)}
        >
            {children}
        </td>
    );
}

interface EmptyStateProps {
    title: string;
    description?: string;
    action?: ReactNode;
    icon?: ReactNode;
}

export function EmptyState({ title, description, action, icon }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
            <span className="grid size-12 place-items-center rounded-full bg-[var(--surface-2)] text-[var(--muted)]">
                {icon ?? <TrayIcon size={24} />}
            </span>
            <div>
                <p className="text-[0.9375rem] font-semibold text-[var(--text)]">{title}</p>
                {description && <p className="mt-1 max-w-sm text-[0.8125rem] text-[var(--muted)]">{description}</p>}
            </div>
            {action}
        </div>
    );
}

interface PaginationProps {
    meta: Pick<Paginated<unknown>, 'links' | 'from' | 'to' | 'total' | 'current_page' | 'last_page'>;
    className?: string;
}

export function Pagination({ meta, className }: PaginationProps) {
    if (meta.last_page <= 1) {
        return (
            <p className={cn('px-1 py-3 text-[0.75rem] text-[var(--muted)]', className)}>
                {meta.total} {meta.total === 1 ? 'registro' : 'registros'}
            </p>
        );
    }

    return (
        <nav className={cn('flex flex-wrap items-center justify-between gap-3 py-3', className)}>
            <p className="text-[0.75rem] text-[var(--muted)]">
                Mostrando <span className="font-mono tabular">{meta.from ?? 0}</span>–<span className="font-mono tabular">{meta.to ?? 0}</span> de{' '}
                <span className="font-mono tabular">{meta.total}</span>
            </p>
            <div className="flex flex-wrap items-center gap-1">
                {meta.links.map((link, index) =>
                    link.url ? (
                        <Link
                            key={`${link.label}-${index}`}
                            href={link.url}
                            preserveScroll
                            preserveState
                            className={cn(
                                'grid h-8 min-w-8 place-items-center rounded-chip border px-2 text-[0.8125rem] transition-colors',
                                link.active
                                    ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]'
                                    : 'border-[var(--line)] bg-[var(--surface)] text-[var(--text)] hover:bg-[var(--surface-2)]',
                            )}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ) : (
                        <span
                            key={`${link.label}-${index}`}
                            className="grid h-8 min-w-8 place-items-center rounded-chip border border-[var(--line)] px-2 text-[0.8125rem] text-[var(--muted)]/60"
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ),
                )}
            </div>
        </nav>
    );
}
