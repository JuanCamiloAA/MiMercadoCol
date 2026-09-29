import { Link } from '@inertiajs/react';
import { CircleNotchIcon } from '@phosphor-icons/react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'warn' | 'outline';
export type ButtonSize = 'sm' | 'md' | 'lg' | 'pos';

const VARIANTS: Record<ButtonVariant, string> = {
    primary: 'bg-[var(--accent)] text-[var(--accent-ink)] hover:brightness-110 border border-transparent',
    secondary: 'bg-[var(--surface-2)] text-[var(--text)] hover:bg-[var(--surface-3)] border border-[var(--line)]',
    outline: 'bg-transparent text-[var(--text)] hover:bg-[var(--surface-2)] border border-[var(--line-2)]',
    ghost: 'bg-transparent text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--surface-2)] border border-transparent',
    danger: 'bg-[var(--danger)] text-white hover:brightness-110 border border-transparent',
    warn: 'bg-[var(--warn-soft)] text-[var(--warn)] hover:brightness-105 border border-[var(--warn)]/30',
};

const SIZES: Record<ButtonSize, string> = {
    sm: 'h-8 px-3 text-[0.8125rem] gap-1.5 rounded-chip',
    // Solo el mediano sigue la densidad: `pos` y `lg` son para el dedo y no se encogen.
    md: 'h-[calc(2.5rem*var(--density))] px-4 text-sm gap-2 rounded-control',
    lg: 'h-11 px-5 text-sm gap-2 rounded-control',
    pos: 'h-11 px-5 text-[0.9375rem] gap-2 rounded-control font-semibold',
};

const BASE =
    'inline-flex items-center justify-center font-medium transition-[background,color,filter,box-shadow] select-none ' +
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)] ' +
    'disabled:opacity-50 disabled:pointer-events-none whitespace-nowrap';

interface CommonProps {
    variant?: ButtonVariant;
    size?: ButtonSize;
    loading?: boolean;
    icon?: ReactNode;
    iconRight?: ReactNode;
    block?: boolean;
    children?: ReactNode;
    className?: string;
}

type ButtonProps = CommonProps & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children' | 'className'>;

export function Button({
    variant = 'primary',
    size = 'md',
    loading = false,
    icon,
    iconRight,
    block = false,
    className,
    children,
    disabled,
    type = 'button',
    ...props
}: ButtonProps) {
    return (
        <button
            type={type}
            disabled={disabled || loading}
            className={cn(BASE, VARIANTS[variant], SIZES[size], block && 'w-full', className)}
            {...props}
        >
            {loading ? <CircleNotchIcon size={16} weight="bold" className="animate-spin" /> : icon}
            {children}
            {iconRight}
        </button>
    );
}

interface ButtonLinkProps extends CommonProps {
    href: string;
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    as?: 'a' | 'button';
    preserveScroll?: boolean;
    data?: Record<string, string | number | boolean | null>;
}

export function ButtonLink({
    href,
    variant = 'primary',
    size = 'md',
    icon,
    iconRight,
    block = false,
    className,
    children,
    method = 'get',
    as = 'a',
    preserveScroll,
    data,
}: ButtonLinkProps) {
    return (
        <Link
            href={href}
            method={method}
            as={as}
            data={data}
            preserveScroll={preserveScroll}
            className={cn(BASE, VARIANTS[variant], SIZES[size], block && 'w-full', className)}
        >
            {icon}
            {children}
            {iconRight}
        </Link>
    );
}
