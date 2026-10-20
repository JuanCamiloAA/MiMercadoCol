import { StoreIcon } from '@/components/storefront/icons';
import { StoreLink } from '@/components/storefront/store-context';
import { cn } from '@/lib/cn';
import { BlockTitle, text, type BlockProps } from './shared';

interface Item {
    category_id: number;
    label: string;
    icon: string;
    image: string | null;
    href: string;
    /** Solo en «Nombres grandes»: cuántos artículos se pueden comprar ahí. */
    count?: number;
}

const TITLE_POSITIONS: Record<string, string> = { top: 'justify-start', center: 'justify-center', bottom: 'justify-end' };

/**
 * Los estantes de la tienda: círculos con ícono o con foto, mosaicos, tarjetas altas, nombres
 * grandes en una línea o etiquetas. El ícono y la foto son los de la ficha de cada categoría (las
 * de foto traen un respaldo del servidor cuando a la categoría le falta).
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

            {style === 'text_list' && (
                <p className="flex flex-wrap items-baseline justify-center gap-x-3 gap-y-1 text-center text-[28px] font-semibold leading-tight md:text-[48px]">
                    {items.map((item, index) => (
                        <span key={item.category_id} className="inline-flex items-baseline gap-3">
                            {index > 0 && (
                                <span className="font-light text-[var(--muted)]" aria-hidden>
                                    /
                                </span>
                            )}
                            <StoreLink href={item.href} className="hover:text-[var(--accent)] hover:underline">
                                {item.label}
                                {typeof item.count === 'number' && <sup className="ml-1 text-[12px] font-semibold text-[var(--muted)] md:text-[15px]">{item.count}</sup>}
                            </StoreLink>
                        </span>
                    ))}
                </p>
            )}

            {style === 'tall_cards' && (
                <div className="scrollbar-none -mx-4 flex snap-x gap-3 overflow-x-auto px-4 pb-1 md:mx-0 md:grid md:grid-cols-3 md:overflow-visible md:px-0 lg:grid-cols-4">
                    {items.map((item) => (
                        <StoreLink
                            key={item.category_id}
                            href={item.href}
                            className="group relative block aspect-[3/4] w-[70%] shrink-0 snap-start overflow-hidden rounded-[var(--store-radius)] bg-[var(--surface-2)] sm:w-[45%] md:w-auto"
                        >
                            {item.image ? (
                                <img src={item.image} alt="" loading="lazy" className="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]" />
                            ) : (
                                <span className="absolute inset-0 grid place-items-center text-[var(--accent)]">
                                    <StoreIcon name={item.icon} size={56} weight="duotone" />
                                </span>
                            )}
                            {/* Sin foto no hay velo: la letra blanca sobre el gris del fondo no se leía. */}
                            {item.image && <span className="absolute inset-0 bg-gradient-to-t from-black/50 via-black/10 to-transparent" aria-hidden />}
                            <span
                                className={cn(
                                    'absolute inset-0 flex flex-col items-center gap-3 p-5 text-center',
                                    // Con el título al centro o arriba el velo de abajo no lo alcanza: la sombra lo sostiene.
                                    item.image ? 'text-white [text-shadow:0_1px_14px_rgba(0,0,0,0.45)]' : 'text-[var(--text)]',
                                    TITLE_POSITIONS[text(data.title_position)] ?? TITLE_POSITIONS.bottom,
                                )}
                            >
                                <span className="text-[20px] font-bold uppercase tracking-[0.06em] md:text-[24px]">{item.label}</span>
                                {text(data.button_label) && (
                                    <span className={cn('rounded-full px-5 py-2 text-[13px] font-bold', item.image ? 'bg-white text-[#14171A]' : 'bg-[var(--accent)] text-[var(--accent-ink)]')}>
                                        {text(data.button_label)}
                                    </span>
                                )}
                            </span>
                        </StoreLink>
                    ))}
                </div>
            )}

            {style === 'photo_circles' && (
                <div className="scrollbar-none -mx-4 flex gap-4 overflow-x-auto px-4 pb-1 md:mx-0 md:flex-wrap md:justify-center md:gap-6 md:overflow-visible md:px-0">
                    {items.map((item) => (
                        <StoreLink key={item.category_id} href={item.href} className="group flex w-[84px] shrink-0 flex-col items-center gap-2 text-center md:w-[112px]">
                            <span className="grid size-[76px] place-items-center overflow-hidden rounded-full bg-[var(--accent-soft)] text-[var(--accent)] ring-2 ring-[var(--line)] ring-offset-2 ring-offset-[var(--bg)] transition group-hover:ring-[var(--accent)] md:size-[100px]">
                                {item.image ? <img src={item.image} alt="" loading="lazy" className="h-full w-full object-cover" /> : <StoreIcon name={item.icon} size={34} weight="duotone" />}
                            </span>
                            <span className="line-clamp-2 text-[12.5px] font-semibold leading-tight md:text-[13.5px]">{item.label}</span>
                        </StoreLink>
                    ))}
                </div>
            )}

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
