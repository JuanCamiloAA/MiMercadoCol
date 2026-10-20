import { cn } from '@/lib/cn';
import { TONES, text, type BlockProps } from './shared';

/** Cuánto tarda en dar una vuelta completa, según la velocidad elegida. */
const DURATIONS: Record<string, string> = { slow: '48s', normal: '30s', fast: '18s' };

/**
 * Cinta que corre: las frases en bucle de lado a lado. La mueve el CSS (`store-ticker` en
 * `storefront.css`) con el contenido repetido dos veces; se detiene al pasar el mouse y queda
 * quieta para quien pidió menos movimiento. La copia va oculta para los lectores de pantalla.
 */
export function TickerBlock({ data }: BlockProps) {
    const items = ((data.items as { text: string }[] | undefined) ?? []).map((item) => text(item.text)).filter((value) => value !== '');

    if (items.length === 0) {
        return null;
    }

    const separator = text(data.separator) || '·';
    const run = (hidden: boolean) => (
        <span className="flex shrink-0 items-center" aria-hidden={hidden || undefined}>
            {items.map((item, index) => (
                <span key={index} className="flex items-center">
                    <span className="px-5 text-[13px] font-bold uppercase tracking-[0.12em] md:text-[14px]">{item}</span>
                    <span className="opacity-60">{separator}</span>
                </span>
            ))}
        </span>
    );

    return (
        <div className={cn('store-ticker overflow-hidden rounded-[var(--store-radius-sm)] py-3', TONES[text(data.tone)] ?? TONES.accent)} style={{ '--ticker-duration': DURATIONS[text(data.speed)] ?? DURATIONS.normal } as React.CSSProperties}>
            <div className="store-ticker-track flex w-max">
                {run(false)}
                {run(true)}
            </div>
        </div>
    );
}
