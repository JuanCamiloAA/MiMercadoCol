import { StoreIcon } from '@/components/storefront/icons';
import { StoreLink } from '@/components/storefront/store-context';
import { BlockTitle, text, type BlockProps } from './shared';

interface Item {
    category_id: number;
    label: string;
    icon: string;
    image: string | null;
    href: string;
}

/**
 * Los estantes de la tienda: círculos con ícono, mosaicos con foto o etiquetas. El ícono y la
 * foto son los de la ficha de cada categoría.
 */
export function CategoriesBlock({ data }: BlockProps) {
    const items = (data.items as Item[] | undefined) ?? [];
    const style = text(data.style) || 'icon_circles';

    if (items.length === 0) {
        return null;
    }

    return (
        <div>
            {text(data.title) !== '' && <BlockTitle>{text(data.title)}</BlockTitle>}

            {style === 'chips' && (
                <div className="scrollbar-none -mx-4 flex gap-2 overflow-x-auto px-4 md:mx-0 md:flex-wrap md:px-0">
                    {items.map((item) => (
                        <StoreLink
                            key={item.category_id}
                            href={item.href}
                            className="flex h-11 shrink-0 items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-4 text-[14px] font-semibold hover:border-[var(--accent)] hover:text-[var(--accent)]"
                        >
                            <StoreIcon name={item.icon} size={18} weight="fill" className="text-[var(--accent)]" />
                            {item.label}
                        </StoreLink>
                    ))}
                </div>
            )}

            {style === 'image_tiles' && (
                <div className="grid grid-cols-3 gap-2.5 sm:grid-cols-4 md:grid-cols-6 md:gap-3">
                    {items.map((item) => (
                        <StoreLink
                            key={item.category_id}
                            href={item.href}
                            className="group flex flex-col overflow-hidden rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] hover:border-[var(--accent)]"
                        >
                            <span className="grid aspect-square place-items-center overflow-hidden bg-[var(--accent-soft)] text-[var(--accent)]">
                                {item.image ? (
                                    <img src={item.image} alt="" loading="lazy" className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105" />
                                ) : (
                                    <StoreIcon name={item.icon} size={44} weight="duotone" />
                                )}
                            </span>
                            <span className="line-clamp-2 px-2 py-2 text-center text-[12.5px] font-bold leading-tight md:text-[13.5px]">{item.label}</span>
                        </StoreLink>
                    ))}
                </div>
            )}

            {style === 'icon_circles' && (
                <div className="scrollbar-none -mx-4 flex gap-3 overflow-x-auto px-4 pb-1 md:mx-0 md:grid md:grid-cols-8 md:gap-4 md:overflow-visible md:px-0 lg:grid-cols-10">
                    {items.map((item) => (
                        <StoreLink key={item.category_id} href={item.href} className="group flex w-[76px] shrink-0 flex-col items-center gap-2 text-center md:w-auto">
                            <span className="grid size-[68px] place-items-center overflow-hidden rounded-full bg-[var(--accent-soft)] text-[var(--accent)] ring-2 ring-transparent transition group-hover:ring-[var(--accent)] md:size-[84px]">
                                {item.image ? (
                                    <img src={item.image} alt="" loading="lazy" className="h-full w-full object-cover" />
                                ) : (
                                    <StoreIcon name={item.icon} size={34} weight="duotone" />
                                )}
                            </span>
                            <span className="line-clamp-2 text-[12.5px] font-semibold leading-tight md:text-[13px]">{item.label}</span>
                        </StoreLink>
                    ))}
                </div>
            )}
        </div>
    );
}
