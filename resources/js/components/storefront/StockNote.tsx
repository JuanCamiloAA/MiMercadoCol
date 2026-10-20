import { cn } from '@/lib/cn';
import type { StockInfo } from '@/types/storefront';

/**
 * El aviso de existencias según la regla de la sede. La cifra exacta solo llega del servidor
 * si la sede la quiere mostrar; por defecto, «Últimas 3 unidades» bajo el umbral y nada más.
 */
export function StockNote({ stock, className }: { stock: StockInfo; className?: string }) {
    if (!stock.note) {
        return null;
    }

    return (
        <p
            className={cn(
                'text-[11.5px] font-semibold',
                stock.level === 'low' && 'text-[var(--warn)]',
                stock.level === 'ok' && 'font-medium text-[var(--muted)]',
                className,
            )}
        >
            {stock.note}
        </p>
    );
}
