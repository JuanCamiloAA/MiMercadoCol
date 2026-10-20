import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import type { RowFlag } from '@/types/storefront-manage';

const TONES: Record<string, string> = {
    low_stock: 'border-[var(--warn)]/30 bg-[var(--warn-soft)] text-[var(--warn)]',
    expiring: 'border-[var(--warn)]/30 bg-[var(--warn-soft)] text-[var(--warn)]',
    incomplete: 'border-[var(--line)] bg-[var(--surface-2)] text-[var(--muted)]',
    suggested: 'border-[var(--accent)]/25 bg-[var(--accent-soft)] text-[var(--accent)]',
    promo: 'border-[var(--accent)]/25 bg-[var(--accent-soft)] text-[var(--accent)]',
    other_warehouse: 'border-[#1D6FA5]/25 bg-[#1D6FA5]/10 text-[#1D6FA5]',
};

/** Las marcas que van debajo del nombre. No cambian el estado; algunas llevan a donde se arregla. */
export function RowFlags({ flags, className }: { flags: RowFlag[]; className?: string }) {
    if (flags.length === 0) {
        return null;
    }

    const chip = 'inline-flex items-center rounded-chip border px-1.5 py-0.5 text-[0.6875rem] font-medium leading-tight';

    return (
        <ul className={cn('mt-1 flex flex-wrap gap-1', className)}>
            {flags.map((flag, index) => (
                <li key={`${flag.key}-${index}`}>
                    {flag.href ? (
                        <Link href={flag.href} className={cn(chip, TONES[flag.key] ?? TONES.incomplete, 'hover:underline')}>
                            {flag.label}
                        </Link>
                    ) : (
                        <span className={cn(chip, TONES[flag.key] ?? TONES.incomplete)}>{flag.label}</span>
                    )}
                </li>
            ))}
        </ul>
    );
}
