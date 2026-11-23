import {
    ArrowUUpLeftIcon,
    ArrowsClockwiseIcon,
    ArrowsLeftRightIcon,
    BankIcon,
    BuildingsIcon,
    CalendarCheckIcon,
    CashRegisterIcon,
    ChartBarIcon,
    ClipboardTextIcon,
    CreditCardIcon,
    CrownSimpleIcon,
    DownloadSimpleIcon,
    EnvelopeSimpleIcon,
    GearIcon,
    GlobeIcon,
    HouseIcon,
    IdentificationCardIcon,
    MapTrifoldIcon,
    MegaphoneIcon,
    PackageIcon,
    PaintBrushIcon,
    PlugsConnectedIcon,
    ReceiptIcon,
    ScalesIcon,
    ShieldCheckIcon,
    ShoppingBagIcon,
    ShoppingCartIcon,
    SquaresFourIcon,
    TagSimpleIcon,
    StackIcon,
    StorefrontIcon,
    TagIcon,
    TicketIcon,
    TrayArrowDownIcon,
    TrayIcon,
    TruckIcon,
    UploadSimpleIcon,
    UserGearIcon,
    UsersIcon,
    UsersThreeIcon,
    WalletIcon,
    WrenchIcon,
    type Icon,
} from '@phosphor-icons/react';

export interface NavLink {
    label: string;
    routeName: string;
    permission: string;
    /** Se pinta cuando el enlace va suelto; dentro de un grupo manda el ícono del grupo. */
    icon: Icon;
    /** Prefijos de ruta que dejan el elemento marcado como activo. */
    match: string[];
    /** Solo lo ve el super usuario, ademas de pedir su permiso. */
    superAdminOnly?: true;
    /** Funcion del plan que exige (ver `MembershipFeatures`). */
    feature?: string;
}

/** Un desplegable dentro de una sección. */
export interface NavGroup {
    key: string;
    label: string;
    icon: Icon;
    links: NavLink[];
}

export type NavEntry = NavLink | NavGroup;

export interface NavSection {
    key: string;
    title: string;
    entries: NavEntry[];
}

export const isNavGroup = (entry: NavEntry): entry is NavGroup => 'links' in entry;

/**
 * El menú lateral: secciones por área de trabajo y, dentro, grupos por tema.
 *
 * Cada grupo junta lo que se usa en el mismo rato de trabajo: quien cuenta bodega pasa de
 * existencias a traslados, no de existencias a clientes. Lo que se abre todo el día —el
 * inicio, la caja registradora, los informes— va suelto para no costar un clic de más.
 */
export const NAVIGATION: NavSection[] = [
    {
        key: 'operation',
        title: 'Operación',
        entries: [
            { label: 'Inicio', routeName: 'dashboard', permission: 'dashboard.index.view', icon: HouseIcon, match: ['dashboard'] },
            { label: 'Punto de venta', routeName: 'pos.index', permission: 'pos.sale.view', icon: ShoppingCartIcon, match: ['pos.'] },
            {
                key: 'sales',
                label: 'Ventas',
                icon: ReceiptIcon,
                links: [
                    { label: 'Historial de ventas', routeName: 'sales.index', permission: 'sales.index.view', icon: ReceiptIcon, match: ['sales.'] },
                    { label: 'Pedidos en línea', routeName: 'orders.index', permission: 'orders.index.view', icon: ClipboardTextIcon, match: ['orders.'] },
                    { label: 'Devoluciones', routeName: 'customer_returns.index', permission: 'sales.returns.view', icon: ArrowUUpLeftIcon, match: ['customer_returns.'] },
                ],
            },
            {
                key: 'registers',
                label: 'Caja',
                icon: CashRegisterIcon,
                links: [
                    { label: 'Cajas', routeName: 'registers.index', permission: 'registers.index.view', icon: CashRegisterIcon, match: ['registers.index', 'registers.opening', 'registers.closing', 'registers.import'] },
                    { label: 'Diferencias de caja', routeName: 'registers.differences.index', permission: 'registers.differences.view', icon: ScalesIcon, match: ['registers.differences'] },
                ],
            },
        ],
    },
    {
        key: 'merchandise',
        title: 'Mercancía',
        entries: [
            {
                key: 'catalog',
                label: 'Catálogo',
                icon: PackageIcon,
                links: [
                    { label: 'Artículos', routeName: 'products.index', permission: 'products.index.view', icon: PackageIcon, match: ['products.'] },
                    { label: 'Servicios', routeName: 'services.index', permission: 'services.index.view', icon: WrenchIcon, match: ['services.'] },
                    { label: 'Categorías', routeName: 'categories.index', permission: 'categories.index.view', icon: SquaresFourIcon, match: ['categories.'] },
                    { label: 'Atributos', routeName: 'attributes.index', permission: 'products.attributes.view', icon: TagSimpleIcon, match: ['attributes.'] },
                    { label: 'Importar artículos', routeName: 'catalog.import.index', permission: 'products.catalog_import.view', icon: DownloadSimpleIcon, match: ['catalog.import'], feature: 'catalog_import' },
                ],
            },
            {
                key: 'inventory',
                label: 'Inventario',
                icon: StackIcon,
                links: [
                    { label: 'Existencias', routeName: 'inventory.index', permission: 'inventory.index.view', icon: StackIcon, match: ['inventory.'] },
                    { label: 'Traslados', routeName: 'transfers.index', permission: 'inventory.transfers.view', icon: ArrowsLeftRightIcon, match: ['transfers.'] },
                    { label: 'Solicitudes de traslado', routeName: 'transfer_requests.index', permission: 'inventory.transfer_requests.view', icon: TrayArrowDownIcon, match: ['transfer_requests.'] },
                ],
            },
            {
                key: 'purchasing',
                label: 'Compras',
                icon: ShoppingBagIcon,
                links: [
                    { label: 'Órdenes de compra', routeName: 'purchases.index', permission: 'purchases.index.view', icon: ShoppingBagIcon, match: ['purchases.'] },
                    { label: 'Devoluciones a proveedor', routeName: 'supplier_returns.index', permission: 'purchases.returns.view', icon: ArrowUUpLeftIcon, match: ['supplier_returns.'] },
                    { label: 'Proveedores', routeName: 'suppliers.index', permission: 'suppliers.index.view', icon: TruckIcon, match: ['suppliers.'] },
                ],
            },
        ],
    },
    {
        key: 'commercial',
        title: 'Comercial',
        entries: [
            {
                key: 'customers',
                label: 'Clientes',
                icon: UsersIcon,
                links: [
                    { label: 'Clientes', routeName: 'customers.index', permission: 'customers.index.view', icon: UsersIcon, match: ['customers.'] },
                    { label: 'Cartera', routeName: 'credit.index', permission: 'credit.index.view', icon: CreditCardIcon, match: ['credit.'] },
                ],
            },
            {
                key: 'marketing',
                label: 'Mercadeo',
                icon: MegaphoneIcon,
                links: [
                    { label: 'Promociones', routeName: 'promotions.index', permission: 'promotions.index.view', icon: TagIcon, match: ['promotions.'], feature: 'promotions' },
                    { label: 'Cupones', routeName: 'coupons.index', permission: 'coupons.index.view', icon: TicketIcon, match: ['coupons.'], feature: 'promotions' },
                ],
            },
            {
                key: 'storefront',
                label: 'Tienda en línea',
                icon: StorefrontIcon,
                links: [
                    { label: 'Artículos en línea', routeName: 'storefront.catalog.index', permission: 'storefront.catalog.manage', icon: StorefrontIcon, match: ['storefront.catalog'], feature: 'online_store' },
                    { label: 'Editor de la tienda', routeName: 'storefront.editor.index', permission: 'storefront.editor.view', icon: PaintBrushIcon, match: ['storefront.editor'], feature: 'online_store' },
                    { label: 'Suscriptores', routeName: 'storefront.newsletter.index', permission: 'storefront.newsletter.view', icon: EnvelopeSimpleIcon, match: ['storefront.newsletter'], feature: 'online_store' },
                ],
            },
        ],
    },
    {
        key: 'analysis',
        title: 'Análisis',
        entries: [{ label: 'Informes', routeName: 'reports.index', permission: 'reports.sales.view', icon: ChartBarIcon, match: ['reports.'] }],
    },
    {
        key: 'admin',
        title: 'Administración',
        entries: [
            {
                key: 'team',
                label: 'Equipo',
                icon: UsersThreeIcon,
                links: [
                    { label: 'Empleados', routeName: 'employees.index', permission: 'employees.index.view', icon: IdentificationCardIcon, match: ['employees.'] },
                    { label: 'Usuarios', routeName: 'users.index', permission: 'users.index.view', icon: UserGearIcon, match: ['users.'] },
                    { label: 'Roles y permisos', routeName: 'roles.index', permission: 'roles.index.view', icon: ShieldCheckIcon, match: ['roles.'] },
                ],
            },
            {
                key: 'company',
                label: 'Empresa',
                icon: BuildingsIcon,
                links: [
                    { label: 'Sedes y bodegas', routeName: 'stores.index', permission: 'stores.index.view', icon: BuildingsIcon, match: ['stores.'] },
                    { label: 'Ajustes', routeName: 'settings.index', permission: 'settings.index.view', icon: GearIcon, match: ['settings.'] },
                    { label: 'Medios de pago', routeName: 'payment_methods.index', permission: 'payment_methods.index.view', icon: WalletIcon, match: ['payment_methods.'] },
                    { label: 'Plazos de pago', routeName: 'payment_terms.index', permission: 'payment_terms.index.view', icon: CalendarCheckIcon, match: ['payment_terms.'] },
                ],
            },
        ],
    },
    {
        // La plataforma: solo el super usuario. No la tocan las personalizaciones de menu de una empresa.
        key: 'platform',
        title: 'Plataforma',
        entries: [
            { label: 'Empresas', routeName: 'companies.index', permission: 'companies.index.view', icon: BankIcon, match: ['companies.'], superAdminOnly: true },
            {
                key: 'platform_users',
                label: 'Usuarios',
                icon: UserGearIcon,
                links: [
                    { label: 'De plataforma', routeName: 'platform.users.index', permission: 'platform.users.view', icon: UserGearIcon, match: ['platform.users.'], superAdminOnly: true },
                    { label: 'De empresas', routeName: 'platform.company_users.index', permission: 'platform.company_users.view', icon: UsersThreeIcon, match: ['platform.company_users.'], superAdminOnly: true },
                ],
            },
            { label: 'Solicitudes', routeName: 'platform.leads.index', permission: 'platform.leads.view', icon: TrayIcon, match: ['platform.leads.'], superAdminOnly: true },
            { label: 'Planes de membresía', routeName: 'platform.plans.index', permission: 'platform.plans.view', icon: CrownSimpleIcon, match: ['platform.plans.'], superAdminOnly: true },
            { label: 'Ciclos de cobro', routeName: 'platform.billing_cycles.index', permission: 'platform.billing_cycles.view', icon: ArrowsClockwiseIcon, match: ['platform.billing_cycles.'], superAdminOnly: true },
            { label: 'Clasificaciones', routeName: 'platform.business_categories.index', permission: 'platform.business_categories.view', icon: TagIcon, match: ['platform.business_categories.'], superAdminOnly: true },
            { label: 'Pasarela de pagos', routeName: 'platform.gateway.index', permission: 'platform.gateway.view', icon: CreditCardIcon, match: ['platform.gateway.'], superAdminOnly: true },
            { label: 'Pasarelas para empresas', routeName: 'platform.company_gateways.index', permission: 'platform.gateway.view', icon: PlugsConnectedIcon, match: ['platform.company_gateways.'], superAdminOnly: true },
            { label: 'Mapas', routeName: 'platform.maps.index', permission: 'platform.maps.view', icon: MapTrifoldIcon, match: ['platform.maps.'], superAdminOnly: true },
            { label: 'Landing pública', routeName: 'platform.landing.index', permission: 'platform.landing.view', icon: GlobeIcon, match: ['platform.landing.'], superAdminOnly: true },
            { label: 'Plantillas de tienda', routeName: 'platform.store_templates.index', permission: 'platform.store_templates.view', icon: PaintBrushIcon, match: ['platform.store_templates.'], superAdminOnly: true },
            { label: 'Carga masiva (CSV)', routeName: 'platform.imports.index', permission: 'platform.imports.view', icon: UploadSimpleIcon, match: ['platform.imports.'], superAdminOnly: true },
        ],
    },
];

/** Secciones que la empresa no puede reordenar, renombrar ni ocultar: no son suyas. */
export const PLATFORM_SECTIONS = ['platform'];

/**
 * Lo que la empresa cambio del menu en Ajustes. Solo diferencias: el catalogo sigue siendo
 * `NAVIGATION`, porque los iconos son componentes y no viajan por JSON.
 */
// Alias y no interfaz: asi cabe en los datos de un `useForm` de Inertia.
export type NavigationOverrides = {
    /** Claves de seccion, en el orden elegido. */
    section_order?: string[];
    /** Por seccion: claves de grupo o `routeName` del enlace suelto, en el orden elegido. */
    entry_order?: Record<string, string[]>;
    /** Clave → nombre que se lee. La clave de un enlace es su `routeName`. */
    labels?: Record<string, string>;
    /** Claves ocultas: secciones, grupos o enlaces. */
    hidden?: string[];
};

/**
 * Lo que no se puede ocultar: la seccion y el grupo que contienen Ajustes, y el enlace mismo.
 * Si se ocultaran, nadie podria volver a esta pantalla para deshacerlo. Es la misma lista que
 * `NavigationOverrides::LOCKED` en el servidor, que es quien de verdad lo hace cumplir.
 */
export const LOCKED_NAVIGATION = ['admin', 'company', 'settings.index'];

/** Clave estable de una entrada: el grupo por su `key`, el enlace suelto por su ruta. */
export const entryKey = (entry: NavEntry): string => (isNavGroup(entry) ? entry.key : entry.routeName);

/**
 * Reordena respetando lo nuevo: los elementos que la empresa ordeno ocupan, en su orden, los
 * lugares que ya tenian; lo que no conoce (una pantalla que se agrego despues) se queda en su
 * sitio por defecto.
 */
function reorder<T>(items: T[], order: string[] | undefined, keyOf: (item: T) => string): T[] {
    if (!order || order.length === 0) {
        return items;
    }

    const known = new Set(order);
    const queue = order
        .map((key) => items.find((item) => keyOf(item) === key))
        .filter((item): item is T => item !== undefined);

    return items.map((item) => (known.has(keyOf(item)) ? (queue.shift() ?? item) : item));
}

/**
 * Aplica lo que la empresa configuro sobre el catalogo del codigo.
 *
 * Lo desconocido se ignora y lo nuevo aparece en su sitio por defecto: una pantalla que se
 * agregue manana se ve sin que nadie tenga que volver a configurar el menu. Esto solo oculta,
 * renombra y ordena; el permiso se filtra despues, y nunca al reves.
 */
export function applyNavigationOverrides(base: NavSection[], overrides: NavigationOverrides | null | undefined): NavSection[] {
    if (!overrides || Array.isArray(overrides)) {
        return base;
    }

    // La seccion de plataforma queda por fuera: se aplica a lo demas y se pega al final.
    const platform = base.filter((section) => PLATFORM_SECTIONS.includes(section.key));
    const company = base.filter((section) => !PLATFORM_SECTIONS.includes(section.key));

    const labels = overrides.labels ?? {};
    const hidden = new Set((overrides.hidden ?? []).filter((key) => !LOCKED_NAVIGATION.includes(key)));
    const label = (key: string, fallback: string) => {
        const custom = labels[key];

        return typeof custom === 'string' && custom.trim() !== '' ? custom.trim() : fallback;
    };

    const sections = company
        .filter((section) => !hidden.has(section.key))
        .map((section) => {
            const entries = section.entries
                .filter((entry) => !hidden.has(entryKey(entry)))
                .map((entry): NavEntry => {
                    if (!isNavGroup(entry)) {
                        return { ...entry, label: label(entry.routeName, entry.label) };
                    }

                    return {
                        ...entry,
                        label: label(entry.key, entry.label),
                        links: entry.links
                            .filter((link) => !hidden.has(link.routeName))
                            .map((link) => ({ ...link, label: label(link.routeName, link.label) })),
                    };
                });

            return {
                ...section,
                title: label(section.key, section.title),
                entries: reorder(entries, overrides.entry_order?.[section.key], entryKey),
            };
        });

    return [...reorder(sections, overrides.section_order, (section) => section.key), ...platform];
}
