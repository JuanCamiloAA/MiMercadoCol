import { StoreIcon } from '@/components/storefront/icons';
import type { BlockProps } from './shared';

/** Tres o cuatro promesas cortas con su ícono: domicilio, horario, formas de pago. */
export function BenefitsBlock({ data }: BlockProps) {
    const items = (data.items as { icon: string; title: string; text: string }[] | undefined) ?? [];

    if (items.length === 0) {
        return null;
    }

    return (
        <ul className="grid gap-3 rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] p-4 sm:grid-cols-2 md:p-5 lg:grid-cols-4">
            {items.map((item, index) => (
                <li key={`${item.title}-${index}`} className="flex items-center gap-3">
                    <span className="grid size-12 shrink-0 place-items-center rounded-full bg-[var(--accent-soft)] text-[var(--accent)]">
                        <StoreIcon name={item.icon} size={24} weight="fill" />
                    </span>
                    <span className="min-w-0">
                        <span className="block text-[14.5px] font-extrabold leading-tight">{item.title}</span>
                        {item.text && <span className="block text-[12.5px] text-[var(--muted)]">{item.text}</span>}
                    </span>
                </li>
            ))}
        </ul>
    );
}
