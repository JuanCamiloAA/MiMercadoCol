import { BannerImage } from '@/components/storefront/BannerImage';
import { cn } from '@/lib/cn';
import { BlockTitle, TargetLink, TONES, text, type BlockProps, type MediaPayload, type Target } from './shared';

interface Tile {
    kind: 'image' | 'color';
    media: MediaPayload | null;
    media_mobile: MediaPayload | null;
    tone?: string;
    title?: string;
    text?: string;
    target?: Target | null;
}

const LAYOUTS: Record<string, string> = {
    '2': 'grid-cols-1 sm:grid-cols-2',
    '3': 'grid-cols-2 md:grid-cols-3',
    '1+2': 'grid-cols-2 md:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] md:grid-rows-2',
    '4': 'grid-cols-2 md:grid-cols-4',
};

/** Mosaico de dos a cuatro banners: imagen o color con texto, cada uno con su destino. */
export function BannerGridBlock({ data, priority = false }: BlockProps) {
    const tiles = (data.tiles as Tile[] | undefined) ?? [];
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

                    return (
                        <TargetLink
                            key={index}
                            target={tile.target}
                            ariaLabel={tile.kind === 'image' ? (tile.media?.alt ?? tile.title ?? undefined) : undefined}
                            className={cn(
                                'group relative flex overflow-hidden rounded-[var(--store-radius)]',
                                big ? 'col-span-2 aspect-[16/9] md:col-span-1 md:row-span-2 md:aspect-auto' : 'aspect-[4/3] md:aspect-[16/10]',
                                layout === '2' && 'aspect-[16/9]',
                                tile.kind === 'color' ? (TONES[tile.tone ?? 'accent'] ?? TONES.accent) : 'bg-[var(--surface-2)]',
                            )}
                        >
                            {tile.kind === 'image' ? (
                                <>
                                    <BannerImage
                                        media={tile.media}
                                        mobile={tile.media_mobile}
                                        priority={priority && index === 0}
                                        className="absolute inset-0 transition-transform duration-500 group-hover:scale-[1.03]"
                                        sizes="(max-width: 767px) 50vw, 600px"
                                    />
                                    {(tile.title || tile.text) && (
                                        <span className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-3.5 text-white md:p-4">
                                            {tile.title && <span className="block text-[17px] font-extrabold leading-tight md:text-[20px]">{tile.title}</span>}
                                            {tile.text && <span className="block text-[12.5px] opacity-90 md:text-[13.5px]">{tile.text}</span>}
                                        </span>
                                    )}
                                </>
                            ) : (
                                <span className="flex flex-col justify-end gap-1 p-4 md:p-5">
                                    {tile.title && (
                                        <span className={cn('font-black leading-tight', big ? 'text-[26px] md:text-[34px]' : 'text-[19px] md:text-[22px]')} style={{ fontFamily: 'var(--store-display)' }}>
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
