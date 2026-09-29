import { MagnifyingGlassIcon } from '@phosphor-icons/react';
import type { InputHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type BadgeTone = 'accent' | 'warn' | 'danger' | 'neutral' | 'info';

const BADGE_TONES: Record<BadgeTone, string> = {
    accent: 'bg-[var(--accent-soft)] text-[var(--accent)] border-[var(--accent)]/25',
    warn: 'bg-[var(--warn-soft)] text-[var(--warn)] border-[var(--warn)]/25',
    danger: 'bg-[var(--danger-soft)] text-[var(--danger)] border-[var(--danger)]/25',
    neutral: 'bg-[var(--surface-2)] text-[var(--muted)] border-[var(--line)]',
    info: 'bg-[#1D6FA5]/12 text-[#1D6FA5] border-[#1D6FA5]/25',
};

interface BadgeProps {
    children: ReactNode;
    tone?: BadgeTone;
    className?: string;
    dotColor?: string;
}

export function Badge({ children, tone = 'neutral', className, dotColor }: BadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[0.75rem] font-medium whitespace-nowrap',
                BADGE_TONES[tone],
                className,
            )}
        >
            {dotColor && <span className="size-1.5 rounded-full" style={{ backgroundColor: dotColor }} />}
            {children}
        </span>
    );
}

interface PageHeaderProps {
    title: string;
    description?: string;
    actions?: ReactNode;
    breadcrumb?: ReactNode;
}

export function PageHeader({ title, description, actions, breadcrumb }: PageHeaderProps) {
    return (
        <header className="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0">
                {breadcrumb && <div className="mb-1.5 text-[0.75rem] text-[var(--muted)]">{breadcrumb}</div>}
                <h1 className="text-[1.5rem] leading-tight text-[var(--text)]">{title}</h1>
                {description && <p className="mt-1 text-[0.8125rem] text-[var(--muted)]">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
    );
}

interface SearchInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    containerClassName?: string;
}

export function SearchInput({ className, containerClassName, ...props }: SearchInputProps) {
    return (
        <div className={cn('relative flex items-center', containerClassName)}>
            <MagnifyingGlassIcon size={16} className="pointer-events-none absolute left-3 text-[var(--muted)]" />
            <input
                type="search"
                className={cn(
                    'h-10 w-full rounded-control border border-[var(--line)] bg-[var(--surface)] pl-9 pr-3 text-sm text-[var(--text)]',
                    'placeholder:text-[var(--muted)]/70 focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/25',
                    className,
                )}
                {...props}
            />
        </div>
    );
}

interface TabsProps<T extends string> {
    tabs: { value: T; label: string; count?: number }[];
    active: T;
    onChange: (value: T) => void;
    className?: string;
}

export function Tabs<T extends string>({ tabs, active, onChange, className }: TabsProps<T>) {
    return (
        <div className={cn('flex flex-wrap items-center gap-1 border-b border-[var(--line)]', className)}>
            {tabs.map((tab) => (
                <button
                    key={tab.value}
                    type="button"
                    onClick={() => onChange(tab.value)}
                    className={cn(
                        '-mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-[0.8125rem] font-medium transition-colors',
                        active === tab.value
                            ? 'border-[var(--accent)] text-[var(--accent)]'
                            : 'border-transparent text-[var(--muted)] hover:text-[var(--text)]',
                    )}
                >
                    {tab.label}
                    {tab.count !== undefined && (
                        <span
                            className={cn(
                                'rounded-full px-1.5 py-0.5 font-mono text-[0.6875rem] tabular',
                                active === tab.value ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'bg-[var(--surface-2)] text-[var(--muted)]',
                            )}
                        >
                            {tab.count}
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}

interface ChipProps {
    children: ReactNode;
    active?: boolean;
    onClick?: () => void;
    className?: string;
}

export function Chip({ children, active, onClick, className }: ChipProps) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'h-8 rounded-chip border px-3 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
                active
                    ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]'
                    : 'border-[var(--line)] bg-[var(--surface)] text-[var(--text)] hover:bg-[var(--surface-2)]',
                className,
            )}
        >
            {children}
        </button>
    );
}

interface AvatarProps {
    name: string;
    src?: string | null;
    size?: number;
    color?: string;
}

export function Avatar({ name, src, size = 36, color }: AvatarProps) {
    const letters = name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

    if (src) {
        return <img src={src} alt={name} className="rounded-full object-cover" style={{ width: size, height: size }} />;
    }

    return (
        <span
            className="grid shrink-0 place-items-center rounded-full font-semibold text-white"
            style={{
                width: size,
                height: size,
                fontSize: size * 0.38,
                backgroundColor: color ?? 'var(--accent)',
            }}
        >
            {letters || '?'}
        </span>
    );
}

interface StockBarProps {
    quantity: number;
    minStock: number;
}

/** Barra de existencias: verde por encima del minimo, ambar cerca, rojo por debajo. */
export function StockBar({ quantity, minStock }: StockBarProps) {
    const target = Math.max(minStock * 3, 1);
    const ratio = Math.min(1, Math.max(0, quantity / target));
    const tone = quantity <= 0 ? 'danger' : quantity <= minStock ? 'warn' : 'accent';

    const colors = {
        accent: 'var(--accent)',
        warn: 'var(--warn)',
        danger: 'var(--danger)',
    } as const;

    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-16 overflow-hidden rounded-full bg-[var(--surface-3)]">
                <div className="h-full rounded-full transition-[width]" style={{ width: `${ratio * 100}%`, backgroundColor: colors[tone] }} />
            </div>
            <span className="font-mono text-[0.8125rem] tabular" style={{ color: colors[tone] }}>
                {new Intl.NumberFormat('es-CO', { maximumFractionDigits: 3 }).format(quantity)}
            </span>
        </div>
    );
}
