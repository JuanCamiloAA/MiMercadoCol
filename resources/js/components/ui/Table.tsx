import { Link } from '@inertiajs/react';
import { TrayIcon } from '@phosphor-icons/react';
import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { Paginated } from '@/types';

interface TableProps {
    children: ReactNode;
    className?: string;
}

export function Table({ children, className }: TableProps) {
    return (
        <div className={cn('overflow-x-auto rounded-card border border-[var(--line)] bg-[var(--surface)]', className)}>
            <table className="w-full border-collapse text-sm">{children}</table>
        </div>
    );
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
