import type { SelectOption } from '@/types';

/** De dónde salió un renglón, para saber después qué tanto sirvieron las sugerencias. */
export type ItemOrigin = 'suggestion' | 'search' | 'scan' | 'manual';

export interface CatalogSupplier {
    id: number;
    name: string | null;
    last_cost: number;
    is_primary: boolean;
}

/** Un artículo del catálogo, con todo lo que la tarjeta necesita pintar. */
export interface CatalogProduct {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    unit: string;
    stock: number;
    min_stock: number;
    pack_size: number;
    suppliers: CatalogSupplier[];
    /** El nombre que se muestra cuando no es del proveedor elegido. */
    supplier_label: string | null;
    is_from_supplier: boolean;
    is_low: boolean;
    is_out: boolean;
    suggested: number;
    unit_cost: number;
    /** Si es un subartículo: su artículo principal, que en el catálogo abre la matriz. */
    family_id?: number | null;
    family_name?: string | null;
    variant_label?: string | null;
    value_1_id?: number | null;
    value_2_id?: number | null;
}

/** Un renglón de la orden que se está armando. */
export interface OrderLine {
    product_id: number;
    name: string;
    sku: string;
    unit: string;
    quantity: number;
    unit_cost: number;
    pack_size: number;
    stock: number;
    min_stock: number;
    is_low: boolean;
    /** Null = el artículo no tiene proveedor asignado. */
    supplier_id: number | null;
    supplier_label: string | null;
    origin: ItemOrigin;
    family_id?: number | null;
}

export interface PurchaseCreateProps {
    suppliers: (SelectOption & { payment_term_id: number | null })[];
    supplierId: number | null;
    products: CatalogProduct[];
    counts: { total: number; low_stock: number };
    store: { id: number; name: string } | null;
    paymentTerms: { value: number; label: string; days: number }[];
    defaultPaymentTermId: number | null;
}
