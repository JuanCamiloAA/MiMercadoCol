import { EyeSlashIcon, SquaresFourIcon } from '@phosphor-icons/react';
import { useContext } from 'react';
import { SectionShell } from '@/components/sections/SectionShell';
import { BlockSelectionContext } from '@/components/storefront/store-context';
import { cn } from '@/lib/cn';
import type { StoreBlock } from '@/types/storefront';
import { AnnouncementBlock } from './AnnouncementBlock';
import { BannerGridBlock } from './BannerGridBlock';
import { BenefitsBlock } from './BenefitsBlock';
import { BrandLogosBlock } from './BrandLogosBlock';
import { CategoriesBlock } from './CategoriesBlock';
import { CtaBandBlock } from './CtaBandBlock';
import { DealCountdownBlock } from './DealCountdownBlock';
import { FaqBlock } from './FaqBlock';
import { HeroBlock } from './HeroBlock';
import { ImageTextBlock } from './ImageTextBlock';
import { LookBlock } from './LookBlock';
import { ProductGridBlock } from './ProductGridBlock';
import { PromoCarouselBlock } from './PromoCarouselBlock';
import { RichTextBlock } from './RichTextBlock';
import type { BlockProps } from './shared';
import { StoreInfoBlock } from './StoreInfoBlock';
import { TickerBlock } from './TickerBlock';
import { MediaCarouselBlock, VideoBlock, VideoShowcaseBlock } from './VideoBlocks';

export { StoreIcon } from '@/components/storefront/icons';
export type { StoreBlock };

/**
 * El renderizador de cada tipo. Un tipo nuevo se suma aquí, a `BlockCatalogue::TYPES` y a su
 * clase en `app/Storefront/Blocks/`.
 */
const RENDERERS: Record<string, (props: BlockProps) => React.ReactNode> = {
    announcement: AnnouncementBlock,
    hero: HeroBlock,
    banner_grid: BannerGridBlock,
    categories: CategoriesBlock,
    product_grid: ProductGridBlock,
    deal_countdown: DealCountdownBlock,
    promo_carousel: PromoCarouselBlock,
    media_carousel: MediaCarouselBlock,
    video: VideoBlock,
    video_showcase: VideoShowcaseBlock,
    cta_band: CtaBandBlock,
    benefits: BenefitsBlock,
    rich_text: RichTextBlock,
    image_text: ImageTextBlock,
    look: LookBlock,
    brand_logos: BrandLogosBlock,
    ticker: TickerBlock,
    faq: FaqBlock,
    store_info: StoreInfoBlock,
};

/** Los tipos que llevan imagen grande: el primero de la página pide prioridad al navegador. */
const BANNERS = ['hero', 'banner_grid', 'promo_carousel', 'media_carousel', 'image_text', 'look'];

interface BlocksProps {
    blocks: StoreBlock[];
    /** El bloque que se está editando, para resaltarlo en la vista previa. */
    activeUuid?: string | null;
    onSelect?: (uuid: string) => void;
    /** En la vista previa se ven también las láminas fuera de vigencia. */
    draft?: boolean;
    className?: string;
}

/**
 * Por qué un bloque no pintaría nada. En la tienda eso está bien (un carrusel sin láminas no
 * ocupa lugar), pero en la vista previa quien edita no sabría dónde quedó lo que acaba de
 * agregar: ahí se pinta un aviso con lo que le falta.
 */
function emptyReason(block: StoreBlock): string | null {
    const data = block.data;
    const none = (key: string) => !Array.isArray(data[key]) || (data[key] as unknown[]).length === 0;
    const blank = (key: string) => typeof data[key] !== 'string' || (data[key] as string).trim() === '';

    switch (block.type) {
        case 'announcement':
            return blank('text') ? 'El aviso no tiene texto todavía.' : null;
        case 'hero':
            return none('slides') ? 'El banner principal no tiene láminas. Agrega la primera con su imagen en el panel de la izquierda.' : null;
        case 'promo_carousel':
            return none('slides') ? 'El carrusel no tiene láminas. Agrega la primera con su imagen en el panel de la izquierda.' : null;
        case 'media_carousel':
            return none('slides') ? 'El carrusel de imágenes y videos no tiene láminas. Agrega la primera con una imagen o un video.' : null;
        case 'video':
        case 'video_showcase':
            return !data.video ? 'Sube o elige el video en el panel de la izquierda. Sin video, el bloque no sale en la tienda.' : null;
        case 'banner_grid':
            return none('tiles') ? 'El mosaico no tiene banners todavía.' : null;
        case 'categories':
            return none('items') ? 'No hay categorías para mostrar con la regla elegida.' : null;
        case 'product_grid':
            if (!none('products') || data.placeholder) {
                return null;
            }

            // La tienda no muestra lo que no tiene disponible: el estante no está roto, está sin existencias.
            return data.out_of_stock_only
                ? 'Todos los artículos de este estante están sin existencias. Siguen publicados y vuelven solos cuando entre mercancía.'
                : 'Este estante no tiene artículos con la regla elegida. Prueba con otra fuente o revisa que los artículos estén en línea.';
        case 'deal_countdown':
            if (data.style === 'drop') {
                return !data.drop ? 'El lanzamiento no tiene una fecha por venir. Ponle la fecha en «Sale el»; cuando pasa, el bloque se va solo.' : null;
            }

            if (!data.promotion) {
                return 'No hay una promoción vigente con fecha de fin. Elige una o créala en Mercadeo › Promociones.';
            }

            return none('products') ? 'La promoción no tiene artículos a la venta en línea.' : null;
        case 'image_text':
            if (!data.media) {
                return 'Elige la imagen en el panel de la izquierda. Sin imagen, el bloque no sale en la tienda.';
            }

            return blank('title') ? 'Ponle un título: sin título el bloque no sale en la tienda.' : null;
        case 'look':
            if (!data.media) {
                return 'Elige la foto del look en el panel de la izquierda.';
            }

            return none('items') ? 'No hay prendas para mostrar: elige cuáles o revisa que estén en línea y con disponible.' : null;
        case 'brand_logos':
            return none('items') ? 'Agrega las marcas con su logo o su nombre.' : null;
        case 'ticker':
            return none('items') ? 'Agrega las frases de la cinta.' : null;
        case 'cta_band':
            return blank('title') ? 'La franja no tiene título todavía.' : null;
        case 'benefits':
            return none('items') ? 'Sin beneficios todavía.' : null;
        case 'faq':
            return none('items') ? 'Sin preguntas todavía.' : null;
        case 'rich_text':
            return blank('title') && blank('body') ? 'El texto está vacío.' : null;
        default:
            return null;
    }
}

/**
 * Pinta los bloques de una página de la tienda.
 *
 * El mismo componente sirve al cliente y a la vista previa del editor. Si fueran dos, la vista
 * previa terminaría mintiendo.
 */
export function StoreBlocks({ blocks, activeUuid: givenUuid, onSelect: givenSelect, draft: givenDraft, className }: BlocksProps) {
    // En la vista previa la selección llega por contexto: las páginas no la conocen.
    const selection = useContext(BlockSelectionContext);
    const activeUuid = givenUuid ?? selection?.activeUuid ?? null;
    const onSelect = givenSelect ?? selection?.onSelect;
    const draft = givenDraft ?? selection !== null;
    const firstBanner = blocks.findIndex((block) => BANNERS.includes(block.type));

    if (blocks.length === 0) {
        return null;
    }

    return (
        <div className={cn('flex flex-col gap-[calc(var(--store-gap)*1.6)]', className)}>
            {blocks.map((block, index) => {
                const Renderer = RENDERERS[block.type];

                if (!Renderer) {
                    return null;
                }

                return (
                    <SectionShell
                        key={block.uuid}
                        surface="store"
                        appearance={block.appearance}
                        replayKey={draft ? JSON.stringify(block.appearance ?? null) : undefined}
                        data-block-uuid={block.uuid}
                        // Un bloque solo de celular no aparece en computador, y al revés (`sections.css`).
                        data-devices={block.devices === 'all' ? undefined : block.devices}
                        aria-label={typeof block.data.title === 'string' && block.data.title !== '' ? block.data.title : undefined}
                        onClickCapture={
                            onSelect
                                ? (event) => {
                                      event.preventDefault();
                                      onSelect(block.uuid);
                                  }
                                : undefined
                        }
                        className={cn(
                            'min-w-0',
                            onSelect && 'cursor-pointer',
                            activeUuid === block.uuid && 'rounded-[14px] outline outline-2 outline-offset-4 outline-[var(--accent)]',
                        )}
                    >
                        {activeUuid === block.uuid && (
                            <span className="absolute -top-3 left-0 z-20 rounded-[6px] bg-[var(--accent)] px-1.5 py-0.5 text-[10.5px] font-semibold text-[var(--accent-ink)]">
                                Editando
                            </span>
                        )}
                        {onSelect && !block.is_visible && (
                            <span className="absolute -top-3 right-0 z-20 flex items-center gap-1 rounded-[6px] bg-[var(--text)] px-1.5 py-0.5 text-[10.5px] font-semibold text-[var(--surface)]">
                                <EyeSlashIcon size={11} weight="bold" />
                                Oculto: el cliente no lo ve
                            </span>
                        )}
                        <div className={cn(onSelect && !block.is_visible && 'opacity-50')}>
                            {onSelect && emptyReason(block) ? (
                                <div className="flex items-center gap-3 rounded-[var(--store-radius)] border-2 border-dashed border-[var(--line-2)] bg-[var(--surface)] px-4 py-5 text-[13px] text-[var(--muted)]">
                                    <SquaresFourIcon size={22} className="shrink-0" />
                                    <span>{emptyReason(block)}</span>
                                </div>
                            ) : (
                                <Renderer data={block.data} priority={index === firstBanner && index < 3} draft={draft} />
                            )}
                        </div>
                    </SectionShell>
                );
            })}
        </div>
    );
}
