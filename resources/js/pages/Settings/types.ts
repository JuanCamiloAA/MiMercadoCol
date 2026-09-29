import type { NavigationOverrides } from '@/lib/navigation';
import type { MembershipStatus } from '@/types';

export type TabKey = 'company' | 'membership' | 'brand' | 'appearance' | 'navigation' | 'pos' | 'pos_rules' | 'receipt' | 'credit';

export type ThemeChoice = 'light' | 'dark' | 'system';

export type RailKey = 'sale' | 'opening' | 'closing';

export type ShortcutAction = 'scan' | 'customer' | 'discount' | 'hold' | 'resume' | 'recent' | 'pay' | 'pay_alt';

/** Los ajustes editables, con la misma clave que en el servidor. */
export interface SettingsValues {
    'brand.display_name': string;
    'brand.tagline': string | null;
    'brand.accent': string;
    'brand.accent_dark': string | null;

    'appearance.font_body': string;
    'appearance.font_display': string;
    'appearance.font_numeric': string;
    'appearance.radius': 'sharp' | 'soft' | 'round';
    'appearance.density': 'cozy' | 'compact';
    'panel.default_theme': ThemeChoice;
    'panel.allow_theme_toggle': boolean;
    'panel.sidebar_style': 'surface' | 'dark' | 'brand';
    'panel.sidebar_mode': 'expanded' | 'icons';
    'panel.ui_scale': number;
    'panel.content_width': 'centered' | 'wide' | 'full';

    /** PHP manda `[]` cuando esta vacio. */
    'panel.navigation': NavigationOverrides | [];
    'panel.home_by_role': Record<string, string> | [];

    'pos.default_theme': ThemeChoice;
    'pos.allow_theme_toggle': boolean;
    'pos.ui_scale': number;
    'pos.ticket_side': 'right' | 'left';
    'pos.catalog_view': 'grid' | 'list' | 'search';
    'pos.tile_size': 'sm' | 'md' | 'lg';
    'pos.total_size': 'normal' | 'large';
    'pos.rail': RailKey[];
    'pos.shortcuts': Record<ShortcutAction, string>;
    'pos.sounds': boolean;

    'pos.allow_discount_percent': number;
    'pos.cash_rounding': number;
    'returns.max_days': number;
    'returns.store_credit_days': number;

    'receipt.paper_width': number;
    'receipt.font_size': 'small' | 'normal' | 'large';
    'receipt.show_logo': boolean;
    'receipt.header': string | null;
    'receipt.fields': string[];
    'receipt.code': 'none' | 'barcode' | 'qr';
    'pos.receipt_footer': string;
    'receipt.copies': number;
    'receipt.auto_print': boolean;

    'credit.block_on_overdue_days': number;
}

export type SettingKey = keyof SettingsValues;

export interface Option<T extends string | number = string | number> {
    value: T;
    label: string;
    hint?: string;
    /** El texto corto del boton, donde lo hay (los atajos de la caja). */
    short?: string;
}

export interface FontOption {
    value: string;
    label: string;
    generic: string;
    /** Direccion de Google Fonts de esa familia, para verla en la vista previa. */
    href: string;
}

export interface StoreRow {
    id: number;
    name: string;
    code: string;
    address: string | null;
    phone: string | null;
    is_active: boolean;
}

export interface BrandAssets {
    mark: string | null;
    logo_light: string | null;
    logo_dark: string | null;
    logo_receipt: string | null;
}

export interface CompanyRow {
    id: number;
    name: string;
    legal_name: string;
    tax_id: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
}

export interface ReceiptSample {
    is_example: boolean;
    number: string;
    date: string;
    cashier: string | null;
    seller: string | null;
    customer: { name: string; document: string } | null;
    items: {
        description: string;
        quantity: string;
        unit_price: number;
        discount: number;
        line_total: number;
        /** Los artículos de un servicio, debajo de su línea, como en el tiquete impreso. */
        components?: { description: string; quantity: string; line_total: number; adjusted: boolean }[];
    }[];
    subtotal: number;
    tax_total: number;
    discount_total: number;
    rounding_total: number;
    total: number;
    payments: { label: string; amount: number }[];
    cash_received: number | null;
    change: number | null;
    barcode: string | null;
    qr: string | null;
    /** La canasta en negro, si se pide logo y no se ha subido ninguno. */
    default_logo: string;
}

export interface PosSample {
    products: { name: string; price: number; badge: string | null }[];
    categories: string[];
}

export interface ThemeTokenSet {
    accent: string;
    ink: string;
    soft: string;
    muted: string;
    mark: string;
}

/** Lo que la vista previa necesita para pintar un borrador igual que el CSS real. */
export interface PreviewTokens {
    factory_accent: string;
    factory: { light: ThemeTokenSet; dark: ThemeTokenSet };
    radii: Record<string, [number, number, number, number]>;
    densities: Record<string, number>;
    content_widths: Record<string, string>;
}

export interface MembershipUsage {
    used: number;
    limit: number | null;
}

export interface MembershipChargeRow {
    id: number;
    date: string | null;
    concept: string;
    period_starts_at: string | null;
    period_ends_at: string | null;
    amount: number;
    status: 'pending' | 'approved' | 'declined' | 'error' | 'voided';
    status_label: string;
    method_label: string;
    reference: string;
    failure_reason: string | null;
}

export interface MembershipPlanOption {
    id: number;
    name: string;
    description: string | null;
    features: string[];
    limits: { stores: number | null; users: number | null; registers: number | null };
    /** Precio por id de ciclo, calculado en el servidor. */
    prices: Record<string, number>;
}

/** Ajustes > Membresia. Todo precio viene calculado del servidor. */
export interface MembershipOverview {
    plan: { id: number; name: string; description: string | null; features: string[] } | null;
    cycle: { id: number; name: string; months: number } | null;
    price: number | null;
    status: MembershipStatus;
    status_label: string;
    started_at: string | null;
    ends_at: string | null;
    days_left: number | null;
    grace_ends_at: string | null;
    next_charge_at: string | null;
    auto_renew: boolean;
    scheduled_change: { plan: string | null; cycle: string | null; applies_at: string | null } | null;
    usage: Record<'stores' | 'users' | 'registers', MembershipUsage>;
    card: {
        brand: string | null;
        last4: string | null;
        exp_month: number | null;
        exp_year: number | null;
        holder: string | null;
        usable: boolean;
        other_environment: boolean;
        expires_before_renewal: boolean;
    } | null;
    gateway: { enabled: boolean; support_email: string | null };
    can_manage: boolean;
    quote: { amount: number; concept: string; starts_at: string; ends_at: string } | null;
    in_flight: boolean;
    plans: MembershipPlanOption[];
    cycles: { id: number; name: string; months: number; discount_percent: number }[];
    charges: MembershipChargeRow[];
}

export interface SettingsPageProps {
    settings: SettingsValues;
    defaults: SettingsValues;
    editable: Record<TabKey, boolean>;
    company: CompanyRow | null;
    stores: StoreRow[];
    storeOverrides: Record<string, Partial<SettingsValues>>;
    brandAssets: BrandAssets;
    fontOptions: { body: FontOption[]; display: FontOption[]; numeric: FontOption[] };
    previewTokens: PreviewTokens;
    options: Record<string, Option[]>;
    swatches: string[];
    roles: Option<string>[];
    homePages: Option<string>[];
    lockedNavigation: string[];
    receiptLimits: { max_lines: number; chars: Record<string, number> };
    receiptSample: ReceiptSample;
    posSample: PosSample;
    /** Null si la persona no puede ver la membresia. */
    membership: MembershipOverview | null;
}

/** Lo que cada pestana recibe: las props de la pantalla y si puede guardar. */
export interface TabProps extends SettingsPageProps {
    readOnly: boolean;
    onDirtyChange: (dirty: boolean) => void;
}
