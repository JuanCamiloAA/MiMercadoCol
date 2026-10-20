import { BannerImage, bannerMedia } from '@/components/storefront/BannerImage';
import { cn } from '@/lib/cn';
import { TargetLink, text, type BlockProps, type MediaPayload, type Target } from './shared';

/**
 * Imagen y texto en dos columnas. En celular la imagen va arriba; `reverse` la pone a la derecha
 * en computador. `panel` lleva el texto sobre un color suave de la marca.
 */
export function ImageTextBlock({ data, priority = false }: BlockProps) {
    const media = (data.media as MediaPayload | null) ?? null;
    const title = text(data.title);

    if (!media || title === '') {
        return null;
    }

    const panel = text(data.style) === 'panel';

    return (
        <div className={cn('grid overflow-hidden rounded-[var(--store-radius)] md:grid-cols-2 md:items-stretch', !panel && 'gap-6 md:gap-12')}>
            <div className={cn('relative aspect-[4/5] overflow-hidden bg-[var(--surface-2)] md:aspect-auto md:min-h-[420px]', !panel && 'rounded-[var(--store-radius)]', data.reverse === true && 'md:order-2')}>
                <BannerImage {...bannerMedia(data)} priority={priority} className="absolute inset-0" sizes="(max-width: 767px) 100vw, 50vw" />
            </div>
            <div className={cn('flex flex-col justify-center gap-3', panel ? 'bg-[var(--accent-soft)] p-6 md:p-12' : 'py-2', data.reverse === true && 'md:order-1')}>
                {text(data.eyebrow) && <p className="text-[12px] font-bold uppercase tracking-[0.16em] text-[var(--muted)]">{text(data.eyebrow)}</p>}
                <h2 className="text-[26px] leading-[1.1] md:text-[38px]">{title}</h2>
                {text(data.text) && <p className="max-w-[34rem] whitespace-pre-line text-[15px] leading-relaxed text-[var(--muted)] md:text-[16px]">{text(data.text)}</p>}
                {text(data.button_label) && (
                    <TargetLink
                        target={(data.target as Target | null) ?? null}
                        className="mt-2 inline-flex h-11 w-fit items-center rounded-[var(--store-btn-radius,var(--store-radius-sm))] bg-[var(--accent)] px-6 text-[14px] font-bold text-[var(--accent-ink)] hover:brightness-110"
                    >
                        {text(data.button_label)}
                    </TargetLink>
                )}
            </div>
        </div>
    );
}
