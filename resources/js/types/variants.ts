/** Un valor de una característica: «8», «Azul», «900 ml». */
export interface AttributeValue {
    id: number;
    value: string;
    /** Solo en las medidas: cantidad y unidad (ml, l, g, kg, un). */
    amount: number | null;
    unit: string | null;
    color_hex: string | null;
    media_id: number | null;
    media_url: string | null;
    sort_order: number;
    is_active: boolean;
    /** Cuántos subartículos lo usan. */
    usage: number;
}

/** Una característica del catálogo de la empresa. */
export interface AttributeRow {
    id: number;
    name: string;
    display: 'buttons' | 'swatches' | 'select';
    /** Nulo: un texto. `volume`, `weight` o `units`: una medida. */
    measure: string | null;
    units: string[];
    name_in_label: boolean;
    is_active: boolean;
    values: AttributeValue[];
}

export interface SelectOption {
    value: string | number;
    label: string;
}
