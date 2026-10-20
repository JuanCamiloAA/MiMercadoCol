import type { Paginated } from '@/types';

/** El estado de un artículo frente a la tienda, en el orden en que lo evalúa el servidor. */
export type CatalogStatus = 'not_published' | 'family_hidden' | 'no_price' | 'out_of_stock' | 'visible';

export type ManageTab = 'products' | 'families' | 'services';

export interface StoreOption {
    value: number;
    label: string;
    /** La bodega que respalda la tienda de esa sede. */
    warehouse: string;
}

export interface RowFlag {
    key: string;
    label: string;
    href?: string;
}

export interface OtherWarehouse {
    id: number;
    name: string;
    available: number;
}

export interface Replenishment {
    kind: 'purchase' | 'transfer';
    id: number;
    code: string;
    expected_at: string | null;
    quantity: number;
    href: string | null;
}

export interface CatalogRow {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    brand: string | null;
    category: string | null;
    family: string | null;
    family_id?: number | null;
    variant_label: string | null;
    thumb_url: string | null;
    /** null en un servicio que ningún artículo limita. */
    available: number | null;
    stock_quantity?: number;
    reserved_quantity?: number;
    min_stock: number | null;
    stock_unit: string;
    allow_negative_stock?: boolean;
    sold_pos_30d: number;
    sold_online_30d: number;
    price: number;
    online_price: number | null;
    current_price: number;
    is_online_visible: boolean;
    is_featured: boolean;
    status: CatalogStatus;
    status_label: string;
    status_note: string;
    out_of_stock_since?: string | null;
    other_available?: number;
    top_other_warehouse?: OtherWarehouse | null;
    expiring_lot_days?: number | null;
    flags: RowFlag[];
    /** Servicio de precio variable: no se publica. */
    open_price?: boolean;
    /** Servicio: los artículos que lo limitan. */
    components?: string[];
    /** Artículo con subartículos. */
    variants_total?: number;
    variants_published?: number;
    variants_available?: number;
}

export interface OutOfStockRow extends CatalogRow {
    replenishment?: Replenishment | null;
    replenishment_kind: 'purchase' | 'transfer' | 'transferable' | 'none';
    transfer_href?: string | null;
    purchase_href?: string | null;
}

export interface QuickView {
    value: string;
    label: string;
    count: number;
    tone: 'neutral' | 'warn' | 'accent';
}

export interface CategoryOption {
    value: number;
    label: string;
    depth: number;
}

export interface Option {
    value: string | number;
    label: string;
}

export interface CatalogFilters extends Record<string, string> {
    store: string;
    tab: string;
    view: string;
    search: string;
    category: string;
    brand: string;
    supplier: string;
    stock: string;
    status: string;
    sheet: string;
    price_min: string;
    price_max: string;
    promo: string;
    expiring_days: string;
    no_online_sales: string;
    price_differs: string;
    margin_below: string;
    other_warehouse: string;
    sort: string;
    family: string;
}

export interface ManageMetrics {
    visible: number;
    out_of_stock: number;
    stock_unpublished: number;
    published: number;
    active: number;
    featured: number;
    featured_out_of_stock: number;
    incomplete: number;
    out_of_stock_transferable: number;
}

export type SuggestionGroupKey = 'pos_sellers' | 'expiring' | 'overstock' | 'incomplete';

export interface SuggestionItem {
    id: number;
    name: string;
    sku: string;
    thumb_url: string | null;
    group: SuggestionGroupKey;
    reason: string;
    available: number;
    stock_unit: string;
    sold_pos_30d: number;
    coverage_days: number | null;
    expiring_lot_days: number | null;
    min_stock: number | null;
    price: number;
    missing: ('image' | 'description' | 'price')[];
}

export interface SuggestionGroup {
    key: SuggestionGroupKey;
    title: string;
    criteria: string;
    count: number;
    items: SuggestionItem[];
    /** La vista del catálogo que muestra el grupo completo. */
    view: Record<string, string>;
}

export interface CategoryCoverage {
    id: number;
    name: string;
    active: number;
    published: number;
    visible: number;
    out_of_stock: number;
    stock_unpublished: number;
    rest: number;
    suggestions: number;
}

export interface ManageCan {
    transfer_requests_create: boolean;
    purchases_create: boolean;
    purchases_view: boolean;
    transfers_view: boolean;
    promotions_create: boolean;
    products_edit: boolean;
}

export interface ManageProps {
    tab: ManageTab;
    stores: StoreOption[];
    store_id: number;
    warehouse: { id: number; name: string };
    storefront_url: string | null;
    rows: Paginated<CatalogRow>;
    filters: CatalogFilters;
    options: {
        categories: CategoryOption[];
        brands: Option[];
        suppliers: Option[];
        statuses: Option[];
        sheet: Option[];
        sorts: Option[];
        stock: Option[];
        quick_views: QuickView[];
    };
    thresholds: { expiring_days: number; low_margin: number };
    metrics: ManageMetrics;
    suggestions_preview: SuggestionItem[];
    suggestions_total: number;
    coverage: CategoryCoverage[];
    can: ManageCan;
}

export interface OutOfStockMetrics {
    hidden: number;
    featured: number;
    replenishing: number;
    replenishing_purchases: number;
    replenishing_transfers: number;
    transferable: number;
    no_replenishment: number;
}

export interface OutOfStockProps {
    tab: ManageTab;
    stores: StoreOption[];
    store_id: number;
    warehouse: { id: number; name: string };
    rows: Paginated<OutOfStockRow>;
    filters: { store: string; tab: string; search: string; category: string; replenishment: string };
    options: { categories: CategoryOption[]; replenishment: Option[] };
    metrics: OutOfStockMetrics;
    tab_counts: Record<ManageTab, number>;
    can: ManageCan;
}

export interface Dismissal {
    id: number;
    product_id: number;
    name: string;
    sku: string;
    group: SuggestionGroupKey;
    group_label: string;
    dismissed_at: string | null;
    dismissed_by: string | null;
}

export interface SuggestionsProps {
    stores: StoreOption[];
    store_id: number;
    warehouse: { id: number; name: string };
    groups: SuggestionGroup[];
    total: number;
    top_seller_ids: number[];
    coverage: CategoryCoverage[];
    dismissed: Dismissal[];
    can: ManageCan;
}
