import { BannerImage, bannerMedia } from '@/components/storefront/BannerImage';
import { useDocumentTheme } from '@/hooks/use-document-theme';
import { cn } from '@/lib/cn';
import { bannerStyle, buttonClasses, positionClasses, RATIO_CLASSES, themedItem, titleClass } from './banner-style';
import { BannerText } from './BannerText';
import { BlockTitle, TargetLink, TONES, text, type BlockProps, type MediaPayload, type Target } from './shared';

interface Tile {
    kind: 'image' | 'color';
    media: MediaPayload | null;
    media_mobile: MediaPayload | null;
    tone?: string;
    title?: string;
    text?: string;
    button_label?: string | null;
    target?: Target | null;
    /** «Diseño de este banner»: vacío, como siempre. */
    text_color?: 'light' | 'dark' | null;
    overlay?: number | null;
    style?: unknown;
}

const LAYOUTS: Record<string, string> = {
    '1': 'grid-cols-1',
    '2': 'grid-cols-1 sm:grid-cols-2',
    '3': 'grid-cols-2 md:grid-cols-3',
    '1+2': 'grid-cols-2 md:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] md:grid-rows-2',
    '4': 'grid-cols-2 md:grid-cols-4',
};

/** Mosaico de dos a cuatro banners: imagen o color con texto, cada uno con su destino. */
export function BannerGridBlock({ data, priority = false }: BlockProps) {
    const scheme = useDocumentTheme();
    // En modo oscuro cada banner lleva lo que cambió allá (color del texto, velo, diseño).
    const tiles = ((data.tiles as Tile[] | undefined) ?? []).map((tile) => themedItem(tile, scheme));
    // Las opciones numéricas llegan como número desde el servidor.
    const layout = String(data.layout ?? '2');

    if (tiles.length === 0) {
        return null;
    }

    return (
        <div>
            {text(data.title) !== '' && <BlockTitle>{text(data.title)}</BlockTitle>}
            <div className={cn('grid gap-3', LAYOUTS[layout] ?? LAYOUTS['2'])}>
                {tiles.map((tile, index) => {
                    const big = layout === '1+2' && index === 0;
                    const style = bannerStyle(tile.style);
                    const ratio = style.ratio ? RATIO_CLASSES[style.ratio] : null;
                    const position = positionClasses(style.text_position);

                    return (
                        <TargetLink
                            key={index}
                            target={tile.target}
                            ariaLabel={tile.kind === 'image' ? (tile.media?.alt ?? tile.title ?? undefined) : undefined}
                            className={cn(
                                'group relative flex overflow-hidden rounded-[var(--store-radius)]',
                                // Una proporción elegida manda sobre la del diseño del mosaico.
                                ratio ?? (big ? 'col-span-2 aspect-[16/9] md:col-span-1 md:row-span-2 md:aspect-auto' : 'aspect-[4/3] md:aspect-[16/10]'),
                                ratio && big && 'col-span-2 md:col-span-1 md:row-span-2',
                                !ratio && layout === '2' && 'aspect-[16/9]',
                                !ratio && layout === '1' && 'aspect-[16/8] md:aspect-[21/7]',
                                tile.kind === 'color' ? (TONES[tile.tone ?? 'accent'] ?? TONES.accent) : 'bg-[var(--surface-2)]',
                            )}
                        >
                            {tile.kind === 'image' ? (
                                <>
                                    <BannerImage
                                        {...bannerMedia(tile)}
                                        priority={priority && index === 0}
                                        className="absolute inset-0 transition-transform duration-500 group-hover:scale-[1.03]"
                                        sizes="(max-width: 767px) 50vw, 600px"
                                    />
                                    {(tile.title || tile.text || tile.button_label) && (
                                        <BannerText
                                            light={tile.text_color !== 'dark'}
                                            overlay={tile.overlay}
                                            shape={style.overlay_style}
                                            position={position ?? (tile.button_label && !tile.title && !tile.text ? 'flex flex-col justify-end items-center' : null)}
                                            className="p-3.5 md:p-4"
                                        >
                                            {tile.title && <span className={cn('block font-extrabold', titleClass(style.title_size, 'text-[17px] md:text-[20px]', 'leading-tight'))}>{tile.title}</span>}
                                            {tile.text && <span className="block text-[12.5px] opacity-90 md:text-[13.5px]">{tile.text}</span>}
                                            {tile.button_label && (
                                                <span
                                                    className={cn(
                                                        'mt-2 inline-flex w-fit items-center text-[13.5px] font-extrabold',
                                                        buttonClasses(style.button_style, tile.text_color !== 'dark') ?? 'rounded-full bg-[var(--accent)] px-4 py-2 text-[var(--accent-ink)]',
                                                    )}
                                                >
                                                    {tile.button_label}
                                                </span>
                                            )}
                                        </BannerText>
                                    )}
                                </>
                            ) : (
                                <span className={cn('flex w-full flex-col justify-end gap-1 p-4 md:p-5', position)}>
                                    {tile.title && (
                                        <span
                                            className={cn('font-black', titleClass(style.title_size, big ? 'text-[26px] md:text-[34px]' : 'text-[19px] md:text-[22px]', 'leading-tight'))}
                                            style={{ fontFamily: 'var(--store-display)' }}
                                        >
                                            {tile.title}
                                        </span>
                                    )}
                                    {tile.text && <span className="text-[13px] opacity-90 md:text-[14px]">{tile.text}</span>}
                                </span>
                            )}
                        </TargetLink>
                    );
                })}
            </div>
        </div>
    );
}
