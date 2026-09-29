export type SupplierReturnStatus = 'draft' | 'dispatched' | 'closed' | 'cancelled';

export interface Option {
    value: string | number;
    label: string;
}

export interface SupplierReturnRow {
    id: number;
    number: string;
    status: SupplierReturnStatus;
    status_label: string;
    supplier_name: string | null;
    order_code: string | null;
    order_id: number | null;
    store: string | null;
    warehouse: string | null;
    units: number;
    net_units: number;
    total: number;
    /** Lo que el proveedor todavía debe: ni respondido ni rechazado. */
    pending_total: number;
    created_at: string | null;
    dispatched_at: string | null;
    closed_at: string | null;
    cancelled_at: string | null;
}

/** Existencia de un artículo en una bodega de la sede, ya con lo apartado descontado. */
export interface WarehouseStock {
    quantity: number;
    reserved: number;
    available: number;
}

export interface ReturnLot {
    id: number;
    code: string | null;
    expires_at: string | null;
    quantity: number;
    warehouse_id: number;
    /** Entró con esta orden (o trae su mismo código): sale primero. */
    from_order: boolean;
}

/** Un artículo como lo entrega el servidor al formulario: nunca se inventan disponibles. */
export interface ReturnProduct {
    product_id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    unit: string | null;
    sold_by_weight: boolean;
    track_lots: boolean;
    tax_rate: number;
    stock: Record<string, WarehouseStock>;
    lots: ReturnLot[];
    /** Solo en la suelta. */
    suggested_cost?: number;
    is_supplier_product?: boolean;
}

/** Un renglón del formulario, con lo que el servidor dio y lo que escribe el usuario. */
export interface ReturnLine extends ReturnProduct {
    purchase_order_item_id?: number;
    received?: number;
    returned?: number;
    in_drafts?: number;
    returnable?: number;
    lot_code?: string | null;
    unit_cost: number;
    quantity: number;
    reason: string | null;
    note: string | null;
    lot_id: number | null;
}

export interface ReturnableOrder {
    id: number;
    code: string;
    supplier_id: number;
    supplier: string | null;
    status_label: string;
    received_at: string | null;
    total: number;
}

export interface SupplierReturnDetail extends SupplierReturnRow {
    supplier: { id: number | null; name: string | null; contact_name: string | null; phone: string | null };
    order: { id: number; code: string } | null;
    note: string | null;
    shipping_reference: string | null;
    subtotal: number;
    tax_total: number;
    rejected_total: number;
    settled_total: number;
    created_by: string | null;
    dispatched_by: string | null;
    cancel_reason: string | null;
}

export interface SupplierReturnItemRow {
    id: number;
    product_id: number;
    name: string | null;
    sku: string | null;
    unit: string | null;
    sold_by_weight: boolean;
    reason: string;
    reason_label: string;
    note: string | null;
    quantity: number;
    rejected_quantity: number;
    net_quantity: number;
    replaceable: number;
    unit_cost: number;
    line_total: number;
    chosen_lot: string | null;
    lots: { code: string | null; expires_at: string | null; quantity: number }[];
}

export interface SupplierReturnSettlementRow {
    id: number;
    type: 'credit_note' | 'refund' | 'replacement';
    type_label: string;
    document_number: string | null;
    amount: number;
    received_at: string | null;
    payment_method: string | null;
    note: string | null;
    user: string | null;
    order: { id: number; code: string; status_label: string } | null;
}

export interface TimelineEvent {
    at: string;
    label: string;
    detail: string | null;
    user: string | null;
    tone: 'neutral' | 'warn' | 'accent' | 'danger';
}
