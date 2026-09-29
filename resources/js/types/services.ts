export type PricingMode = 'bundled' | 'itemized';

export interface ServiceOption {
    value: string | number;
    label: string;
}

export interface ServiceRow {
    id: number;
    code: string;
    barcode: string | null;
    name: string;
    thumb: string | null;
    category: { id: number; name: string } | null;
    price: number;
    /** Lo que cobra la caja por una unidad tal cual el maestro (en detallado, con los artículos). */
    price_total: number;
    pricing_mode: PricingMode;
    pricing_label: string;
    open_price: boolean;
    components_count: number;
    is_online_visible: boolean;
    is_active: boolean;
    /** Componentes inactivos o eliminados: mientras haya alguno, el servicio no se cobra. */
    blocked: string[];
    cost?: number;
    cost_total?: number;
    margin?: number | null;
}

/** Un artículo como lo devuelve el buscador de componentes. */
export interface ComponentProduct {
    product_id: number;
    name: string;
    sku?: string | null;
    unit?: string | null;
    sold_by_weight?: boolean;
    is_active: boolean;
    unit_price?: number;
    unit_cost?: number | null;
    /** Si es un subartículo: su artículo principal. */
    family_id?: number | null;
    family_name?: string | null;
    variant_label?: string | null;
}

export interface ComponentLine extends ComponentProduct {
    quantity: number;
    returnable_by_default: boolean;
    /** El artículo completo, en la presentación que se elija al vender; este es el de por defecto. */
    family?: boolean;
    /** Precio del artículo dentro del servicio detallado. Nulo: su precio vigente (`unit_price`). */
    custom_price?: number | null;
}

export interface ServiceFormData {
    id: number;
    code: string;
    barcode: string | null;
    name: string;
    category_id: number | null;
    description: string | null;
    image_path: string | null;
    price: number;
    online_price: number | null;
    cost: number | null;
    tax_rate: number;
    pricing_mode: PricingMode;
    open_price: boolean;
    is_active: boolean;
    is_online_visible: boolean;
    is_featured: boolean;
    sort_order: number;
    components: ComponentLine[];
}
