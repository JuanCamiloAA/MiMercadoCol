import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface CardProps {
    children: ReactNode;
    className?: string;
    padded?: boolean;
}

export function Card({ children, className, padded = true }: CardProps) {
    return (
        <section
            className={cn(
                'rounded-card border border-[var(--line)] bg-[var(--surface)]',
                // La densidad de Ajustes > Apariencia: 1 de fabrica, 0,8 en compacta.
                padded && 'p-[calc(1.25rem*var(--density))]',
                className,
            )}
        >
            {children}
        </section>
    );
}

interface CardHeaderProps {
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
}

export function CardHeader({ title, description, action, className }: CardHeaderProps) {
    return (
        <header className={cn('mb-4 flex items-start justify-between gap-4', className)}>
            <div className="min-w-0">
                <h2 className="text-[0.9375rem] font-semibold tracking-tight text-[var(--text)]">{title}</h2>
                {description && <p className="mt-0.5 text-[0.8125rem] text-[var(--muted)]">{description}</p>}
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </header>
    );
}

interface StatCardProps {
    label: string;
    value: ReactNode;
    change?: number | null;
    caption?: ReactNode;
    icon?: ReactNode;
    tone?: 'accent' | 'warn' | 'danger' | 'neutral';
}

const TONES: Record<NonNullable<StatCardProps['tone']>, string> = {
    accent: 'bg-[var(--accent-soft)] text-[var(--accent)]',
    warn: 'bg-[var(--warn-soft)] text-[var(--warn)]',
    danger: 'bg-[var(--danger-soft)] text-[var(--danger)]',
    neutral: 'bg-[var(--surface-2)] text-[var(--muted)]',
};

export function StatCard({ label, value, change, caption, icon, tone = 'accent' }: StatCardProps) {
    const hasChange = change !== null && change !== undefined;
    const positive = (change ?? 0) >= 0;

    return (
        <div className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-[calc(1rem*var(--density))]">
            <div className="flex items-start justify-between gap-3">
                <p className="text-[0.75rem] font-medium uppercase tracking-wide text-[var(--muted)]">{label}</p>
                {icon && <span className={cn('grid size-8 place-items-center rounded-control', TONES[tone])}>{icon}</span>}
            </div>
            <p className="mt-2 font-mono text-[1.625rem] font-semibold leading-none tracking-tight text-[var(--text)] tabular">
                {value}
            </p>
            <div className="mt-2 flex items-center gap-2 text-[0.75rem]">
                {hasChange && (
                    <span
                        className={cn(
                            'rounded-full px-1.5 py-0.5 font-mono font-medium tabular',
                            positive ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'bg-[var(--danger-soft)] text-[var(--danger)]',
                        )}
                    >
                        {positive ? '+' : ''}
                        {change?.toFixed(1).replace('.', ',')} %
                    </span>
                )}
                {caption && <span className="text-[var(--muted)]">{caption}</span>}
            </div>
        </div>
    );
}
