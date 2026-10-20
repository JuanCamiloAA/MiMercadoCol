import { CaretRightIcon, CheckIcon } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';
import { AddToCartControl } from '@/components/storefront/AddToCartControl';
import { ImageCarousel } from '@/components/storefront/ImageCarousel';
import { StoreIcon } from '@/components/storefront/icons';
import { Price } from '@/components/storefront/Price';
import { StockNote } from '@/components/storefront/StockNote';
import { StoreLink } from '@/components/storefront/store-context';
import StoreLayout from '@/layouts/StoreLayout';
import { money, number, UNIT_SHORT } from '@/lib/format';
import { StoreBlocks } from '@/pages/Storefront/blocks/StoreBlocks';
import type { CardProduct, StoreBlock } from '@/types/storefront';

interface ServiceProps {
    service: CardProduct & {
        description: string | null;
        category: { label: string; href: string } | null;
        tax_rate: number;
        pricing_mode: 'bundled' | 'itemized';
        /** Todas sus fotos, la portada primero (el carrusel). */
        images?: string[];
    };
    blocks: StoreBlock[];
}

/**
 * El detalle de un servicio en la tienda. Se vende tal cual el maestro: el cliente ve lo que
 * incluye pero no lo cambia; quien alista el pedido registra lo que de verdad usó.
 */
export default function StorefrontService({ service, blocks }: ServiceProps) {
    const includes = service.includes ?? [];
    // La presentación de cada artículo completo; el precio cambia lo que el servidor ya dijo.
    const [choices, setChoices] = useState<Record<number, number>>(() =>
        Object.fromEntries(includes.filter((part) => part.family?.chosen).map((part) => [part.family!.id, part.family!.chosen as number])),
    );
    const price = useMemo(
        () =>
            service.price +
            includes.reduce((sum, part) => sum + (part.family?.options.find((option) => option.product_id === choices[part.family!.id])?.delta ?? 0), 0),
        [service.price, includes, choices],
    );
    const item = useMemo(() => ({ ...service, kind: 'service' as const, choices }), [service, choices]);

    return (
        <StoreLayout title={service.name} current="catalog">
            <nav aria-label="Ruta" className="mb-3 flex flex-wrap items-center gap-1 text-[13px] text-[var(--muted)]">
                <StoreLink href="/tienda" className="hover:text-[var(--text)] hover:underline">
                    Inicio
                </StoreLink>
                {service.category && (
                    <>
                        <CaretRightIcon size={11} />
                        <StoreLink href={service.category.href} className="hover:text-[var(--text)] hover:underline">
                            {service.category.label}
                        </StoreLink>
                    </>
                )}
            </nav>

            <article className="grid gap-5 rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] p-3 md:grid-cols-2 md:gap-8 md:p-6">
                <ImageCarousel
                    images={service.images && service.images.length > 0 ? service.images : service.image ? [service.image] : []}
                    alt={service.name}
                    fit="cover"
                    resetKey={service.id}
                    fallback={
                        <span className="grid h-full w-full place-items-center text-[var(--muted)] opacity-50">
                            <StoreIcon name="Package" size={120} weight="duotone" />
                        </span>
                    }
                    overlay={
                        <span className="absolute left-3 top-3 z-10 rounded-[8px] bg-[var(--accent)] px-2.5 py-1 text-[13px] font-bold text-[var(--accent-ink)]">Servicio</span>
                    }
                />

                <div className="flex flex-col gap-3">
                    <h1 className="text-[24px] leading-tight md:text-[32px]">{service.name}</h1>
                    <Price price={price} size="xl" />
                    <p className="text-[12.5px] text-[var(--muted)]">Por servicio · IVA {service.tax_rate}% incluido</p>

                    {service.description && <p className="whitespace-pre-line text-[14px] leading-relaxed text-[var(--muted)]">{service.description}</p>}

                    {includes.length > 0 && (
                        <section className="rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface-2)] p-3">
                            <h2 className="mb-1.5 text-[15px]">Incluye</h2>
                            <ul className="flex flex-col gap-1 text-[13.5px]">
                                {includes.map((part) => (
                                    <li key={part.name} className="flex items-start gap-1.5">
                                        <CheckIcon size={15} weight="bold" className="mt-0.5 shrink-0 text-[var(--accent)]" />
                                        <span className="min-w-0 flex-1">
                                            {number(part.quantity)} {UNIT_SHORT[part.unit] ?? ''} {part.name}
                                            {part.family && part.family.options.length > 1 && (
                                                <span className="mt-1.5 flex flex-wrap gap-1.5" role="radiogroup" aria-label={`Presentación de ${part.name}`}>
                                                    {part.family.options.map((option) => {
                                                        const on = choices[part.family!.id] === option.product_id;

                                                        return (
                                                            <button
                                                                key={option.product_id}
                                                                type="button"
                                                                role="radio"
                                                                aria-checked={on}
                                                                aria-disabled={!option.available || undefined}
                                                                disabled={!option.available}
                                                                onClick={() => setChoices((current) => ({ ...current, [part.family!.id]: option.product_id }))}
                                                                className={
                                                                    on
                                                                        ? 'min-h-[36px] rounded-full border-2 border-[var(--accent)] bg-[var(--accent-soft)] px-3 text-[13px] font-bold text-[var(--accent)]'
                                                                        : option.available
                                                                          ? 'min-h-[36px] rounded-full border border-[var(--line)] bg-[var(--surface)] px-3 text-[13px] font-semibold hover:border-[var(--accent)]'
                                                                          : 'min-h-[36px] cursor-not-allowed rounded-full border border-dashed border-[var(--line)] px-3 text-[13px] text-[var(--muted)] line-through'
                                                                }
                                                            >
                                                                {option.label}
                                                                {option.delta !== 0 && <span className="ml-1 text-[11.5px] font-normal">{option.delta > 0 ? '+' : '−'}{money(Math.abs(option.delta))}</span>}
                                                                {!option.available && <span className="sr-only"> (agotado)</span>}
                                                            </button>
                                                        );
                                                    })}
                                                </span>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            {service.pricing_mode === 'itemized' && <p className="mt-2 text-[12px] text-[var(--muted)]">El precio ya suma el servicio y cada artículo.</p>}
                        </section>
                    )}

                    <StockNote stock={service.stock} className="text-[13px]" />

                    <div className="mt-1 max-w-[360px]">
                        <AddToCartControl item={item} size="lg" />
                    </div>
                </div>
            </article>

            {blocks.length > 0 && <StoreBlocks blocks={blocks} className="mt-8" />}
        </StoreLayout>
    );
}
