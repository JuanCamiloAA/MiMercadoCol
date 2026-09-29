import type { Paginated, SelectOption } from '@/types';

/**
 * El código de barras es `string` en todo el camino, nunca `number`.
 *
 * Un `parseInt` sobre un EAN se come los ceros de la izquierda —que son significativos— y el
 * artículo queda guardado con otro código del que se escanea en la caja.
 */
export type Ean = string;

export type ImportSource = 'openfoodfacts' | 'csv';

export type ImportStatus = 'pending' | 'processing' | 'completed' | 'failed' | 'cancelled';

export type ItemResult = 'imported' | 'updated' | 'skipped' | 'failed';

/**
 * Si el artículo ya está en el catálogo de la empresa, y si vale la pena traerlo.
 *
 * `up_to_date` es el que no se vuelve a ofrecer: ya entró y la fuente no le aporta nada.
 * `outdated` sí está, pero la fuente tiene algo que le falta.
 */
export type PreviewStatus = 'new' | 'outdated' | 'up_to_date' | 'deleted';

export interface PreviewRow {
    ean: Ean | null;
    name: string | null;
    brand: string | null;
    packaging: string | null;
    category: string | null;
    image: string | null;
    usable: boolean;
    reason: string | null;
    status: PreviewStatus;
    current_name: string | null;
    /** Qué le aportaría la fuente a un artículo que ya existe. Vacío = nada. */
    adds: string[];
}

export interface PreviewResponse {
    count: number;
    page: number;
    page_size: number;
    products: PreviewRow[];
    /** Cuántos de esta página valen la pena: nuevos más los que la fuente completa. */
    worth: number;
}

export interface ImportTotals {
    detected: number;
    imported: number;
    updated: number;
    skipped: number;
    failed: number;
}

export interface ImportRow {
    id: number;
    source: ImportSource;
    source_label: string;
    status: ImportStatus;
    status_label: string;
    is_final: boolean;
    progress: number;
    criteria_summary: string;
    totals: ImportTotals;
    error_message: string | null;
    user: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string;
}

export interface ImportItemRow {
    id: number;
    ean: Ean | null;
    name: string | null;
    result: ItemResult;
    result_label: string;
    reason: string | null;
    product: { id: number; name: string; sku: string } | null;
}

export interface ItemsResponse {
    items: Paginated<ImportItemRow>;
    counts: Record<ItemResult, number>;
}

export interface CatalogImportPageProps {
    categories: SelectOption[];
    /** Los segmentos de la fuente, en castellano. */
    segments: SelectOption[];
    running: ImportRow | null;
    history: ImportRow[];
    limits: { default: number; max: number; page_size: number; csv_max_kb: number };
    attribution: { label: string; url: string };
    can: { run: boolean; cancel: boolean };
}
