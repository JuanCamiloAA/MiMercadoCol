import { BellRingingIcon, LightningIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { ProductShelf } from '@/components/storefront/ProductShelf';
import type { CardProduct } from '@/types/storefront';
import { TargetLink, text, type BlockProps, type Target } from './shared';

function remaining(endsAt: string): number {
    return Math.max(0, Math.floor((new Date(endsAt).getTime() - Date.now()) / 1000));
}

function two(value: number): string {
    return String(value).padStart(2, '0');
}

/**
 * Lanzamiento: una cuenta regresiva grande (días, horas, minutos) hasta la fecha que puso la sede,
 * con «Avísame» que abre su WhatsApp. Cuando llega a cero se va sola.
 */
function DropCountdown({ data }: BlockProps) {
    const drop = data.drop as { starts_at: string; notify: Target } | null;
    const [left, setLeft] = useState(() => (drop ? remaining(drop.starts_at) : 0));

    useEffect(() => {
        if (!drop) {
            return;
        }

        setLeft(remaining(drop.starts_at));
        const timer = setInterval(() => setLeft(remaining(drop.starts_at)), 1000);

        return () => clearInterval(timer);
    }, [drop]);

    if (!drop || left <= 0) {
        return null;
    }

    const parts: [number, string][] = [
        [Math.floor(left / 86400), 'días'],
        [Math.floor((left % 86400) / 3600), 'horas'],
        [Math.floor((left % 3600) / 60), 'minutos'],
        [left % 60, 'segundos'],
    ];

    return (
        <div className="flex flex-col items-center gap-4 py-6 text-center md:py-10">
            {text(data.title) && <h2 className="text-[24px] leading-tight md:text-[36px]">{text(data.title)}</h2>}
            {text(data.text) && <p className="max-w-[36rem] text-[15px] text-[var(--muted)]">{text(data.text)}</p>}
            <div className="flex gap-3 md:gap-6" role="timer" aria-live="off" aria-label={`Faltan ${parts[0][0]} días, ${parts[1][0]} horas y ${parts[2][0]} minutos`}>
                {parts.map(([value, label]) => (
                    <div key={label} className="flex min-w-[64px] flex-col items-center md:min-w-[96px]">
                        <span className="text-[38px] font-extrabold leading-none tabular-nums md:text-[64px]" data-store-price="">
                            {two(value)}
                        </span>
                        <span className="mt-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--muted)]">{label}</span>
                    </div>
                ))}
            </div>
            {drop.notify?.href && (
                <TargetLink
                    target={drop.notify}
                    className="mt-2 inline-flex h-11 items-center gap-2 rounded-[var(--store-btn-radius,var(--store-radius-sm))] bg-[var(--text)] px-6 text-[14px] font-bold text-[var(--bg)] hover:opacity-90"
                >
                    <BellRingingIcon size={17} weight="bold" />
                    Avísame
                </TargetLink>
            )}
        </div>
    );
}

/**
 * Oferta relámpago: la promoción, su cuenta regresiva y sus artículos. Cuando llega a cero se
 * va sola; el servidor tampoco la vuelve a mandar. Con estilo «Lanzamiento», `DropCountdown`.
 */
export function DealCountdownBlock({ data, ...rest }: BlockProps) {
    if (text(data.style) === 'drop') {
        return <DropCountdown data={data} {...rest} />;
    }

    return <FlashDeal data={data} {...rest} />;
}

function FlashDeal({ data }: BlockProps) {
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
