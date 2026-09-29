/** Una bodega de la sede activa, como la pinta un selector. */
export interface WarehouseOption {
    value: number;
    label: string;
    is_pos_source?: boolean;
    is_online_source?: boolean;
    is_receiving_default?: boolean;
}

/** Una entrada suma existencias; una salida las resta. */
export type StockDirection = 'in' | 'out';

/** Un artículo del catálogo, con la existencia de la sede activa. */
export interface StockProduct {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    unit: string;
    stock: number;
    /** Apartado por pedidos que todavía no salen. */
    reserved: number;
    available: number;
    min_stock: number;
    unit_cost: number;
    is_low: boolean;
    is_out: boolean;
    allow_negative_stock: boolean;
}

/** Un renglón del movimiento que se está armando. */
export interface StockLine {
    product_id: number;
    name: string;
    sku: string;
    unit: string;
    quantity: number;
    unit_cost: number;
    stock: number;
    reserved: number;
    available: number;
    min_stock: number;
    allow_negative_stock: boolean;
}

export interface StockMovementFormProps {
    direction: StockDirection;
    reasons: { value: string; label: string }[];
    warehouses: WarehouseOption[];
    default_warehouse: number | null;
    products: StockProduct[];
    counts: { total: number; low_stock: number };
    store: { id: number; name: string } | null;
}
