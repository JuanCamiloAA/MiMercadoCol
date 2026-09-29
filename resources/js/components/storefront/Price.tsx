import { cn } from '@/lib/cn';
import { money } from '@/lib/format';

/**
 * El precio: protagonista, en la letra de la tienda y en negrita. Con promoción va en el rojo
 * de oferta y el de antes tachado al lado. El precio por unidad de medida lo calcula el
 * servidor; aquí solo se pinta.
 */
export function Price({
    price,
    compareAt,
    unitPrice,
    size = 'lg',
    from = false,
    className,
}: {
    price: number;
    compareAt?: number | null;
    unitPrice?: string | null;
    size?: 'sm' | 'md' | 'lg' | 'xl';
    /** «Desde»: un artículo con presentaciones de distinto precio. */
    from?: boolean;
    className?: string;
}) {
    const deal = compareAt !== null && compareAt !== undefined && compareAt > price;

    return (
        <div className={cn('min-w-0', className)}>
            <p className="flex flex-wrap items-baseline gap-x-1.5 leading-none">
                {from && <span className="text-[12.5px] font-semibold text-[var(--muted)]">Desde</span>}
                <span
                    className={cn(
                        'font-extrabold tracking-tight tabular-nums',
                        size === 'sm' && 'text-[15px]',
                        size === 'md' && 'text-[17px] md:text-[18px]',
                        size === 'lg' && 'text-[19px] md:text-[21px]',
                        size === 'xl' && 'text-[28px] md:text-[34px]',
                        deal ? 'text-[var(--deal)]' : 'text-[var(--text)]',
                    )}
                >
                    {money(price)}
                </span>
                {deal && (
                    <span className={cn('text-[var(--muted)] line-through tabular-nums', size === 'xl' ? 'text-[16px]' : 'text-[12.5px]')}>
                        <span className="sr-only">Antes </span>
                        {money(compareAt)}
                    </span>
                )}
            </p>
            {unitPrice && <p className={cn('mt-1 text-[var(--muted)] tabular-nums', size === 'xl' ? 'text-[13px]' : 'text-[11.5px]')}>{unitPrice}</p>}
        </div>
    );
}
