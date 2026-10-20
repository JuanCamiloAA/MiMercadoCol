/**
 * Lo que llega del servidor para pintar la tienda en línea.
 *
 * Todo sale resuelto: los destinos con su `href`, la imagen de cada artículo, el precio por
 * unidad de medida, el aviso de existencias y los textos con sus variables. El navegador pinta;
 * no decide precios ni direcciones.
 */

export interface MediaPayload {
    id?: number;
    /** Un video de la biblioteca no tiene recortes ni medidas: solo se pinta en un campo de video. */
    kind?: 'image' | 'video';
    mime?: string | null;
    bytes?: number | null;
    url: string;
    srcset?: string | null;
    alt: string | null;
    width?: number | null;
    height?: number | null;
    focal_x: number;
    focal_y: number;
    variants: Record<string, string>;
}

export interface Target {
    kind?: 'category' | 'page' | 'search' | 'url' | 'whatsapp';
    value?: string | number;
    /** La dirección ya armada por el servidor. */
    href?: string | null;
}

/** El aviso de existencias. «Agotado» no existe: lo que no tiene disponible no llega a la tienda. */
export interface StockInfo {
    level: 'low' | 'ok';
    note: string | null;
}

/** La ficha de la tarjeta de producto: la misma en toda la tienda. */
/** Lo que trae un servicio. Un artículo completo deja escoger la presentación. */
export interface ServiceInclude {
    name: string;
    /** Con la presentación que lleva: «Aceite de girasol · 900 ml». */
    label?: string;
    quantity: number;
    unit: string;
    family?: {
        id: number;
        chosen: number | null;
        /** `delta`: cuánto cambia el precio del servicio con esa presentación (lo calcula el servidor). */
        options: { product_id: number; label: string; delta: number; available: boolean }[];
    } | null;
}

export interface CardProduct {
    /** `family`: un artículo con subartículos; se escoge la presentación antes de agregar. */
    kind: 'product' | 'service' | 'family';
    id: number;
    /** `p:12`, `s:3` o `f:5`: la llave de su línea en el carrito (una familia no tiene: se agrega por subartículo). */
    key: string;
    name: string;
    brand: string | null;
    content: string | null;
    unit: string;
    sold_by_weight: boolean;
    step: number;
    price: number;
    compare_at: number | null;
    deal: { percent: number; savings: number; upto?: boolean } | null;
    unit_price: string | null;
    image: string | null;
    image_external: boolean;
    category_icon: string;
    category_id: number | null;
    href: string;
    stock: StockInfo;
    includes?: ServiceInclude[];
    /** Familia: «Desde» porque sus presentaciones no cuestan lo mismo. */
    price_from?: boolean;
    /** Familia: «3 presentaciones», «Tallas S–XL · 2 colores», y las muestras de color. */
    options?: { summary: string; swatches: { value: string; color_hex: string | null; image: string | null }[] };
    /** Familia: «Elegir talla», «Elegir presentación». */
    choose_label?: string;
    /** Familia con una sola presentación a la venta: se agrega directo. */
    single?: CardProduct | null;
    slug?: string;
    /** Solo si la tarjeta lo pide: una línea de la descripción y la foto al pasar el mouse. */
    description?: string | null;
    image_hover?: string;
}

/** Una característica de un artículo con subartículos, con sus valores en orden. */
export interface FamilyAttribute {
    id: number;
    position: 1 | 2;
    name: string;
    display: 'buttons' | 'swatches' | 'select';
    values: { id: number; value: string; color_hex: string | null; image: string | null }[];
}

/** Una presentación, con su precio vigente, existencia y fotos ya resueltos por el servidor. */
export interface FamilyVariant extends CardProduct {
    label: string | null;
    value_1_id: number | null;
    value_2_id: number | null;
    images: string[];
    description: string | null;
}

/** Todo lo que se necesita para escoger una presentación. */
export interface FamilyDetail {
    family: { id: number; name: string; slug: string; brand: string | null; description: string | null; category_id: number | null; tax_rate: number; href: string };
    attributes: FamilyAttribute[];
    variants: FamilyVariant[];
    images: { url: string; value_id: number | null }[];
    default_id: number | null;
}

export interface CartLine {
    key: string;
    quantity: number;
}

/** El resumen del carrito que viaja con cada página de la tienda. */
export interface CartBrief {
    count: number;
    lines_count: number;
    total: number;
    lines: CartLine[];
    free_delivery: { from: number; missing: number; reached: boolean } | null;
    min_order_total: number;
    min_order_missing: number;
}

export interface CategoryNode {
    id: number;
    name: string;
    slug: string;
    parent_id: number | null;
    href: string;
    icon: string;
    image: string | null;
    children?: CategoryNode[];
}

export interface NavItem {
    label: string;
    icon: string | null;
    image: string | null;
    href: string;
    highlight: boolean;
    /** «Categorías ⌄»: abre el menú grande en vez de ir a la página. */
    opens_menu?: boolean;
}

/**
 * La tarjeta de producto. Las claves opcionales son nuevas: un cascarón publicado antes no las
 * trae y la tarjeta se pinta como siempre.
 */
export interface CardSettings {
    image_fit: 'contain' | 'cover';
    image_bg: 'light' | 'white' | 'tint';
    show_brand: boolean;
    show_content: boolean;
    show_unit_price: boolean;
    deal_badge: 'percent' | 'savings' | 'none';
    add_button: 'outline' | 'solid' | 'icon' | 'none';
    stock_display: 'hidden' | 'low_only' | 'exact';
    low_stock_threshold: number;
    price_size: 'sm' | 'md' | 'lg';
    style?: 'boxed' | 'flat' | 'minimal' | 'photo';
    image_ratio?: '1:1' | '3:4' | '4:5';
    text_align?: 'left' | 'center';
    show_swatches?: boolean;
    show_description?: boolean;
    image_hover?: 'none' | 'second';
}

export interface StoreStructure {
    topbar: {
        enabled: boolean;
        tone: 'brand_dark' | 'accent' | 'neutral' | 'deal' | 'dark';
        rotate_seconds: number;
        messages: { icon: string | null; text: string; href: string | null }[];
    };
    header: {
        style: 'light' | 'brand' | 'dark' | 'minimal' | 'overlay';
        sticky: boolean;
        show_tagline: boolean;
        show_location: boolean;
        show_account: boolean;
        cart_shows_total: boolean;
        search_placeholder: string;
        logo_mode: 'logo' | 'mark_name' | 'name';
        location: { store: string; mode: string | null };
        layout?: 'search' | 'center' | 'inline';
        overlay?: boolean;
        sticky_style?: 'solid' | 'blur_dark' | 'blur_light';
        search_style?: 'pill' | 'underline' | 'icon';
    };
    nav: {
        enabled: boolean;
        style?: 'bar' | 'text' | 'none';
        active_style?: 'none' | 'underline';
        show_deals_chip: boolean;
        deals_label: string;
        show_all_categories: boolean;
        mega_menu: boolean;
        items: NavItem[];
    };
    categories: CategoryNode[];
    mobile_nav: { enabled?: boolean; items: ('home' | 'categories' | 'search' | 'orders' | 'account' | 'cart')[] };
    /** El botón flotante de WhatsApp: sin dirección no se pinta. */
    floating?: { whatsapp_href: string | null; position: 'right' | 'left' };
    card: CardSettings;
    cart_bar: {
        enabled: boolean;
        show_free_delivery_progress: boolean;
        texts: { missing: string; reached: string };
        desktop_behavior: 'drawer' | 'page';
    };
    category_page: {
        layout: 'sidebar' | 'top';
        filters: ('subcategories' | 'brand' | 'price' | 'deals' | 'attributes')[];
        sorts: string[];
        default_sort: string;
        page_size: number;
        pagination: 'pages' | 'load_more';
        show_subcategory_chips: boolean;
    };
    product_page: {
        show_unit_price: boolean;
        show_description: boolean;
        related: 'same_category' | 'best_sellers' | 'none';
        related_limit: number;
    };
    search: { suggestions: boolean; popular_terms: string[]; empty_title: string; empty_text: string };
    catalog: { show_out_of_stock?: boolean; price_per_unit: boolean; hide_zero_price: boolean };
    footer: {
        style?: 'full' | 'minimal';
        tone?: 'light' | 'dark' | 'brand';
        newsletter?: boolean;
        columns: { title: string; links: { label: string; href: string }[] }[];
        contact: {
            phone: string | null;
            whatsapp: string | null;
            whatsapp_href: string | null;
            email: string | null;
            address: string | null;
        };
        social: { network: string; label: string; url: string }[];
        payment_logos: { key: string; label: string }[];
        legal_text: string | null;
        legal: {
            name: string | null;
            tax_id: string | null;
            address: string | null;
            phone: string | null;
            email: string | null;
            terms_href: string | null;
            privacy_href: string | null;
            pqrs_href: string | null;
        };
        attribution: { label: string; url: string } | null;
    };
    delivery: { enabled: boolean; free_from: number; min_order_total: number };
    company: { name: string | null; slug: string | null };
}

export type FontPair = 'moderna' | 'editorial' | 'cercana' | 'mercado' | 'grotesca' | 'minimal' | 'lujo' | 'tecnica' | 'vitrina' | 'urbano' | 'sastre';

export interface StoreThemePayload {
    display_name?: string | null;
    tagline?: string | null;
    accent?: string;
    accent_ink?: string;
    deal_color?: string;
    deal_ink?: string;
    scheme?: 'light' | 'dark' | 'auto';
    allow_scheme_toggle?: boolean;
    font_pair?: FontPair;
    radius?: 'sharp' | 'soft' | 'round';
    density?: 'cozy' | 'compact';
    button_style?: 'solid' | 'outline';
    /** Formas nuevas: un retrato de antes no las trae y valen lo de siempre. */
    logo_font?: 'none' | 'italiana' | 'cormorant' | 'playfair' | 'anton';
    surface?: 'white' | 'warm' | 'cool' | 'sage' | 'sand';
    heading_case?: 'normal' | 'upper';
    container?: 'normal' | 'wide' | 'full';
    button_shape?: 'default' | 'pill' | 'square';
    logo?: MediaPayload | null;
    favicon?: MediaPayload | null;
    mark?: MediaPayload | null;
}

export interface StoreInfo {
    id: number;
    name: string;
    address: string | null;
    phone: string | null;
    opens_at: string;
    closes_at: string;
}

/** El cascarón de la tienda: tema, estructura global y la sede. */
export interface StoreShell {
    theme: StoreThemePayload;
    settings: StoreStructure;
    store: StoreInfo | null;
    stores?: { id: number; name: string }[];
}

export interface StoreBlock {
    uuid: string;
    type: string;
    page?: string;
    devices: 'all' | 'mobile' | 'desktop';
    is_visible: boolean;
    scheduled: boolean;
    data: Record<string, unknown>;
    /** El diseño de la sección ya resuelto (`SectionShell`). Un retrato viejo en caché no lo trae. */
    appearance?: Record<string, unknown>;
}

/** Zonas del cascarón que se pueden tocar en la vista previa para editarlas. */
export type ShellZone = 'topbar' | 'header' | 'nav' | 'cart_bar' | 'mobile_nav' | 'footer';

export interface Listing {
    items: CardProduct[];
    total: number;
    page: number;
    last_page: number;
    per_page: number;
    sort: string;
    facets: {
        subcategories: { id: number; name: string; href: string; count: number }[];
        brands: { value: string; count: number }[];
        price: { min: number; max: number } | null;
        /** «Talla: 8, 10, 12…»: valores de los artículos con subartículos que hay disponibles. */
        attributes?: { id: number; name: string; display: string; values: { id: number; value: string; color_hex: string | null; count: number }[] }[];
    };
    truncated: boolean;
}
