import { LightningIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { ProductShelf } from '@/components/storefront/ProductShelf';
import type { CardProduct } from '@/types/storefront';
import { text, type BlockProps } from './shared';

function remaining(endsAt: string): number {
    return Math.max(0, Math.floor((new Date(endsAt).getTime() - Date.now()) / 1000));
}

function two(value: number): string {
    return String(value).padStart(2, '0');
}

/**
 * Oferta relámpago: la promoción, su cuenta regresiva y sus artículos. Cuando llega a cero se
 * va sola; el servidor tampoco la vuelve a mandar.
 */
export function DealCountdownBlock({ data }: BlockProps) {
    const promotion = data.promotion as { name: string; ends_at: string } | null;
    const products = (data.products as CardProduct[] | undefined) ?? [];
    const [left, setLeft] = useState(() => (promotion ? remaining(promotion.ends_at) : 0));

    useEffect(() => {
        if (!promotion) {
            return;
        }

        setLeft(remaining(promotion.ends_at));
        const timer = setInterval(() => setLeft(remaining(promotion.ends_at)), 1000);

        return () => clearInterval(timer);
    }, [promotion]);

    if (!promotion || products.length === 0 || left <= 0) {
        return null;
    }

    const days = Math.floor(left / 86400);
    const hours = Math.floor((left % 86400) / 3600);
    const minutes = Math.floor((left % 3600) / 60);
    const seconds = left % 60;

    return (
        <div className="rounded-[var(--store-radius)] border-2 border-[var(--deal)] bg-[var(--deal-soft)] p-3 md:p-4">
            <ProductShelf
                title={text(data.title) || promotion.name}
                products={products}
                seeAllHref={(data.see_all_href as string | null) ?? null}
                heading={
                    <span className="flex items-center gap-2">
                        <span className="flex items-center gap-1 rounded-full bg-[var(--deal)] px-2.5 py-1 text-[12px] font-extrabold uppercase text-[var(--deal-ink)]">
                            <LightningIcon size={14} weight="fill" />
                            Termina en
                        </span>
                        <span className="font-extrabold tabular-nums text-[var(--deal)]" role="timer" aria-live="off" aria-label={`Termina en ${days} días, ${hours} horas y ${minutes} minutos`}>
                            {days > 0 && `${days}d `}
                            {two(hours)}:{two(minutes)}:{two(seconds)}
                        </span>
                    </span>
                }
            />
        </div>
    );
}
