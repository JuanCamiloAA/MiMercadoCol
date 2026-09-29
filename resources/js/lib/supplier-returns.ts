import type { SupplierReturnStatus } from '@/types/supplier-returns';

/** El color del estado de una devolución a proveedor: lo pendiente resalta. */
export const RETURN_TONES: Record<SupplierReturnStatus, 'neutral' | 'warn' | 'accent' | 'danger'> = {
    draft: 'neutral',
    dispatched: 'warn',
    closed: 'accent',
    cancelled: 'danger',
};
