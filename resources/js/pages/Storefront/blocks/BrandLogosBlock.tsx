import { useDocumentTheme } from '@/hooks/use-document-theme';
import { BlockTitle, TargetLink, text, type BlockProps, type MediaPayload, type Target } from './shared';

interface Brand {
    media: MediaPayload | null;
    /** El logo para fondo oscuro, si la marca lo tiene. */
    media_dark?: MediaPayload | null;
    text?: string | null;
    target?: Target | null;
}

/**
 * Franja de marcas en gris: el logo si lo hay, si no el nombre. En celular se desliza; en
 * computador van todas en una fila que se reparte el ancho.
 */
export function BrandLogosBlock({ data }: BlockProps) {
    const dark = useDocumentTheme() === 'dark';
    // En modo oscuro va el logo para fondo oscuro; sin él, el de siempre.
    const items = ((data.items as Brand[] | undefined) ?? [])
        .map((item) => (dark && item.media_dark ? { ...item, media: item.media_dark } : item))
        .filter((item) => item.media || text(item.text) !== '');

    if (items.length === 0) {
        return null;
    }

    return (
        <div>
            {text(data.title) !== '' && <BlockTitle>{text(data.title)}</BlockTitle>}
            <ul className="scrollbar-none -mx-4 flex items-center gap-8 overflow-x-auto px-4 py-2 md:mx-0 md:flex-wrap md:justify-around md:gap-10 md:overflow-visible md:px-0">
                {items.map((item, index) => (
                    <li key={index} className="shrink-0">
                        <TargetLink target={item.target ?? null} ariaLabel={item.media?.alt ?? text(item.text)} className="block opacity-60 grayscale transition hover:opacity-100 hover:grayscale-0">
                            {item.media ? (
                                <img src={item.media.url} alt={item.media.alt ?? ''} loading="lazy" className="h-9 w-auto max-w-[140px] object-contain md:h-11" />
                            ) : (
                                <span className="text-[20px] font-black uppercase tracking-[0.08em] text-[var(--muted)] md:text-[24px]">{text(item.text)}</span>
                            )}
                        </TargetLink>
                    </li>
                ))}
            </ul>
        </div>
    );
}
