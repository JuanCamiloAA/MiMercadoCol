import type { StoreThemeRow } from '@/components/storefront/StoreTheme';
import type { CartBrief, StoreShell } from '@/types/storefront';
import type { RealtimeConfig } from '@/hooks/use-realtime';
import type { ThemePreference } from '@/hooks/use-theme';
import type { NavigationOverrides } from '@/lib/navigation';
import type { Config as ZiggyConfig } from 'ziggy-js';

export interface AuthRole {
    id: number;
    name: string;
    display_name: string;
    color: string;
}

export interface AuthCompany {
    id: number;
    name: string;
}

export interface AuthStore {
    id: number;
    name: string;
    code: string;
}

export interface AuthUser {
    id: number;
    name: string;
    last_name: string | null;
    full_name: string;
    initials: string;
    email: string;
    avatar: string | null;
    job_title: string | null;
    is_super_admin: boolean;
    permissions: string[];
    accessible_pages: string[];
    role: AuthRole | null;
    company: AuthCompany | null;
    store: AuthStore | null;
}

export type MembershipStatus = 'trial' | 'active' | 'grace' | 'suspended' | 'cancelled';

export interface ActiveCompany {
    id: number;
    name: string;
    slug: string;
    legal_name: string;
    tax_id: string;
    logo: string | null;
    is_active: boolean;
    membership_status: MembershipStatus;
    /** Funciones del plan: `online_store`, `promotions`, `multi_warehouse`, `catalog_import`. */
    features: string[];
}

/** Una empresa del selector del super usuario. */
export interface CompanyOption {
    id: number;
    name: string;
    is_active: boolean;
    membership_status: MembershipStatus;
    membership_label: string;
}

/** La franja de aviso de membresia: prueba por vencer, gracia o suspension. */
export interface MembershipNotice {
    status: MembershipStatus;
    tone: 'warn' | 'danger';
    message: string;
    can_pay: boolean;
}

export interface ActiveStore {
    id: number;
    name: string;
    code: string;
    suggested_cash_base: number;
}

export interface StoreOption {
    id: number;
    name: string;
    code: string;
}

export interface LastSaleFlash {
    id: number;
    number: string;
    total: number;
    rounding_total: number;
    due_total: number;
    received_amount: number | null;
    change_amount: number | null;
    /** Con que se pago, medio por medio: un cobro puede venir repartido. */
    payments: { method: string; label: string; amount: number; reference: string | null }[];
    print_url: string;
    /** Si la venta fue un cambio: la devolución que la pagó y lo que sobró. */
    exchange?: { return_number: string; return_print_url: string; leftover: number; leftover_label: string } | null;
}

/** La devolución de caja recién registrada, para imprimir su comprobante. */
export interface LastReturnFlash {
    id: number;
    number: string;
    status: string;
    print_url: string;
}

export interface FlashMessages {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
    temporary_password?: string | null;
    last_sale?: LastSaleFlash | null;
    last_return?: LastReturnFlash | null;
}

export interface PermissionCatalogueEntry {
    name: string;
    label: string;
}

export interface PermissionCatalogueGroup {
    key: string;
    display: string;
    permissions: PermissionCatalogueEntry[];
}

export interface PermissionCatalogueModule {
    key: string;
    display: string;
    icon: string | null;
    order: number;
    super_admin_only: boolean;
    total: number;
    groups: PermissionCatalogueGroup[];
}

export interface StorefrontCustomer {
    id: number;
    name: string;
    document_number: string;
}

export interface BrandSurface {
    default_theme: ThemePreference;
    allow_theme_toggle: boolean;
}

export interface PanelSurface extends BrandSurface {
    sidebar_style: 'surface' | 'dark' | 'brand';
    sidebar_mode: 'expanded' | 'icons';
}

/** La marca del panel y la caja, ya resuelta por `BrandThemeService`. */
export interface BrandProps {
    display_name: string;
    tagline: string | null;
    mark_url: string | null;
    logo_light_url: string | null;
    logo_dark_url: string | null;
    favicon_url: string;
    touch_icon_url: string | null;
    theme_color: string;
    /** La letra que va sobre el acento en cada tema. */
    ink: { light: string; dark: string };
    /** Lo que va en `#brand-theme`. Vacio si todo es de fabrica. */
    css: string;
    fonts_href: string;
    surfaces: { panel: PanelSurface; pos: BrandSurface };
    /** Solo las diferencias con el menu del codigo. */
    navigation: NavigationOverrides | null;
}

export interface PageProps {
    auth: { user: AuthUser | null };
    customer: StorefrontCustomer | null;
    /** El aspecto publicado de la sede; solo llega en las rutas de la tienda en linea. */
    storeTheme: StoreThemeRow | null;
    /** El cascarón publicado de la tienda (estructura global y tema); solo en sus rutas. */
    storefront: StoreShell | null;
    /** El resumen del carrito de la tienda en línea; solo en sus rutas. */
    cart: CartBrief | null;
    /** «Ver la tienda» del panel: la tienda de la sede activa, o null si no puede vender. */
    storefrontUrl: string | null;
    /** La empresa y la sede de la tienda en línea que se está viendo (sale de la sede elegida). */
    storefrontCompany: { name: string; slug: string; store: { id: number; name: string } } | null;
    /** La marca del panel y la caja. En la tienda en linea llega null. */
    brand: BrandProps | null;
    /** El orden del riel de la caja; solo llega en las pantallas de caja. */
    posRail: ('sale' | 'opening' | 'closing')[] | null;
    /** Los canales de tiempo real que el servidor le armó a esta persona. */
    realtime: RealtimeConfig | null;
    activeCompany: ActiveCompany | null;
    /** Solo para el super usuario; vacio para los demas. */
    companiesForSelector: CompanyOption[];
    activeCompanyId: number | null;
    /** El super usuario en «Todas las empresas». */
    isConsolidatedView: boolean;
    membershipNotice: MembershipNotice | null;
    activeStore: ActiveStore | null;
    storesForSelector: StoreOption[];
    permissionMatrix: PermissionCatalogueModule[] | null;
    /** Cuantas funciones del catalogo no trae el plan de la empresa (la nota al pie del asignador). */
    permissionMatrixHidden: number;
    flash: FlashMessages;
    ziggy: ZiggyConfig & { location: string };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

export interface SelectOption {
    value: string | number;
    label: string;
}

declare global {
    // eslint-disable-next-line no-var
    var route: typeof import('ziggy-js').route;
}
