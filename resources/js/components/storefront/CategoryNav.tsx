import { CaretDownIcon, ListIcon, SealPercentIcon } from '@phosphor-icons/react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import type { StoreShell } from '@/types/storefront';
import { StoreIcon } from './icons';
import { CategoryThumb, MegaMenu } from './MegaMenu';
import { Sheet } from './Sheet';
import { StoreLink, usePreviewMode } from './store-context';

/** Todas las categorías en una hoja, para el celular. */
export function CategorySheet({ open, onClose, shell }: { open: boolean; onClose: () => void; shell: StoreShell }) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const nav = shell.settings.nav;

    return (
        <Sheet open={open} onClose={onClose} title="Categorías" side="left">
            <ul className="flex flex-col gap-1 p-3">
                {nav.show_deals_chip && (
                    <li>
                        <StoreLink
                            href="/tienda/ofertas"
                            onClick={onClose}
                            className="flex min-h-[52px] items-center gap-3 rounded-[14px] bg-[var(--deal-soft)] px-3 text-[15px] font-bold text-[var(--deal)]"
                        >
                            <SealPercentIcon size={22} weight="fill" />
                            {nav.deals_label}
                        </StoreLink>
                    </li>
                )}
                {shell.settings.categories.map((category) => (
                    <li key={category.id}>
                        <div className="flex items-center gap-1">
                            <StoreLink
                                href={category.href}
                                onClick={onClose}
                                className="flex min-h-[52px] min-w-0 flex-1 items-center gap-3 rounded-[14px] px-2 text-[15px] font-semibold hover:bg-[var(--surface-2)]"
                            >
                                <CategoryThumb category={category} />
                                <span className="truncate">{category.name}</span>
                            </StoreLink>
                            {(category.children ?? []).length > 0 && (
                                <button
                                    type="button"
                                    onClick={() => setExpanded(expanded === category.id ? null : category.id)}
                                    aria-expanded={expanded === category.id}
                                    aria-label={`Subcategorías de ${category.name}`}
                                    className="grid size-11 shrink-0 place-items-center rounded-full text-[var(--muted)] hover:bg-[var(--surface-2)]"
                                >
                                    <CaretDownIcon size={16} weight="bold" className={cn('transition-transform', expanded === category.id && 'rotate-180')} />
                                </button>
                            )}
                        </div>
                        {expanded === category.id && (
                            <ul className="mb-1 ml-14 flex flex-col">
                                {(category.children ?? []).map((child) => (
                                    <li key={child.id}>
                                        <StoreLink href={child.href} onClick={onClose} className="flex min-h-[44px] items-center text-[14px] text-[var(--muted)] hover:text-[var(--text)]">
                                            {child.name}
                                        </StoreLink>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </li>
                ))}
            </ul>
        </Sheet>
    );
}

/**
 * El menú de categorías: «Todas las categorías», el chip de ofertas en rojo y las categorías
 * (o los enlaces) que eligió la sede, con su ícono o su foto. En celular es una fila que se
 * desliza.
 */
export function CategoryNav({ shell, onOpenSheet, currentPath, desktop = false }: { shell: StoreShell; onOpenSheet: () => void; currentPath: string; desktop?: boolean }) {
    const nav = shell.settings.nav;
    const [mega, setMega] = useState(false);
    const text = nav.style === 'text';
    // «Todo en una fila» con menú en texto: en computador el menú va dentro del encabezado.
    const insideHeader = desktop && text && shell.settings.header.layout === 'inline';
    const { interactive } = usePreviewMode();
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!mega) {
            return;
        }

        const close = (event: MouseEvent) => {
            if (container.current && !container.current.contains(event.target as Node)) {
                setMega(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [mega]);

    if (!nav.enabled || insideHeader) {
        return null;
    }

    const hasTree = shell.settings.categories.length > 0;
    const openMenu = () => interactive && (nav.mega_menu && desktop ? setMega((open) => !open) : onOpenSheet());

    // «Texto en mayúsculas pequeñas»: sin chips ni bordes, centrado bajo el logo si va al centro.
    if (text) {
        return (
            <nav ref={container} aria-label="Categorías" className="relative border-b border-[var(--line)] bg-[var(--surface)] text-[var(--text)]">
                <div
                    className={cn(
                        'scrollbar-none mx-auto flex w-full max-w-[var(--store-max,1280px)] items-center gap-6 overflow-x-auto px-4 py-3 lg:px-[var(--store-gutter,1.5rem)]',
                        shell.settings.header.layout === 'center' && 'md:justify-center',
                    )}
                >
                    {nav.show_all_categories && hasTree && (
                        <button type="button" onClick={openMenu} aria-expanded={mega} className="inline-flex shrink-0 items-center gap-1 text-[12.5px] font-semibold uppercase tracking-[0.08em] hover:opacity-70">
                            Categorías
                            <CaretDownIcon size={12} weight="bold" className={cn('transition-transform', mega && 'rotate-180')} />
                        </button>
                    )}
                    {nav.show_deals_chip && (
                        <StoreLink href="/tienda/ofertas" className="shrink-0 text-[12.5px] font-bold uppercase tracking-[0.08em] text-[var(--deal)] hover:underline">
                            {nav.deals_label}
                        </StoreLink>
                    )}
                    {nav.items.map((item, position) => {
                        const active = currentPath === item.href;
                        const className = cn(
                            'shrink-0 text-[12.5px] font-semibold uppercase tracking-[0.08em] hover:opacity-70',
                            active && nav.active_style === 'underline' && 'underline decoration-2 underline-offset-[6px]',
                        );

                        return item.opens_menu ? (
                            <button key={`${item.href}-${position}`} type="button" onClick={openMenu} aria-expanded={mega} className={cn(className, 'inline-flex items-center gap-1')}>
                                {item.label}
                                <CaretDownIcon size={12} weight="bold" />
                            </button>
                        ) : (
                            <StoreLink key={`${item.href}-${position}`} href={item.href} className={className}>
                                {item.label}
                            </StoreLink>
                        );
                    })}
                </div>

                {mega && <MegaMenu categories={shell.settings.categories} onClose={() => setMega(false)} />}
            </nav>
        );
    }

    return (
        <nav ref={container} aria-label="Categorías" className="relative border-b border-[var(--line)] bg-[var(--surface)] text-[var(--text)]">
            <div className="scrollbar-none mx-auto flex w-full max-w-[var(--store-max,1280px)] items-center gap-1.5 overflow-x-auto px-4 py-2 lg:px-[var(--store-gutter,1.5rem)]">
                {nav.show_all_categories && hasTree && (
                    <>
                        {/* Computador: el menú grande. Celular: la hoja. */}
                        <button
                            type="button"
                            onClick={() => interactive && (nav.mega_menu ? setMega((open) => !open) : onOpenSheet())}
                            aria-expanded={mega}
                            className="hidden h-10 shrink-0 items-center gap-2 rounded-full bg-[var(--text)] px-4 text-[13.5px] font-bold text-[var(--surface)] hover:opacity-90 md:flex"
                        >
                            <ListIcon size={18} weight="bold" />
                            Todas las categorías
                            <CaretDownIcon size={13} weight="bold" className={cn('transition-transform', mega && 'rotate-180')} />
                        </button>
                        <button
                            type="button"
                            onClick={() => interactive && onOpenSheet()}
                            className="flex h-10 shrink-0 items-center gap-1.5 rounded-full bg-[var(--text)] px-3.5 text-[13.5px] font-bold text-[var(--surface)] md:hidden"
                        >
                            <ListIcon size={17} weight="bold" />
                            Categorías
                        </button>
                    </>
                )}

                {nav.show_deals_chip && (
                    <StoreLink
                        href="/tienda/ofertas"
                        className={cn(
                            'flex h-10 shrink-0 items-center gap-1.5 rounded-full px-3.5 text-[13.5px] font-extrabold transition-colors',
                            currentPath.startsWith('/tienda/ofertas')
                                ? 'bg-[var(--deal)] text-[var(--deal-ink)]'
                                : 'bg-[var(--deal-soft)] text-[var(--deal)] hover:bg-[var(--deal)] hover:text-[var(--deal-ink)]',
                        )}
                    >
                        <SealPercentIcon size={18} weight="fill" />
                        {nav.deals_label}
                    </StoreLink>
                )}

                {nav.items.map((item, position) => {
                    const active = currentPath === item.href;
                    const className = cn(
                        'flex h-10 shrink-0 items-center gap-2 rounded-full border px-3 text-[13.5px] font-semibold transition-colors',
                        active
                            ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]'
                            : item.highlight
                              ? 'border-[var(--accent)] text-[var(--accent)] hover:bg-[var(--accent-soft)]'
                              : 'border-[var(--line)] hover:border-[var(--accent)] hover:text-[var(--accent)]',
                        active && nav.active_style === 'underline' && 'underline decoration-2 underline-offset-4',
                    );
                    const content = (
                        <>
                            {item.image ? (
                                <img src={item.image} alt="" className="size-6 rounded-full object-cover" />
                            ) : item.icon ? (
                                <StoreIcon name={item.icon} size={17} weight={active ? 'fill' : 'regular'} />
                            ) : null}
                            {item.label}
                        </>
                    );

                    // «Categorías ⌄»: abre el menú grande (en celular, la hoja) en vez de ir a una página.
                    return item.opens_menu ? (
                        <button key={`${item.href}-${position}`} type="button" onClick={openMenu} aria-expanded={mega} className={className}>
                            {content}
                            <CaretDownIcon size={13} weight="bold" />
                        </button>
                    ) : (
                        <StoreLink key={`${item.href}-${position}`} href={item.href} className={className}>
                            {content}
                        </StoreLink>
                    );
                })}
            </div>

            {mega && <MegaMenu categories={shell.settings.categories} onClose={() => setMega(false)} />}
        </nav>
    );
}
