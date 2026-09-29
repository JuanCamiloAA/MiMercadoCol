import { TruckIcon } from '@phosphor-icons/react';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import type { CartBrief } from '@/types/storefront';

/**
 * El avance hacia el domicilio gratis. Con `free_from = 0` no llega nada del servidor y aquí
 * no se pinta nada: «gratis desde $ 0» no existe.
 */
export function FreeDeliveryProgress({
    freeDelivery,
    texts,
    tone = 'default',
    className,
}: {
    freeDelivery: CartBrief['free_delivery'];
    texts: { missing: string; reached: string };
    tone?: 'default' | 'onColor';
    className?: string;
}) {
    if (!freeDelivery) {
        return null;
    }

    const progress = Math.min(100, Math.round(((freeDelivery.from - freeDelivery.missing) / freeDelivery.from) * 100));
    const text = freeDelivery.reached ? texts.reached : texts.missing.replace('{faltan}', money(freeDelivery.missing));

    return (
        <div className={cn('min-w-0', className)}>
            <p className={cn('flex items-center gap-1.5 text-[12.5px] font-semibold', freeDelivery.reached && tone === 'default' && 'text-[var(--accent)]')}>
                <TruckIcon size={16} weight="fill" className="shrink-0" />
                <span className="truncate">{text}</span>
            </p>
            <div
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={progress}
                aria-label="Avance hacia el domicilio gratis"
                className={cn('mt-1.5 h-1.5 w-full overflow-hidden rounded-full', tone === 'onColor' ? 'bg-[color-mix(in_srgb,var(--accent-ink)_25%,transparent)]' : 'bg-[var(--surface-3)]')}
            >
                <div
                    className={cn('h-full rounded-full transition-[width] duration-500', tone === 'onColor' ? 'bg-[var(--accent-ink)]' : 'bg-[var(--accent)]')}
                    style={{ width: `${progress}%` }}
                />
            </div>
        </div>
    );
}
