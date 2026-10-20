import { StoreIcon } from '@/components/storefront/icons';
import { text, type BlockProps } from './shared';

/**
 * Tres o cuatro promesas cortas con su ícono: domicilio, horario, formas de pago. En tarjeta (la
 * de siempre) o en una fila con líneas finas (`inline`), el de las tiendas de moda.
 */
export function BenefitsBlock({ data }: BlockProps) {
    const items = (data.items as { icon: string; title: string; text: string }[] | undefined) ?? [];

    if (items.length === 0) {
        return null;
    }

    if (text(data.style) === 'inline') {
        return (
            <ul className="grid border-y border-[var(--line)] sm:grid-cols-2 lg:grid-flow-col lg:grid-cols-none lg:divide-x lg:divide-[var(--line)]">
                {items.map((item, index) => (
                    <li key={`${item.title}-${index}`} className="flex items-center justify-center gap-2.5 px-4 py-4 text-center">
                        <StoreIcon name={item.icon} size={20} className="shrink-0" />
                        <span className="min-w-0 text-left">
                            <span className="block text-[13px] font-bold uppercase tracking-[0.06em]">{item.title}</span>
                            {item.text && <span className="block text-[12px] text-[var(--muted)]">{item.text}</span>}
                        </span>
                    </li>
                ))}
            </ul>
        );
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
