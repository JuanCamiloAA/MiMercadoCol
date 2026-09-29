export type CustomerReturnStatus = 'requested' | 'approved' | 'received' | 'resolved' | 'rejected' | 'cancelled';

export type Disposition = 'restock' | 'waste' | 'none';

export interface Option {
    value: string | number;
    label: string;
}

export interface CustomerReturnRow {
    id: number;
    number: string;
    created_at: string;
    status: CustomerReturnStatus;
    status_label: string;
    channel: 'pos' | 'online';
    origin_label: string;
    store: string | null;
    sale: { id: number; number: string } | null;
    order: { id: number; number: string } | null;
    customer: string | null;
    total: number;
    resolution_label: string | null;
}

export interface CustomerReturnItemRow {
    id: number;
    parent_id: number | null;
    description: string;
    product_id: number | null;
    is_service: boolean;
    quantity: number;
    unit_price: number;
    line_total: number;
    reason: string;
    reason_label: string;
    disposition: Disposition | null;
    disposition_label: string | null;
    suggested_disposition: Disposition | null;
    warehouse: string | null;
}

export interface CustomerReturnDetail {
    id: number;
    number: string;
    status: CustomerReturnStatus;
    status_label: string;
    resolution: string | null;
    resolution_label: string | null;
    origin_label: string;
    channel: 'pos' | 'online';
    store: string | null;
    total: number;
    refunded_total: number;
    refund_method_label: string | null;
    credited_total: number;
    exchanged_total: number;
    debt_relief_total: number;
    reason: string | null;
    customer_note: string | null;
    reject_reason: string | null;
    out_of_window: boolean;
    window_approved_by: string | null;
    photos: string[];
    sale: { id: number; number: string; sold_at: string | null; total: number } | null;
    order: { id: number; number: string } | null;
    customer: { id: number; name: string; document: string | null; email: string | null; phone: string | null; store_credit: number; generic: boolean } | null;
    refund_sale: { id: number; number: string } | null;
    exchange_sale: { id: number; number: string } | null;
    timeline: { label: string; at: string; by: string | null }[];
    items: CustomerReturnItemRow[];
}

/** Una línea de la venta tal como la ve quien va a devolver (`CustomerReturnService::saleForReturn`). */
export interface ReturnableLine {
    id: number;
    description: string;
    product_id: number | null;
    sold_by_weight: boolean;
    quantity: number;
    refunded: number;
    held: number;
    available: number;
    unit_price: number;
    line_total: number;
    returnable_by_default?: boolean;
    adjusted?: boolean;
}

export interface ReturnableSale {
    id: number;
    number: string;
    total: number;
    due_total: number;
    status: string;
    status_label: string;
    channel: 'pos' | 'online';
    sold_at: string;
    order: { id: number; number: string } | null;
    /** `generic`: la ficha del consumidor final, que no recibe saldo a favor. */
    customer: { id: number; name: string; store_credit: number; generic: boolean } | null;
    window: { days: number; max: number; within: boolean; until: string };
    payments: { method: string; label: string; amount: number }[];
    items: (ReturnableLine & { is_service: boolean; pricing_mode: string | null; components: ReturnableLine[] })[];
}
