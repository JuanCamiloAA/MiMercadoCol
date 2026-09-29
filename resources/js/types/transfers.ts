/** Una bodega como la ve el selector de traslados: siempre con su sede al lado. */
export interface TransferWarehouse {
    value: number;
    label: string;
    store_id: number;
    store_name: string | null;
}

export type TransferStatus = 'draft' | 'in_transit' | 'received' | 'cancelled';

export interface TransferRow {
    id: number;
    number: string;
    /** La solicitud de la que salió, si salió de una. */
    request_number: string | null;
    status: TransferStatus;
    status_label: string;
    origin: string | null;
    destination: string | null;
    /** Entre bodegas de la misma sede: la única ruta que admite traslado directo. */
    is_internal: boolean;
    items: number;
    units: number;
    value: number;
    created_at: string | null;
}

export interface TransferSide {
    id: number;
    name: string;
    store_id: number;
    store_name: string | null;
}

export interface TransferDetail {
    id: number;
    number: string;
    status: TransferStatus;
    status_label: string;
    note: string | null;
    is_internal: boolean;
    request: { id: number; number: string } | null;
    origin: TransferSide | null;
    destination: TransferSide | null;
    created_by: string | null;
    created_at: string | null;
    dispatched_by: string | null;
    dispatched_at: string | null;
    received_by: string | null;
    received_at: string | null;
    value: number;
}

export interface TransferItem {
    id: number;
    product_id: number;
    name: string | null;
    sku: string | null;
    unit: string | null;
    quantity: number;
    received_quantity: number;
    missing: number;
    unit_cost: number;
}

/** Un renglón del traslado que se está armando. */
export interface TransferLine {
    product_id: number;
    name: string;
    sku: string;
    unit: string;
    quantity: number;
    unit_cost: number;
    /** Lo disponible en la bodega de origen cuando se agregó. */
    available: number;
}

export type TransferRequestStatus = 'pending' | 'partial' | 'fulfilled' | 'rejected' | 'closed' | 'cancelled';

export interface TransferRequestRow {
    id: number;
    number: string;
    status: TransferRequestStatus;
    status_label: string;
    origin: string | null;
    destination: string | null;
    is_internal: boolean;
    items: number;
    units: number;
    /** Lo que ya va en traslados vigentes. */
    sent: number;
    created_by: string | null;
    created_at: string | null;
}

export interface TransferRequestDetail {
    id: number;
    number: string;
    status: TransferRequestStatus;
    status_label: string;
    note: string | null;
    /** Por qué se rechazó o se cerró sin completar. */
    response_note: string | null;
    is_internal: boolean;
    origin: TransferSide | null;
    destination: TransferSide | null;
    created_by: string | null;
    created_at: string | null;
    responded_by: string | null;
    responded_at: string | null;
    cancelled_at: string | null;
}

export interface TransferRequestItem {
    id: number;
    product_id: number;
    name: string | null;
    sku: string | null;
    unit: string | null;
    quantity: number;
    sent_quantity: number;
    pending: number;
    /** Lo disponible hoy en la bodega que surte. */
    origin_available: number;
    /** Lo que tiene hoy la bodega que pide. */
    destination_stock: number;
}

export interface TransferRequestTransfer {
    id: number;
    number: string;
    status: TransferStatus;
    status_label: string;
    units: number;
    value: number;
    created_at: string | null;
}
