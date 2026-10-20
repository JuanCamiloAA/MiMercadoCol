import { Badge, type BadgeTone } from '@/components/ui/Misc';
import type { CatalogStatus } from '@/types/storefront-manage';

const TONES: Record<CatalogStatus, BadgeTone> = {
    not_published: 'neutral',
    family_hidden: 'neutral',
    no_price: 'danger',
    out_of_stock: 'warn',
    visible: 'accent',
};

const DOTS: Record<BadgeTone, string> = {
    accent: 'var(--accent)',
    warn: 'var(--warn)',
    danger: 'var(--danger)',
    neutral: 'var(--muted)',
    info: '#1D6FA5',
};

interface StatusPillProps {
    status: CatalogStatus;
    label: string;
    note?: string | null;
}

/** El estado en tienda con su texto: el color nunca es lo único que lo distingue. */
export function StatusPill({ status, label, note }: StatusPillProps) {
    const tone = TONES[status];

    return (
        <div className="flex flex-col items-start gap-0.5">
            <Badge tone={tone} dotColor={DOTS[tone]}>
                {label}
            </Badge>
            {note && <span className="text-[0.6875rem] leading-tight text-[var(--muted)]">{note}</span>}
        </div>
    );
}
