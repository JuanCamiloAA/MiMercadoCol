/** Una presentación que se puede escoger en un artículo completo del servicio. */
export interface ServiceFamilyOption {
    product_id: number;
    name: string;
    label: string;
    unit: string;
    sold_by_weight: boolean;
    unit_price: number;
    tax_rate: number;
    stock: number;
    allow_negative_stock: boolean;
    is_active: boolean;
}

/**
 * El artículo completo («Aceite de girasol», en la presentación que se elija): el cajero la
 * cambia sin pedir firma, porque escoger cuál no es ajustar el servicio.
 */
export interface ServiceFamilyChoice {
    id: number;
    name: string | null;
    options: ServiceFamilyOption[];
}

/** Un artículo del maestro de un servicio, por una unidad de servicio, como lo manda la caja. */
export interface PosServiceComponent {
    product_id: number;
    name: string;
    unit: string;
    sold_by_weight: boolean;
    quantity: number;
    returnable_by_default: boolean;
    /** Precio vigente en caja (promociones de precio). Cero en un servicio cerrado. */
    unit_price: number;
    tax_rate: number;
    /** Disponible en la bodega de caja: existencia menos lo apartado. */
    stock: number;
    allow_negative_stock: boolean;
    is_active: boolean;
    family?: ServiceFamilyChoice | null;
}

export interface PosService {
    id: number;
    code: string;
    barcode: string | null;
    name: string;
    thumb: string | null;
    /** El valor del servicio en sí. En uno cerrado es todo el precio. */
    price: number;
    /** Lo que cobra una unidad tal cual el maestro. */
    total: number;
    tax_rate: number;
    pricing_mode: 'bundled' | 'itemized';
    open_price: boolean;
    category_id: number | null;
    /** Artículos inactivos o eliminados: mientras haya alguno, no se cobra sin quitarlos. */
    blocked: string[];
    components: PosServiceComponent[];
}

export type ServiceAction = 'service_remove' | 'service_reduce' | 'service_increase' | 'service_add';

/** Un artículo dentro de la línea de un servicio en el tiquete. */
export interface ServiceComponentLine {
    product_id: number;
    name: string;
    unit: string;
    sold_by_weight: boolean;
    /** Maestro × cantidad del servicio; null si el cajero lo agregó. */
    master_quantity: number | null;
    /** Lo que de verdad lleva esta venta. */
    quantity: number;
    unit_price: number;
    tax_rate: number;
    discount: number;
    stock: number;
    allow_negative_stock: boolean;
    returnable_by_default: boolean;
    is_active: boolean;
    family?: ServiceFamilyChoice | null;
}

export interface RemovedComponent {
    product_id: number;
    name: string;
    master_quantity: number;
}

export const q3 = (value: number) => Math.round((Number.isFinite(value) ? value : 0) * 1000) / 1000;

/** Los artículos del maestro multiplicados por la cantidad del servicio. */
export function componentsFromMaster(master: PosServiceComponent[], serviceQuantity: number, itemized: boolean): ServiceComponentLine[] {
    return master.map((component) => ({
        product_id: component.product_id,
        name: component.name,
        unit: component.unit,
        sold_by_weight: component.sold_by_weight,
        master_quantity: q3(component.quantity * serviceQuantity),
        quantity: q3(component.quantity * serviceQuantity),
        unit_price: itemized ? component.unit_price : 0,
        tax_rate: component.tax_rate,
        discount: 0,
        stock: component.stock,
        allow_negative_stock: component.allow_negative_stock,
        returnable_by_default: component.returnable_by_default,
        is_active: component.is_active,
        family: component.family ?? null,
    }));
}

/** Otra presentación del artículo completo: la misma cantidad, su precio y su existencia. */
export function swapComponent(component: ServiceComponentLine, option: ServiceFamilyOption, itemized: boolean): ServiceComponentLine {
    return {
        ...component,
        product_id: option.product_id,
        name: option.name,
        unit: option.unit,
        sold_by_weight: option.sold_by_weight,
        unit_price: itemized ? option.unit_price : 0,
        tax_rate: option.tax_rate,
        stock: option.stock,
        allow_negative_stock: option.allow_negative_stock,
        is_active: option.is_active,
    };
}

export function isComponentAdjusted(component: ServiceComponentLine): boolean {
    return component.master_quantity === null || Math.abs(component.quantity - component.master_quantity) > 0.0005;
}

/** Qué permiso pide llevar un artículo a esta cantidad. */
export function actionFor(component: ServiceComponentLine, next: number): ServiceAction | null {
    if (component.master_quantity === null) {
        return null;
    }

    if (next < component.master_quantity - 0.0005) {
        return 'service_reduce';
    }

    if (next > component.master_quantity + 0.0005) {
        return 'service_increase';
    }

    return null;
}

/** Lo que cobra un artículo de servicio: nada en uno cerrado. */
export function componentTotal(component: ServiceComponentLine): number {
    const gross = Math.round(component.quantity * component.unit_price);

    return gross - Math.min(component.discount, gross);
}
