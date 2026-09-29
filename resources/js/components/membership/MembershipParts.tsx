import { Badge, type BadgeTone } from '@/components/ui/Misc';
import { cn } from '@/lib/cn';
import type { MembershipStatus } from '@/types';

const TONES: Record<MembershipStatus, BadgeTone> = {
    trial: 'info',
    active: 'accent',
    grace: 'warn',
    suspended: 'danger',
    cancelled: 'neutral',
};

/** El estado de la membresia con su color. El texto llega del servidor. */
export function MembershipBadge({ status, label }: { status: MembershipStatus; label: string }) {
    return <Badge tone={TONES[status] ?? 'neutral'}>{label}</Badge>;
}

/**
 * Cuanto usa de un recurso contra el tope del plan. Sin tope, solo la cuenta.
 */
export function UsageMeter({ used, limit, label }: { used: number; limit: number | null; label?: string }) {
    const ratio = limit ? Math.min(1, used / Math.max(1, limit)) : 0;
    const full = limit !== null && used >= limit;

    return (
        <div className="min-w-[5.5rem]">
            <p className="flex items-baseline justify-between gap-2 text-[0.8125rem]">
                {label && <span className="text-[var(--muted)]">{label}</span>}
                <span className={cn('font-mono tabular', full && 'font-semibold text-[var(--warn)]')}>
                    {used}
                    <span className="text-[var(--muted)]">{limit === null ? ' · sin tope' : ` / ${limit}`}</span>
                </span>
            </p>
            {limit !== null && (
                <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-[var(--surface-3)]">
                    <div className={cn('h-full rounded-full', full ? 'bg-[var(--warn)]' : 'bg-[var(--accent)]')} style={{ width: `${ratio * 100}%` }} />
                </div>
            )}
        </div>
    );
}
