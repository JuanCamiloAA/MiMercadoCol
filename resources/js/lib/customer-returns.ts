import type { CustomerReturnStatus, Disposition } from '@/types/customer-returns';

/** El color del estado de una devolución de venta: lo que espera a alguien resalta. */
export const CUSTOMER_RETURN_TONES: Record<CustomerReturnStatus, 'neutral' | 'warn' | 'accent' | 'danger' | 'info'> = {
    requested: 'warn',
    approved: 'info',
    received: 'warn',
    resolved: 'accent',
    rejected: 'danger',
    cancelled: 'neutral',
};

/** A dónde va un artículo que vuelve. El servidor manda las etiquetas; esto es solo el color. */
export const DISPOSITION_TONES: Record<Disposition, 'accent' | 'danger' | 'neutral'> = {
    restock: 'accent',
    waste: 'danger',
    none: 'neutral',
};

/** Lo que se propone al recibir según el motivo, igual que `CustomerReturnItem::defaultDisposition`. */
export function suggestedDisposition(reason: string, wasteReasons: string[]): Disposition {
    return wasteReasons.includes(reason) ? 'waste' : 'restock';
}
