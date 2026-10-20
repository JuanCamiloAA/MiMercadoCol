/** Tipos que comparten las tres pestañas del editor. */

import type { FontPair } from '@/types/storefront';

export type Dict = Record<string, unknown>;

/** El editor de una sede o el de la plantilla de la plataforma: es el mismo editor. */
export type EditorContext = 'store' | 'template';

export type TargetKind = 'category' | 'page' | 'search' | 'url' | 'whatsapp';

export interface TargetValue {
    kind?: TargetKind | '';
    value?: string;
}

export interface FieldSchema {
    type: string;
    label: string;
    help?: string;
    max?: number;
    min?: number;
    step?: number;
    rows?: number;
    required?: boolean;
    singular?: string;
    max_items?: number;
    options?: { value: string | number; label: string }[];
    options_from?: string;
    show_if?: { field: string; value: string | number | (string | number)[] };
    item?: Record<string, FieldSchema>;
    item_defaults?: Dict;
    kinds?: TargetKind[];
    /** `design`: dentro de un repetidor, va en el grupo plegable «Diseño de esta lámina». */
    group?: string;
    /** Los campos de un `style` (`BannerStyle`): se guardan juntos en un objeto. */
    fields?: Record<string, FieldSchema>;
    /** Las versiones de una imagen o un video (`MediaVariants`): versión → clave hermana. */
    variants?: Partial<Record<'mobile' | 'dark' | 'mobile_dark', string>>;
    /** En una hermana: la clave del campo principal, que la edita junto con las demás. */
    variant_of?: string;
}

export interface CatalogueEntry {
    type: string;
    label: string;
    icon: string;
    help: string;
    pages: string[];
    fields: Record<string, FieldSchema>;
    defaults: Dict;
    /** El diseño de la sección de fábrica para este tipo (pestaña «Diseño»). */
    appearance_defaults?: Dict;
}

export interface BlockRow {
    /** En una sede es el id de la fila; en una plantilla, el uuid del bloque. */
    id: number | string;
    uuid: string;
    type: string;
    page: string;
    sort_order: number;
    is_visible: boolean;
    visible_from: string | null;
    visible_to: string | null;
    devices: 'all' | 'mobile' | 'desktop';
    payload: Dict;
    /** El nombre con su regla: «Estante · Ofertas — todo lo que esté en promoción · 10 artículos». */
    summary: string;
    is_empty: boolean;
    is_scheduled: boolean;
    is_live: boolean;
}

export interface MediaRow {
    id: number;
    url: string;
    srcset?: string | null;
    alt: string | null;
    width: number | null;
    height: number | null;
    focal_x: number;
    focal_y: number;
    variants: Record<string, string>;
}

export interface ThemeRow {
    display_name: string | null;
    tagline: string | null;
    accent: string;
    accent_ink?: string;
    deal_color: string;
    deal_ink?: string;
    scheme: 'light' | 'dark' | 'auto';
    allow_scheme_toggle: boolean;
    font_pair: FontPair;
    radius: 'sharp' | 'soft' | 'round';
    density: 'compact' | 'cozy';
    button_style: 'solid' | 'outline';
    logo_font: 'none' | 'italiana' | 'cormorant' | 'playfair' | 'anton';
    surface: 'white' | 'warm' | 'cool' | 'sage' | 'sand';
    heading_case: 'normal' | 'upper';
    container: 'normal' | 'wide' | 'full';
    button_shape: 'default' | 'pill' | 'square';
    logo: MediaRow | null;
    favicon: MediaRow | null;
    mark: MediaRow | null;
}

export interface ZoneRow {
    id: number;
    name: string;
    fee: number;
    eta_minutes: number;
    is_active: boolean;
}

export interface SlotRow {
    id: number;
    weekday: number;
    weekday_label: string;
    starts_at: string;
    ends_at: string;
    capacity: number;
    is_active: boolean;
}

export interface NavItemRow {
    label: string;
    icon: string | null;
    target: TargetValue | null;
    highlight: boolean;
    /** «Categorías ⌄»: abre el menú grande. */
    opens_menu?: boolean;
}

export interface FooterLinkRow {
    label: string;
    target: TargetValue | null;
}

export interface SettingsRow {
    delivery: {
        enabled: boolean;
        fee: number;
        min_order_total: number;
        free_from: number;
        eta_minutes: number;
        pickup: boolean;
        tip: boolean;
    };
    coverage: { mode: 'zones' | 'radius'; radius_km: number };
    slots: { enabled: boolean };
    payments: { cash_on_delivery: boolean; card_on_delivery: boolean; transfer: boolean; customer_credit: boolean };
    // `show_out_of_stock` quedó sin efecto: la tienda nunca muestra lo que no tiene disponible.
    catalog: { show_out_of_stock?: boolean; price_per_unit: boolean; hide_zero_price?: boolean };
    texts: { delivery_note: string | null };
    seo: { title: string | null; description: string | null };
    topbar: {
        enabled: boolean;
        tone: string;
        rotate_seconds: number;
        messages: { icon: string | null; text: string; target: TargetValue | null }[];
    };
    header: {
        style: string;
        sticky: boolean;
        show_tagline: boolean;
        show_location: boolean;
        show_account: boolean;
        cart_shows_total: boolean;
        search_placeholder: string;
        logo_mode: string;
        layout: string;
        overlay: boolean;
        sticky_style: string;
        search_style: string;
    };
    nav: {
        enabled: boolean;
        show_deals_chip: boolean;
        deals_label: string;
        items: NavItemRow[];
        show_all_categories: boolean;
        mega_menu: boolean;
        style: string;
        active_style: string;
    };
    mobile_nav: { enabled: boolean; items: string[] };
    card: {
        image_fit: string;
        image_bg: string;
        show_brand: boolean;
        show_content: boolean;
        show_unit_price: boolean;
        deal_badge: string;
        add_button: string;
        stock_display: string;
        low_stock_threshold: number;
        price_size: string;
        style: string;
        image_ratio: string;
        text_align: string;
        show_swatches: boolean;
        show_description: boolean;
        image_hover: string;
    };
    floating: { whatsapp: boolean; position: string };
    cart_bar: {
        enabled: boolean;
        show_free_delivery_progress: boolean;
        texts: { missing: string; reached: string };
        desktop_behavior: string;
    };
    category_page: {
        layout: string;
        filters: string[];
        sorts: string[];
        default_sort: string;
        page_size: number;
        pagination: string;
        show_subcategory_chips: boolean;
    };
    product_page: { show_unit_price: boolean; show_description: boolean; related: string; related_limit: number };
    search: { suggestions: boolean; popular_terms: string[]; empty_title: string; empty_text: string | null };
    footer: {
        columns: { title: string; links: FooterLinkRow[] }[];
        contact: { phone: string | null; whatsapp: string | null; email: string | null; address: string | null };
        social: { network: string; url: string }[];
        payment_logos: string[];
        legal_text: string | null;
        terms_target: TargetValue | null;
        privacy_target: TargetValue | null;
        pqrs_target: TargetValue | null;
        style: string;
        tone: string;
        newsletter: boolean;
    };
}

export type SettingsKey = keyof SettingsRow;

/** Las piezas de la estructura global que se editan en Contenido. */
export type StructureKey = 'topbar' | 'header' | 'nav' | 'cart_bar' | 'mobile_nav' | 'footer' | 'floating';

export type Option = { value: string; label: string };

export interface StructureOptions {
    topbar_tones: Option[];
    topbar_variables: string[];
    header_styles: Option[];
    logo_modes: Option[];
    mobile_nav_items: Option[];
    sorts: Option[];
    filters: Option[];
    social: Option[];
    payment_logos: Option[];
    pages: Option[];
    target_kinds: TargetKind[];
    block_pages: Option[];
    choices: Record<string, Option[]>;
}

export interface Endpoints {
    theme: string;
    settings: string;
    structure: string;
    look: string;
    blocks_store: string;
    block_update: string;
    block_duplicate: string;
    block_destroy: string;
    blocks_reorder: string;
    publish: string;
    history: string;
    restore: string;
    products: string;
    media_index: string;
    media_store: string;
    media_video_store: string;
    detach: string;
    reattach: string;
    preview_base?: string;
}

/** Los colores que sugiere una plantilla; los aplica la sede desde Aspecto, si quiere. */
export interface PaletteSuggestion {
    accent?: string;
    deal_color?: string;
}

export interface TemplateOption {
    id: number;
    name: string;
    description: string | null;
    is_default: boolean;
    audience?: string | null;
    audience_group: string | null;
    preview?: string | null;
    palette?: PaletteSuggestion | null;
}

export interface TemplateInfo {
    follows: boolean;
    id: number | null;
    name: string | null;
    is_default?: boolean;
    followers?: number;
    /** Los colores sugeridos por la plantilla de la sede. */
    palette?: PaletteSuggestion | null;
    available: TemplateOption[];
}

export interface Issue {
    message: string;
    hint: string | null;
}

export interface StorefrontRow {
    id: number;
    slug: string;
    is_online: boolean;
    store: { id: number; name: string };
    has_unpublished_changes: boolean;
    last_saved_at: string | null;
    published_at: string | null;
    published_by: string | null;
    published_version: number | null;
    preview_url: string;
}

export interface EditorPermissions {
    content: boolean;
    appearance: boolean;
    settings: boolean;
    publish: boolean;
    history: boolean;
    copy_store: boolean;
    coupons: boolean;
}

export interface VersionRow {
    id: number;
    number: number;
    label: string | null;
    author: string | null;
    published_at: string | null;
    is_current: boolean;
    changes: string[];
}

export type EditorTab = 'appearance' | 'content' | 'settings';

export interface MediaConfig {
    max_kb: number;
    accept: string;
    index: string;
    store: string;
    /** Los videos (MP4 o WebM) de los bloques de video: otro tope y otra dirección de subida. */
    video_max_kb: number;
    video_accept: string;
    video_store: string;
}
