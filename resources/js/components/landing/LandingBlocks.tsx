import { Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowRightIcon,
    BasketIcon,
    CaretDownIcon,
    CaretRightIcon,
    CaretUpIcon,
    CheckIcon,
    ClockIcon,
    CrosshairIcon,
    FacebookLogoIcon,
    GlobeIcon,
    InstagramLogoIcon,
    LinkedinLogoIcon,
    ListIcon,
    MagnifyingGlassIcon,
    MapPinIcon,
    MapTrifoldIcon,
    NavigationArrowIcon,
    QuotesIcon,
    ShoppingCartIcon,
    SparkleIcon,
    TiktokLogoIcon,
    TruckIcon,
    WhatsappLogoIcon,
    XIcon,
    YoutubeLogoIcon,
    type Icon,
} from '@phosphor-icons/react';
import { type FormEvent, type ReactNode, type RefObject, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { LANDING_ICONS, landingIcon } from '@/components/landing/landing-icons';
import { type MapFocus, StoreMap, StoreMapDialog, type StoreMapProps } from '@/components/landing/StoreFinderMap';
import { deliveryLabel, distanceLabel, distanceOf, DotList, StoreLogo, StoreStatus } from '@/components/landing/store-finder-parts';
import { AppDemoBlock } from '@/components/landing/AppDemo';
import { BAND_BG, bool, list, Section, SectionTitle, text } from '@/components/landing/landing-ui';
import { MediaCarouselBlock, VideoBlock, VideoShowcaseBlock } from '@/components/landing/LandingMedia';
import { type Bounds, inBounds, type LatLng, type MapConfig } from '@/components/maps/types';
import { SectionShell } from '@/components/sections/SectionShell';
import { ThemeToggle } from '@/components/ui/ThemeToggle';
import { useDocumentTheme } from '@/hooks/use-document-theme';
import type { ThemePreference } from '@/hooks/use-theme';
import { cn } from '@/lib/cn';
import { pickUrls } from '@/lib/media-variants';
import { money } from '@/lib/format';
import { csrfHeaders } from '@/lib/http';
import type { PageProps } from '@/types';

/** Los iconos de `config/landing_icons.php`: viven en `landing-icons.ts` para que el panel los use sin cargar la landing. */
export const ICONS = LANDING_ICONS;

const SOCIAL: Record<string, Icon> = {
    instagram: InstagramLogoIcon,
    facebook: FacebookLogoIcon,
    tiktok: TiktokLogoIcon,
    youtube: YoutubeLogoIcon,
    linkedin: LinkedinLogoIcon,
};

export interface LandingSite {
    name: string;
    logo_url: string | null;
    logo_dark_url: string | null;
    mark_url: string | null;
    seo_title: string;
    seo_description: string | null;
    seo_image_url: string | null;
    favicon_url: string | null;
    whatsapp: string | null;
    support_email: string | null;
    terms_url: string;
    privacy_url: string;
    login_url: string;
    signup_url: string;
    contact_url: string;
}

/** La clasificación de una empresa: alimentos, textiles, belleza… */
export interface LandingCategory {
    id: number;
    name: string;
    slug: string;
    icon: string;
    /** Cuántas tiendas (o empresas) de esa clasificación hay en la lista. */
    count?: number;
}

/** El domicilio en corto, de la versión publicada de la tienda. Montos en COP entero. */
export interface LandingStoreDelivery {
    enabled: boolean;
    /** Lo menos que alguien paga; `null` sin domicilio. */
    fee_from: number | null;
    /** `null` cuando no hay domicilio gratis: nunca «gratis desde $ 0». */
    free_from: number | null;
    min_order_total: number;
    /** «hoy entre 4:00 y 6:00 p. m.»; solo si algún buscador la muestra. */
    next_slot: string | null;
}

export interface LandingStore {
    id: number;
    name: string;
    company: string;
    company_name: string;
    company_id: number;
    category: LandingCategory | null;
    address: string | null;
    city: string | null;
    latitude: number | null;
    longitude: number | null;
    opens_at: string | null;
    closes_at: string | null;
    is_open: boolean;
    /** «cierra 9:00 p. m.», «abre 7:00 a. m.» o «abre mañana 7:00 a. m.»; vacía sin horario. */
    hours_label: string;
    delivery: LandingStoreDelivery;
    accent: string;
    accent_ink: string;
    logo_url: string | null;
    url: string;
    /** La ruta en Google Maps; `null` si la sede no tiene coordenadas. */
    directions_url: string | null;
    distance_km?: number | null;
}

export interface LandingPlan {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    is_featured: boolean;
    trial_days: number;
    limits: { stores: number | null; users: number | null; registers: number | null };
    /** Lo que se muestra del plan: lo que escribio la plataforma o sus modulos completos. */
    highlights: string[];
    prices: Record<string, number>;
    monthly: Record<string, number>;
}

/** Un renglon de la tabla comparativa: un modulo y, por plan, si lo trae todo, en parte o nada. */
export interface LandingComparisonRow {
    key: string;
    display: string;
    plans: Record<string, 'all' | 'some' | 'none'>;
}

export interface LandingCycle {
    id: number;
    code: string;
    name: string;
    months: number;
    discount_percent: number;
}

export interface LandingBlockView {
    uuid: string;
    type: string;
    is_visible: boolean;
    // Cada tipo trae su forma; se lee con los ayudantes de abajo.
    data: Record<string, unknown>;
    source: Record<string, unknown> | null;
    /** El diseño de la sección ya resuelto (`LandingRenderer::resolve`); el encabezado y el pie no llevan. */
    appearance?: Record<string, unknown> | null;
}

/**
 * El modo de la página: con el que nace mientras el visitante no escoja y si está fijo (la vista
 * previa del editor fija el que se revisa). El selector usa estos mismos valores que la página:
 * con otros, cada uno aplicaría un tema distinto.
 */
export interface LandingTheme {
    fallback: ThemePreference;
    locked: boolean;
}

interface Context {
    site: LandingSite;
    panel: { name: string; url: string } | null;
    isPreview: boolean;
    theme: LandingTheme;
    /** El encabezado decide si se ofrece el selector de modo; lo pintan el encabezado y el pie. */
    themeToggle: boolean;
}

interface LandingBlocksProps {
    blocks: LandingBlockView[];
    site: LandingSite;
    panel: { name: string; url: string } | null;
    isPreview: boolean;
    theme?: LandingTheme;
}

/**
 * Pinta la landing bloque por bloque. Lo mismo en la página pública y en la vista previa del
 * editor: los datos de la base (sedes, empresas, planes) ya vienen resueltos del servidor.
 */
export function LandingBlocks({ blocks, site, panel, isPreview, theme = { fallback: 'light', locked: false } }: LandingBlocksProps) {
    const themeToggle = !blocks.some((block) => block.type === 'header' && block.data.theme_toggle === false);
    const context: Context = { site, panel, isPreview, theme, themeToggle };

    return (
        <>
            {blocks.map((block) =>
                // El encabezado y el pie van sin envoltura: el encabezado es fijo al desplazar.
                block.appearance ? (
                    <SectionShell
                        key={block.uuid}
                        surface="landing"
                        as="div"
                        appearance={block.appearance}
                        replayKey={isPreview ? JSON.stringify(block.appearance) : undefined}
                    >
                        <LandingBlock block={block} context={context} />
                    </SectionShell>
                ) : (
                    <LandingBlock key={block.uuid} block={block} context={context} />
                ),
            )}
        </>
    );
}

function LandingBlock({ block, context }: { block: LandingBlockView; context: Context }) {
    switch (block.type) {
        case 'header':
            return <HeaderBlock data={block.data} context={context} />;
        case 'hero':
            return <HeroBlock data={block.data} />;
        case 'store_finder':
            return (
                <StoreFinderBlock
                    data={block.data}
                    stores={list<LandingStore>(block.source?.stores)}
                    categories={list<LandingCategory>(block.source?.categories)}
                    map={(block.source?.map as MapConfig | undefined) ?? null}
                    isPreview={context.isPreview}
                />
            );
        case 'how_it_works':
            return <StepsBlock data={block.data} />;
        case 'video_showcase':
            return <VideoShowcaseBlock data={block.data} isPreview={context.isPreview} />;
        case 'video':
            return <VideoBlock data={block.data} isPreview={context.isPreview} />;
        case 'media_carousel':
            return <MediaCarouselBlock data={block.data} isPreview={context.isPreview} />;
        case 'app_demo':
            return <AppDemoBlock data={block.data} source={block.source} />;
        case 'companies_showcase':
            return <CompaniesBlock data={block.data} companies={list<Record<string, unknown>>(block.source?.companies)} categories={list<LandingCategory>(block.source?.categories)} />;
        case 'benefits':
            return <BenefitsBlock data={block.data} />;
        case 'plans':
            return (
                <PlansBlock
                    data={block.data}
                    plans={list<LandingPlan>(block.source?.plans)}
                    cycles={list<LandingCycle>(block.source?.cycles)}
                    comparison={list<LandingComparisonRow>(block.source?.comparison)}
                    signupUrl={context.site.signup_url}
                />
            );
        case 'testimonials':
            return <TestimonialsBlock data={block.data} />;
        case 'faq':
            return <FaqBlock data={block.data} />;
        case 'cta_band':
            return <CtaBandBlock data={block.data} />;
        case 'contact':
            return <ContactBlock data={block.data} site={context.site} />;
        case 'footer':
            return <FooterBlock data={block.data} context={context} />;
        default:
            return null;
    }
}

function CtaLink({ href, children, variant = 'primary', className }: { href: string; children: ReactNode; variant?: 'primary' | 'secondary' | 'light'; className?: string }) {
    if (!href || !children) {
        return null;
    }

    return (
        <a
            href={href}
            className={cn(
                'inline-flex h-12 items-center justify-center gap-2 rounded-control px-5 text-[0.9375rem] font-semibold transition-opacity hover:opacity-90',
                variant === 'primary' && 'bg-[var(--accent)] text-[var(--accent-ink)]',
                variant === 'secondary' && 'border border-[var(--line-2)] bg-[var(--surface)] text-[var(--text)]',
                variant === 'light' && 'bg-white text-[#14171A]',
                className,
            )}
        >
            {children}
        </a>
    );
}

/** Claro, oscuro o el del equipo, para quien visita. En la vista previa solo muestra el modo que se revisa. */
function LandingThemeToggle({ context, className }: { context: Context; className?: string }) {
    if (!context.themeToggle) {
        return null;
    }

    return <ThemeToggle fallback={context.theme.fallback} locked={context.theme.locked} compact className={className} />;
}

function HeaderBlock({ data, context }: { data: Record<string, unknown>; context: Context }) {
    const [open, setOpen] = useState(false);
    const name = text(data.brand_name) || context.site.name;
    const links = list<{ label: string; url: string }>(data.links).filter((link) => link.label && link.url);
    // La landing respeta el tema de quien la mira. Sin logo para fondo oscuro, el claro.
    const theme = useDocumentTheme();
    const lightLogo = text(data.logo_url) || context.site.logo_url;
    const logo = theme === 'dark' ? text(data.logo_dark_url) || context.site.logo_dark_url || lightLogo : lightLogo;

    const access = context.panel ? (
        <a href={context.panel.url} className="inline-flex h-10 items-center rounded-control bg-[var(--accent)] px-4 text-[0.875rem] font-semibold text-[var(--accent-ink)]">
            Ir al panel
        </a>
    ) : (
        <a
            href={context.site.login_url}
            className="inline-flex h-10 items-center rounded-control border border-[var(--line-2)] px-4 text-[0.875rem] font-semibold text-[var(--text)] hover:bg-[var(--surface-2)]"
        >
            {text(data.login_label) || 'Ingresar como empresa'}
        </a>
    );

    return (
        <header className="sticky top-0 z-30 border-b border-[var(--line)] bg-[var(--surface)]/95 backdrop-blur">
            <div className="mx-auto flex h-16 w-full max-w-[70rem] items-center gap-4 px-4">
                <a href="/" className="flex min-w-0 items-center gap-2">
                    {logo ? (
                        <img src={logo} alt={name} className="h-9 w-auto max-w-[10rem] object-contain" />
                    ) : (
                        <>
                            <span className="grid size-9 place-items-center rounded-control bg-[var(--accent)] text-[var(--accent-ink)]">
                                <BasketIcon size={20} weight="bold" />
                            </span>
                            <span className="truncate text-[1.0625rem] font-semibold tracking-tight">{name}</span>
                        </>
                    )}
                </a>

                <nav className="ml-4 hidden flex-1 items-center gap-1 md:flex">
                    {links.map((link) => (
                        <a key={`${link.label}${link.url}`} href={link.url} className="rounded-control px-3 py-2 text-[0.875rem] font-medium text-[var(--muted)] hover:text-[var(--text)]">
                            {link.label}
                        </a>
                    ))}
                </nav>

                <div className="ml-auto hidden items-center gap-2 md:flex">
                    {/* Entre tableta y escritorio angosto no cabe junto a los enlaces: ahi queda el del pie. */}
                    <LandingThemeToggle context={context} className="hidden lg:inline-flex" />
                    {access}
                    {!context.panel && text(data.cta_label) && (
                        <a href={text(data.cta_url) || context.site.signup_url} className="inline-flex h-10 items-center rounded-control bg-[var(--accent)] px-4 text-[0.875rem] font-semibold text-[var(--accent-ink)]">
                            {text(data.cta_label)}
                        </a>
                    )}
                </div>

                <button type="button" className="ml-auto grid size-10 place-items-center rounded-control border border-[var(--line)] md:hidden" onClick={() => setOpen(!open)} aria-label="Menú">
                    {open ? <XIcon size={20} /> : <ListIcon size={20} />}
                </button>
            </div>

            {open && (
                <div className="border-t border-[var(--line)] bg-[var(--surface)] px-4 py-3 md:hidden">
                    <nav className="flex flex-col gap-1">
                        {links.map((link) => (
                            <a key={`${link.label}${link.url}`} href={link.url} onClick={() => setOpen(false)} className="rounded-control px-2 py-2.5 text-[0.9375rem] font-medium">
                                {link.label}
                            </a>
                        ))}
                    </nav>
                    {context.themeToggle && (
                        <div className="mt-2 flex items-center justify-between gap-3 border-t border-[var(--line)] px-2 pt-3">
                            <span className="text-[0.875rem] font-medium text-[var(--muted)]">Modo de pantalla</span>
                            <LandingThemeToggle context={context} />
                        </div>
                    )}
                    <div className="mt-3 flex flex-wrap gap-2">
                        {access}
                        {!context.panel && text(data.cta_label) && (
                            <a href={text(data.cta_url) || context.site.signup_url} className="inline-flex h-10 items-center rounded-control bg-[var(--accent)] px-4 text-[0.875rem] font-semibold text-[var(--accent-ink)]">
                                {text(data.cta_label)}
                            </a>
                        )}
                    </div>
                </div>
            )}
        </header>
    );
}

function HeroBlock({ data }: { data: Record<string, unknown> }) {
    const centered = data.align === 'center';
    // La imagen del modo de la página (claro u oscuro) y, en celular, la de celular si la hay.
    const picked = pickUrls(data, 'image', useDocumentTheme());
    const image = picked.desktop ?? '';
    const phone = picked.mobile;

    return (
        <section className="relative overflow-hidden px-[var(--sec-px,1rem)] pt-[var(--sec-pt,3rem)] pb-[var(--sec-pb,3.5rem)] md:pt-[var(--sec-pt,5rem)] md:pb-[var(--sec-pb,5rem)]">
            {/* El resplandor es el fondo de fábrica: con fondo propio de la sección se apaga. */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-x-0 top-0 h-[28rem] opacity-60"
                style={{ background: 'var(--sec-own-bg, radial-gradient(60% 60% at 20% 0%, var(--accent-soft), transparent 70%))' }}
            />
            <div className={cn('relative mx-auto grid w-full max-w-[var(--sec-max,70rem)] items-center gap-10', !centered && image && 'md:grid-cols-[1.1fr_1fr]')}>
                <div className={cn(centered && 'mx-auto max-w-[46rem] text-center')}>
                    {text(data.eyebrow) && (
                        <p className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-[var(--accent-soft)] px-3 py-1 text-[0.8125rem] font-semibold text-[var(--accent)]">
                            <SparkleIcon size={14} weight="fill" /> {text(data.eyebrow)}
                        </p>
                    )}
                    <h1 className="text-[2.25rem] leading-[1.08] tracking-tight md:text-[3.25rem]">{text(data.title)}</h1>
                    {text(data.subtitle) && <p className="mt-4 text-[1.0625rem] text-[var(--muted)] md:text-[1.1875rem]">{text(data.subtitle)}</p>}
                    <div className={cn('mt-7 flex flex-wrap gap-3', centered && 'justify-center')}>
                        <CtaLink href={text(data.cta_url)}>
                            {text(data.cta_label)} <ArrowRightIcon size={18} weight="bold" />
                        </CtaLink>
                        <CtaLink href={text(data.secondary_url)} variant="secondary">
                            {text(data.secondary_label)}
                        </CtaLink>
                    </div>
                </div>
                {image && (
                    <picture key={`${image}|${phone ?? ''}`} className={cn(centered && 'mx-auto mt-2 block w-full max-w-[60rem]')}>
                        {phone && <source media="(max-width: 767px)" srcSet={phone} />}
                        <img
                            src={image}
                            alt=""
                            className={cn('w-full rounded-sheet object-cover shadow-xl', centered ? 'aspect-[16/7]' : 'aspect-[4/3]')}
                        />
                    </picture>
                )}
            </div>
        </section>
    );
}

const LAST_STORE_KEY = 'landing.last_store';

interface LastStore {
    id: number;
    name: string;
    company: string;
    url: string;
}

function readLastStore(): LastStore | null {
    try {
        const raw = window.localStorage.getItem(LAST_STORE_KEY);

        return raw ? (JSON.parse(raw) as LastStore) : null;
    } catch {
        return null;
    }
}

const MAP_LAYOUTS = ['side', 'top', 'button', 'none'] as const;
const MAP_HEIGHTS = ['sm', 'md', 'lg'] as const;
const MAP_THEMES = ['auto', 'light', 'dark'] as const;
const SIDE_HEIGHTS = { sm: 'h-[24rem] lg:h-[28rem]', md: 'h-[28rem] lg:h-[36rem]', lg: 'h-[32rem] lg:h-[44rem]' } as const;
/** Con la hoja del celular, el alto en el celular lo pone el bloque; el del computador sigue a `map_height`. */
const SIDE_HEIGHTS_LG = { sm: 'lg:h-[28rem]', md: 'lg:h-[36rem]', lg: 'lg:h-[44rem]' } as const;
const TOP_HEIGHTS = { sm: 'h-[14rem]', md: 'h-[20rem]', lg: 'h-[28rem]' } as const;
const LIST_WIDTHS = { narrow: 'lg:grid-cols-[26rem_minmax(0,1fr)]', medium: 'lg:grid-cols-[30rem_minmax(0,1fr)]' } as const;
const OTHERS_COLUMNS = { '2': 'lg:grid-cols-2', '3': 'lg:grid-cols-3', '4': 'lg:grid-cols-4' } as const;
const SHEET_HEIGHTS = { closed: '', half: 'h-1/2 lg:h-auto', full: 'h-[85%] lg:h-auto' } as const;
const FINDER_LAYOUTS = ['list_map', 'nearest_first', 'by_company'] as const;

type SheetState = keyof typeof SHEET_HEIGHTS;
type FinderMapProps = Omit<StoreMapProps, 'onExpand' | 'gestures' | 'className'>;

const option = <T extends string>(value: unknown, options: readonly T[], fallback: T): T => (options.includes(value as T) ? (value as T) : fallback);
const flag = (value: unknown, fallback: boolean): boolean => (typeof value === 'boolean' ? value : fallback);
const hasHours = (store: LandingStore): boolean => Boolean(store.opens_at && store.closes_at);
const noop = () => undefined;

/**
 * Los ajustes del bloque con su tipo. El servidor ya los normalizó (`StoreFinderBlock::normalize`);
 * los respaldos son sus mismos defectos. Los select numéricos se comparan con `String(...)`.
 */
function finderOptions(data: Record<string, unknown>) {
    return {
        layout: option(data.layout, FINDER_LAYOUTS, 'list_map'),
        ctaLabel: text(data.cta_label) || 'Comprar en esta tienda',
        lastStoreStyle: option(data.last_store_style, ['chip', 'banner', 'off'] as const, 'chip'),
        filterOpenNow: flag(data.filter_open_now, true),
        filterDelivery: flag(data.filter_delivery, true),
        showHours: flag(data.show_hours, true),
        showDistance: flag(data.show_distance, true),
        showDelivery: flag(data.show_delivery, true),
        showNextSlot: flag(data.show_next_slot, false),
        emptyText: text(data.empty_text) || 'No hay tiendas.',
        numbered: flag(data.list_numbered, true),
        listWidth: option(data.list_width, ['narrow', 'medium'] as const, 'medium'),
        ctaMode: option(data.cta_mode, ['selected', 'all'] as const, 'selected'),
        preselect: flag(data.preselect_nearest, true),
        mobileMode: option(data.mobile_mode, ['tabs', 'sheet'] as const, 'sheet'),
        heroBadge: text(data.hero_badge),
        heroFallback: option(data.hero_fallback, ['last_store', 'prompt', 'none'] as const, 'prompt'),
        heroMap: flag(data.hero_map, true),
        othersTitle: text(data.others_title),
        othersColumns: option(String(data.others_columns), ['2', '3', '4'] as const, '3'),
        othersLimit: option(String(data.others_limit), ['6', '9', '12', 'all'] as const, '9'),
        companyOrder: option(data.company_order, ['nearest', 'name', 'stores_count'] as const, 'nearest'),
        companyHeader: option(data.company_header, ['accent', 'neutral'] as const, 'accent'),
        storesPerCompany: option(String(data.stores_per_company), ['3', '5', 'all'] as const, '3'),
        companyCta: flag(data.company_cta, true),
    };
}

type FinderOptions = ReturnType<typeof finderOptions>;

/**
 * El buscador de tiendas. Todo el estado vive aquí (filtros, ubicación, zona, sede elegida) y lo
 * comparten la ventana ampliada y los tres diseños: cada uno recibe la misma lista filtrada
 * (`listStores`) y solo cambia cómo la pinta.
 */
function StoreFinderBlock({
    data,
    stores,
    categories,
    map,
    isPreview,
}: {
    data: Record<string, unknown>;
    stores: LandingStore[];
    categories: LandingCategory[];
    map: MapConfig | null;
    isPreview: boolean;
}) {
    const options = finderOptions(data);
    const cities = useMemo(() => Array.from(new Set(stores.map((store) => store.city).filter((city): city is string => Boolean(city)))).sort(), [stores]);
    const defaultCity = text(data.default_city);
    const [city, setCity] = useState(cities.includes(defaultCity) ? defaultCity : '');
    // El tipo de comercio elegido. `?tipo=belleza-y-cuidado-personal` llega ya filtrado: es el enlace que se comparte.
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [query, setQuery] = useState('');
    // «Abiertas ahora» y «Con domicilio»: estado de la página, no van al enlace.
    const [openNow, setOpenNow] = useState(false);
    const [withDelivery, setWithDelivery] = useState(false);
    const [nearby, setNearby] = useState<LandingStore[] | null>(null);
    const [locating, setLocating] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [last, setLast] = useState<LastStore | null>(null);
    // El mapa: dónde está la persona (si la compartió), la sede señalada y la elegida, y la zona buscada.
    const [me, setMe] = useState<LatLng | null>(null);
    const [hoveredId, setHoveredId] = useState<number | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [focus, setFocus] = useState<MapFocus | null>(null);
    const [area, setArea] = useState<Bounds | null>(null);
    const [mobileView, setMobileView] = useState<'list' | 'map'>('list');
    const [sheet, setSheet] = useState<SheetState>('closed');
    const [expanded, setExpanded] = useState(false);
    const mapBox = useRef<HTMLDivElement>(null);
    const pageTheme = useDocumentTheme();

    // El mapa que admite cada diseño lo fija el servidor; sin proveedor, ninguno.
    const chosenMap = option(data.map_layout, MAP_LAYOUTS, 'button');
    const mapLayout = !map ? 'none' : options.layout === 'list_map' ? 'side' : chosenMap === 'side' ? 'button' : chosenMap;
    const height = option(data.map_height, MAP_HEIGHTS, 'md');
    const chosenTheme = option(data.map_theme, MAP_THEMES, 'auto');
    const mapTheme = chosenTheme === 'auto' ? pageTheme : chosenTheme;
    const expandable = mapLayout === 'button' || (mapLayout !== 'none' && data.map_expand !== false);
    // Con el filtro de clasificación en pantalla, la fila no la repite.
    const showCategory = categories.length <= 1;

    useEffect(() => {
        const saved = readLastStore();
        // Solo si esa sede sigue vendiendo.
        setLast(saved && stores.some((store) => store.id === saved.id) ? saved : null);
    }, [stores]);

    useEffect(() => {
        if (isPreview) {
            return;
        }

        const wanted = new URLSearchParams(window.location.search).get('tipo');
        const found = categories.find((category) => category.slug === wanted);

        if (found) {
            setCategoryId(found.id);
        }
    }, [categories, isPreview]);

    const chooseCategory = (category: LandingCategory | null) => {
        setCategoryId(category?.id ?? null);

        if (isPreview) {
            return;
        }

        try {
            const url = new URL(window.location.href);

            if (category) {
                url.searchParams.set('tipo', category.slug);
            } else {
                url.searchParams.delete('tipo');
            }

            window.history.replaceState(window.history.state, '', url.toString());
        } catch {
            // Sin `history` no pasa nada: solo no queda en el enlace.
        }
    };

    /** La ubicación se pide al tocar el botón, nunca al cargar, y no se guarda. */
    const locate = () => {
        setError(null);

        if (isPreview) {
            setError('En la vista previa no se pide la ubicación.');

            return;
        }

        if (!('geolocation' in navigator)) {
            setError('Este navegador no comparte la ubicación. Busca por ciudad o barrio.');

            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setMe({ lat: position.coords.latitude, lng: position.coords.longitude });
                fetch(route('landing.nearby'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                    credentials: 'same-origin',
                    body: JSON.stringify({ lat: position.coords.latitude, lng: position.coords.longitude }),
                })
                    .then((response) => (response.ok ? response.json() : Promise.reject(new Error('fallo'))))
                    .then((body: { stores: LandingStore[] }) => {
                        setNearby(body.stores);
                        setCity('');
                    })
                    .catch(() => setError('No pudimos ordenar las tiendas por cercanía. Busca por ciudad o barrio.'))
                    .finally(() => setLocating(false));
            },
            () => {
                setError('Sin permiso de ubicación. Busca por ciudad o barrio.');
                setLocating(false);
            },
            { enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 },
        );
    };

    const remember = (store: LandingStore) => {
        try {
            window.localStorage.setItem(LAST_STORE_KEY, JSON.stringify({ id: store.id, name: store.name, company: store.company, url: store.url }));
        } catch {
            // Sin almacenamiento local no pasa nada: solo no se recuerda.
        }
    };

    const select = useCallback((id: number | null) => {
        setSelectedId(id);

        if (id === null) {
            setFocus(null);
        }
    }, []);

    // Elegir en el mapa deja la hoja del celular abajo, con la elegida a la vista.
    const pick = useCallback(
        (id: number | null) => {
            select(id);

            if (id !== null) {
                setSheet('closed');
            }
        },
        [select],
    );

    const needle = query.trim().toLowerCase();
    const shown = (nearby ?? stores).filter(
        (store) =>
            (city === '' || store.city === city) &&
            (categoryId === null || store.category?.id === categoryId) &&
            (!openNow || store.is_open) &&
            (!withDelivery || store.delivery?.enabled === true) &&
            (needle === '' || [store.name, store.company, store.address, store.city, store.category?.name].some((value) => value?.toLowerCase().includes(needle))),
    );
    // «Buscar en esta zona» acota la lista; el mapa sigue mostrando todo lo que pasa los filtros.
    const listStores = area
        ? shown.filter((store) => store.latitude !== null && store.longitude !== null && inBounds({ lat: store.latitude, lng: store.longitude }, area))
        : shown;
    const listKey = listStores.map((store) => store.id).join(',');

    // Cambiar un filtro vuelve a encuadrar el mapa: la zona buscada deja de tener sentido.
    useEffect(() => {
        setArea(null);
    }, [city, categoryId, needle, nearby, openNow, withDelivery]);

    // Con la ubicación, la lista llega ordenada: la primera queda elegida.
    useEffect(() => {
        if (nearby && options.layout === 'list_map' && options.preselect) {
            select(listStores[0]?.id ?? null);
        }
        // Solo cuando llegan las cercanas: después manda lo que la persona elija.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [nearby]);

    // En la vista previa no hay ubicación: se elige la primera para que se vea cómo queda.
    useEffect(() => {
        if (isPreview && options.layout === 'list_map' && options.preselect) {
            setSelectedId((current) => current ?? listStores[0]?.id ?? null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isPreview, options.layout, options.preselect]);

    // El número de cada sede en la lista, el mismo que lleva su pin.
    const labels = useMemo(
        () => (options.layout === 'list_map' && options.numbered ? Object.fromEntries(listStores.map((store, index) => [store.id, String(index + 1)])) : undefined),
        // `listKey` resume `listStores`: solo cambia si cambian las sedes o su orden.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [listKey, options.layout, options.numbered],
    );

    const seeOnMap = (store: LandingStore) => {
        select(store.id);
        setFocus({ id: store.id, seq: Date.now() });

        if (mapLayout === 'button') {
            setExpanded(true);

            return;
        }

        setMobileView('map');
        window.requestAnimationFrame(() => mapBox.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    };

    /** La fila de la lista elige la sede; tocarla otra vez la suelta. */
    const chooseRow = (store: LandingStore) => {
        if (selectedId === store.id) {
            select(null);

            return;
        }

        select(store.id);
        setSheet('closed');

        // Un mapa escondido (la pestaña «Lista» del celular) mide cero: ahí no se le pide volar.
        if (mapBox.current && mapBox.current.offsetWidth > 0) {
            setFocus({ id: store.id, seq: Date.now() });
        }
    };

    const searchField = (
        // `flex-1` solo en fila: en columna (celular) le ganaba a `h-11` y el campo quedaba aplastado.
        <label className="flex h-11 shrink-0 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 md:flex-1 md:shrink">
            <MagnifyingGlassIcon size={18} className="text-[var(--muted)]" />
            <input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Nombre, barrio o dirección"
                aria-label="Buscar tienda por nombre, barrio o dirección"
                className="h-full w-full bg-transparent text-[0.9375rem] outline-none"
            />
        </label>
    );

    // Lleno con el acento siempre: es la acción más útil, no puede verse apagada.
    const locateButton = (
        <button
            type="button"
            onClick={locate}
            disabled={locating}
            aria-busy={locating}
            className="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-[var(--accent)] px-4 text-[0.875rem] font-semibold text-[var(--accent-ink)] transition-opacity hover:opacity-90 disabled:cursor-wait"
        >
            <CrosshairIcon size={18} /> {locating ? 'Buscando…' : 'Usar mi ubicación'}
        </button>
    );

    const citySelect =
        cities.length > 1 ? (
            <select
                value={city}
                onChange={(event) => setCity(event.target.value)}
                className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 text-[0.9375rem]"
                aria-label="Ciudad"
            >
                <option value="">Todas las ciudades</option>
                {cities.map((item) => (
                    <option key={item} value={item}>
                        {item}
                    </option>
                ))}
            </select>
        ) : null;

    const quickFilters =
        options.filterOpenNow || options.filterDelivery ? (
            <div className="flex flex-wrap gap-2" role="group" aria-label="Filtros rápidos">
                {options.filterOpenNow && <ToggleChip label="Abiertas ahora" icon={ClockIcon} pressed={openNow} onToggle={() => setOpenNow((value) => !value)} />}
                {options.filterDelivery && <ToggleChip label="Con domicilio" icon={TruckIcon} pressed={withDelivery} onToggle={() => setWithDelivery((value) => !value)} />}
            </div>
        ) : null;

    const chips =
        categories.length > 1 ? (
            <div className="flex gap-2 overflow-x-auto pb-1 md:flex-wrap md:overflow-visible" role="radiogroup" aria-label="Tipo de comercio">
                <CategoryChip label="Todos" count={stores.length} selected={categoryId === null} onSelect={() => chooseCategory(null)} />
                {categories.map((category) => (
                    <CategoryChip
                        key={category.id}
                        label={category.name}
                        icon={category.icon}
                        count={category.count ?? 0}
                        selected={categoryId === category.id}
                        onSelect={() => chooseCategory(category)}
                    />
                ))}
            </div>
        ) : null;

    const areaNotice = area ? (
        <div className="flex flex-wrap items-center gap-2 text-[0.8125rem]">
            <span className="rounded-full bg-[var(--accent-soft)] px-3 py-1 font-semibold text-[var(--accent)]">
                En la zona del mapa: {listStores.length} {listStores.length === 1 ? 'tienda' : 'tiendas'}
            </span>
            <button type="button" onClick={() => setArea(null)} className="font-semibold text-[var(--accent)] hover:underline">
                Ver todas
            </button>
        </div>
    ) : null;

    const unlocated = stores.filter((store) => store.latitude === null || store.longitude === null).length;

    const mapProps: FinderMapProps | null = map
        ? {
              config: map,
              theme: mapTheme,
              stores: shown,
              user: me,
              cluster: data.map_cluster !== false,
              hoveredId,
              selectedId,
              onSelect: pick,
              focus,
              searchArea: data.map_search_area !== false,
              onSearchArea: setArea,
              onLocate: locate,
              locating,
              showHours: options.showHours,
              showDistance: options.showDistance,
              onBuy: remember,
              ctaLabel: options.ctaLabel,
              labels,
          }
        : null;
    const expand = expandable ? () => setExpanded(true) : undefined;

    const topMap =
        mapLayout === 'top' && mapProps ? (
            <div ref={mapBox} className="mb-5 scroll-mt-20">
                <StoreMap {...mapProps} onExpand={expand} className={cn('rounded-card border border-[var(--line)]', TOP_HEIGHTS[height])} />
            </div>
        ) : null;

    const promptCard = (
        <div className="mb-8 rounded-sheet border border-dashed border-[var(--line-2)] bg-[var(--surface)] p-6 text-center md:p-8">
            <span className="mx-auto grid size-12 place-items-center rounded-full bg-[var(--accent-soft)] text-[var(--accent)]">
                <CrosshairIcon size={24} />
            </span>
            <h3 className="mt-3 text-[1.25rem] font-semibold">¿Cuál tienda te queda más cerca?</h3>
            <p className="mx-auto mt-1 max-w-[30rem] text-[0.9375rem] text-[var(--muted)]">Comparte tu ubicación y te mostramos primero la más cercana. No la guardamos.</p>
            <div className="mx-auto mt-5 flex max-w-[34rem] flex-col gap-3 md:flex-row md:items-center">
                {locateButton}
                {searchField}
            </div>
        </div>
    );

    return (
        <Section id="tiendas" className={BAND_BG}>
            <StoreFinderHeader title={text(data.title)} body={text(data.text)} last={last} style={options.lastStoreStyle} />

            <div className="mb-5 flex flex-col gap-3 md:flex-row md:items-center">
                {locateButton}
                {searchField}
                {citySelect}
                {mapLayout === 'button' && (
                    <button
                        type="button"
                        onClick={() => setExpanded(true)}
                        className="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-control border border-[var(--line-2)] bg-[var(--surface)] px-4 text-[0.875rem] font-semibold"
                    >
                        <MapTrifoldIcon size={18} /> {options.layout === 'nearest_first' ? 'Ver todas en el mapa' : 'Ver mapa'}
                    </button>
                )}
            </div>

            {(quickFilters || chips) && (
                <div className="mb-5 flex flex-col gap-3">
                    {quickFilters}
                    {chips}
                </div>
            )}

            {error && <p className="mb-4 text-[0.875rem] text-[var(--warn)]">{error}</p>}

            {/* Vista previa honesta: una sede sin coordenadas está en la lista pero no en el mapa. */}
            {isPreview && mapLayout !== 'none' && unlocated > 0 && (
                <PreviewNote>
                    {unlocated === 1 ? 'Una sede no tiene ubicación y no sale' : `${unlocated} sedes no tienen ubicación y no salen`} en el mapa. Se ubican en Sedes, con el mapa del
                    formulario.
                </PreviewNote>
            )}

            {areaNotice && <div className="mb-4">{areaNotice}</div>}

            {options.layout === 'list_map' && (
                <>
                    {isPreview && options.preselect && <PreviewNote>En la tienda en línea se elige la más cercana al visitante.</PreviewNote>}
                    <ListMapLayout
                        stores={listStores}
                        options={options}
                        showCategory={showCategory}
                        onBuy={remember}
                        onHover={setHoveredId}
                        map={mapProps}
                        mapBox={mapBox}
                        height={height}
                        selectedId={selectedId}
                        onChoose={chooseRow}
                        sheet={sheet}
                        onSheet={setSheet}
                        mobileView={mobileView}
                        onMobileView={setMobileView}
                        onExpand={expand}
                    />
                </>
            )}

            {options.layout === 'nearest_first' && (
                <NearestFirstLayout
                    stores={listStores}
                    options={options}
                    onBuy={remember}
                    onHover={setHoveredId}
                    hasLocation={nearby !== null}
                    last={last}
                    isPreview={isPreview}
                    map={mapProps}
                    mapLayout={mapLayout}
                    topMap={topMap}
                    prompt={promptCard}
                    onSeeOnMap={seeOnMap}
                />
            )}

            {options.layout === 'by_company' && (
                <ByCompanyLayout stores={listStores} options={options} onBuy={remember} onHover={setHoveredId} hasLocation={nearby !== null} isPreview={isPreview} />
            )}

            {mapProps && expandable && (
                <StoreMapDialog
                    open={expanded}
                    onClose={() => setExpanded(false)}
                    title={text(data.title) || 'Tiendas'}
                    filters={
                        <>
                            <div className="grid gap-2">
                                {searchField}
                                {citySelect}
                            </div>
                            {quickFilters}
                            {chips}
                        </>
                    }
                    notice={areaNotice}
                    listStores={listStores}
                    emptyText={options.emptyText}
                    onHover={setHoveredId}
                    map={mapProps}
                />
            )}
        </Section>
    );
}

/** Título, texto y «Seguir comprando en…»: al lado del título (`chip`) o como franja (`banner`). */
function StoreFinderHeader({ title, body, last, style }: { title: string; body: string; last: LastStore | null; style: FinderOptions['lastStoreStyle'] }) {
    const chip =
        style === 'chip' && last ? (
            <a
                href={last.url}
                className="mt-4 inline-flex min-h-11 max-w-full items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-4 py-1.5 text-[0.875rem] hover:border-[var(--line-2)]"
            >
                <span className="min-w-0 truncate">
                    <span className="text-[var(--muted)]">Tu última tienda:</span> <strong>Sede {last.name}</strong>
                </span>
                <span className="shrink-0 font-semibold text-[var(--accent)]">· Seguir comprando</span>
                <ArrowRightIcon size={16} className="shrink-0 text-[var(--accent)]" />
            </a>
        ) : null;

    return (
        <>
            {(title || body || chip) && (
                <div className="mx-auto mb-8 max-w-[44rem] text-center">
                    {title && <h2 className="text-[1.75rem] leading-tight md:text-[2.25rem]">{title}</h2>}
                    {body && <p className="mt-3 text-[1rem] text-[var(--muted)]">{body}</p>}
                    {chip}
                </div>
            )}

            {style === 'banner' && last && (
                <a
                    href={last.url}
                    className="mx-auto mb-6 flex max-w-[40rem] items-center justify-between gap-3 rounded-card border border-[var(--accent)] bg-[var(--surface)] px-4 py-3 text-[0.9375rem]"
                >
                    <span>
                        Seguir comprando en <strong>{last.name}</strong> — {last.company}
                    </span>
                    <ArrowRightIcon size={18} className="shrink-0 text-[var(--accent)]" />
                </a>
            )}
        </>
    );
}

/** Un filtro que se prende y se apaga: «Abiertas ahora», «Con domicilio». */
function ToggleChip({ label, icon: ChipIcon, pressed, onToggle }: { label: string; icon: Icon; pressed: boolean; onToggle: () => void }) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            onClick={onToggle}
            className={cn(
                'inline-flex h-11 shrink-0 items-center gap-1.5 rounded-full border px-4 text-[0.875rem] font-medium transition-colors',
                pressed ? 'border-[var(--text)] bg-[var(--text)] text-[var(--bg)]' : 'border-[var(--line)] bg-[var(--surface)] text-[var(--text)] hover:border-[var(--line-2)]',
            )}
        >
            {pressed ? <CheckIcon size={16} weight="bold" /> : <ChipIcon size={16} />} {label}
        </button>
    );
}

function PreviewNote({ children }: { children: ReactNode }) {
    return <p className="mb-4 rounded-control border border-dashed border-[var(--warn)] px-3 py-2 text-[0.8125rem] text-[var(--warn)]">{children}</p>;
}

function EmptyList({ text: message }: { text: string }) {
    return <p className="rounded-card border border-dashed border-[var(--line-2)] bg-[var(--surface)] px-4 py-10 text-center text-[var(--muted)]">{message}</p>;
}

/** El llamado principal: lleno, con el color de la sede. Uno solo a la vista. */
function BuyLink({ store, label, onBuy, className }: { store: LandingStore; label: string; onBuy: (store: LandingStore) => void; className?: string }) {
    return (
        <a
            href={store.url}
            onClick={() => onBuy(store)}
            className={cn('inline-flex h-11 items-center justify-center gap-2 rounded-control px-4 text-[0.9375rem] font-semibold transition-opacity hover:opacity-90', className)}
            style={{ background: store.accent, color: store.accent_ink }}
        >
            <ShoppingCartIcon size={18} /> {label}
        </a>
    );
}

function DirectionsLink({ url }: { url: string }) {
    return (
        <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex h-11 items-center gap-1.5 rounded-control border border-[var(--line-2)] bg-[var(--surface)] px-4 text-[0.875rem] font-semibold hover:bg-[var(--surface-2)]"
        >
            <NavigationArrowIcon size={16} /> Cómo llegar
        </a>
    );
}

interface LayoutProps {
    /** La lista: con todos los filtros, también el de la zona del mapa. */
    stores: LandingStore[];
    options: FinderOptions;
    onBuy: (store: LandingStore) => void;
    onHover: (id: number | null) => void;
}

/**
 * A · Lista compacta + mapa. Las filas eligen la sede; la elegida lleva el botón de compra y
 * «Cómo llegar». En el celular, selector «Lista | Mapa» o el mapa con la lista en una hoja.
 */
function ListMapLayout({
    stores,
    options,
    showCategory,
    onBuy,
    onHover,
    map,
    mapBox,
    height,
    selectedId,
    onChoose,
    sheet,
    onSheet,
    mobileView,
    onMobileView,
    onExpand,
}: LayoutProps & {
    showCategory: boolean;
    map: FinderMapProps | null;
    mapBox: RefObject<HTMLDivElement | null>;
    height: (typeof MAP_HEIGHTS)[number];
    selectedId: number | null;
    onChoose: (store: LandingStore) => void;
    sheet: SheetState;
    onSheet: (sheet: SheetState) => void;
    mobileView: 'list' | 'map';
    onMobileView: (view: 'list' | 'map') => void;
    onExpand?: () => void;
}) {
    const row = (store: LandingStore, index: number) => (
        <StoreRow
            key={store.id}
            store={store}
            number={options.numbered ? index + 1 : null}
            selected={selectedId === store.id}
            options={options}
            showCategory={showCategory}
            onChoose={onChoose}
            onBuy={onBuy}
            onHover={onHover}
        />
    );
    const rows = stores.length === 0 ? <EmptyList text={options.emptyText} /> : <ol className="flex flex-col gap-2">{stores.map(row)}</ol>;

    if (!map) {
        return <div className="mx-auto max-w-[48rem]">{rows}</div>;
    }

    if (options.mobileMode === 'tabs') {
        return (
            <>
                <div className="mb-4 flex rounded-full border border-[var(--line)] bg-[var(--surface)] p-1 lg:hidden" role="tablist" aria-label="Cómo ver las tiendas">
                    {(['list', 'map'] as const).map((value) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            aria-selected={mobileView === value}
                            onClick={() => onMobileView(value)}
                            className={cn(
                                'h-11 flex-1 rounded-full text-[0.875rem] font-semibold transition-colors',
                                mobileView === value ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'text-[var(--muted)]',
                            )}
                        >
                            {value === 'list' ? `Lista (${stores.length})` : 'Mapa'}
                        </button>
                    ))}
                </div>
                <div className={cn('grid gap-5 lg:items-start', LIST_WIDTHS[options.listWidth])}>
                    <div className={cn(mobileView === 'map' && 'hidden lg:block')}>{rows}</div>
                    <div ref={mapBox} className={cn('scroll-mt-20 lg:sticky lg:top-20', mobileView === 'list' && 'hidden lg:block')}>
                        {/* En el computador la fila elegida ya trae el botón: la ficha del mapa sería un segundo llamado. */}
                        <StoreMap
                            {...map}
                            onExpand={onExpand}
                            cardClassName="lg:hidden"
                            className={cn('rounded-card border border-[var(--line)] lg:max-h-[calc(100vh-6rem)]', SIDE_HEIGHTS[height])}
                        />
                    </div>
                </div>
            </>
        );
    }

    const selectedIndex = stores.findIndex((store) => store.id === selectedId);
    const handleLabel = sheet === 'full' ? 'Ver el mapa' : 'Ver más tiendas';

    // Un solo árbol para las dos anchuras (un solo mapa): en el celular, el mapa arriba y la lista
    // en una hoja de tres alturas; en el computador, la lista a la izquierda y el mapa pegado a la
    // derecha. La hoja empuja al mapa en vez de taparlo: el crédito del proveedor sigue a la vista.
    // Por eso no lleva `map-overlay`: nunca queda encima del mapa, y con ese z-index la columna de
    // la lista pasaría por encima del encabezado fijo de la página.
    return (
        <div
            className={cn(
                'relative flex h-[36rem] flex-col overflow-hidden rounded-card border border-[var(--line)] bg-[var(--surface)] lg:grid lg:h-auto lg:items-start lg:gap-5 lg:overflow-visible lg:rounded-none lg:border-0 lg:bg-transparent',
                LIST_WIDTHS[options.listWidth],
            )}
        >
            <div ref={mapBox} className={cn('relative order-1 min-h-0 flex-1 scroll-mt-20 lg:sticky lg:top-20 lg:order-2 lg:max-h-[calc(100vh-6rem)] lg:flex-none', SIDE_HEIGHTS_LG[height])}>
                <StoreMap {...map} onExpand={onExpand} card={false} className="absolute inset-0 lg:rounded-card lg:border lg:border-[var(--line)]" />
            </div>

            <div
                role="dialog"
                aria-modal="false"
                aria-label="Lista de tiendas"
                className={cn(
                    'order-2 flex shrink-0 flex-col border-t border-[var(--line)] bg-[var(--surface)] lg:order-1 lg:border-0 lg:bg-transparent',
                    SHEET_HEIGHTS[sheet],
                )}
            >
                <button
                    type="button"
                    onClick={() => onSheet(sheet === 'closed' ? 'half' : sheet === 'half' ? 'full' : 'closed')}
                    aria-label={handleLabel}
                    aria-expanded={sheet !== 'closed'}
                    className="flex h-12 shrink-0 flex-col items-center justify-center gap-1 px-4 lg:hidden"
                >
                    <span className="h-1 w-10 rounded-full bg-[var(--line-2)]" aria-hidden="true" />
                    <span className="inline-flex items-center gap-1.5 text-[0.8125rem] font-semibold" aria-hidden="true">
                        {sheet === 'full' ? <CaretDownIcon size={14} /> : <CaretUpIcon size={14} />}
                        {handleLabel}
                        {sheet !== 'full' && <span className="font-normal text-[var(--muted)]">({stores.length})</span>}
                    </span>
                </button>

                {/* Cerrada: solo la elegida, con su botón. */}
                {sheet === 'closed' && selectedIndex >= 0 && <ol className="px-3 pb-3 lg:hidden">{row(stores[selectedIndex], selectedIndex)}</ol>}

                <div className={cn('min-h-0 flex-1 overflow-y-auto px-3 pb-3 lg:overflow-visible lg:p-0', sheet === 'closed' && 'hidden lg:block')}>{rows}</div>
            </div>
        </div>
    );
}

/** Una sede en la lista de A: toda la fila elige; el enlace de compra va aparte, nunca dentro del botón. */
function StoreRow({
    store,
    number,
    selected,
    options,
    showCategory,
    onChoose,
    onBuy,
    onHover,
}: {
    store: LandingStore;
    number: number | null;
    selected: boolean;
    options: FinderOptions;
    showCategory: boolean;
    onChoose: (store: LandingStore) => void;
    onBuy: (store: LandingStore) => void;
    onHover: (id: number | null) => void;
}) {
    const distance = options.showDistance ? distanceOf(store) : null;
    const delivery = options.showDelivery ? deliveryLabel(store.delivery) : null;

    return (
        <li
            onMouseEnter={() => onHover(store.id)}
            onMouseLeave={() => onHover(null)}
            className={cn('rounded-card border bg-[var(--surface)] transition-colors', selected ? 'border-[var(--text)]' : 'border-[var(--line)] hover:border-[var(--line-2)]')}
        >
            <button type="button" aria-pressed={selected} onClick={() => onChoose(store)} className="flex w-full items-start gap-3 rounded-card p-3 text-left">
                {number !== null ? (
                    <span className="grid size-9 shrink-0 place-items-center rounded-full text-[0.875rem] font-bold tabular" style={{ background: store.accent, color: store.accent_ink }}>
                        {number}
                    </span>
                ) : (
                    <StoreLogo store={store} className="size-9 text-[0.9375rem]" />
                )}
                <span className="min-w-0 flex-1">
                    <span className="flex items-baseline gap-2">
                        <span className="min-w-0 flex-1 truncate text-[0.9375rem] font-semibold">Sede {store.name}</span>
                        {distance !== null && <span className="shrink-0 text-[0.875rem] font-bold tabular">{distanceLabel(distance)}</span>}
                    </span>
                    <span className="block truncate text-[0.8125rem] text-[var(--muted)]">{[store.company, store.address || store.city].filter(Boolean).join(' · ')}</span>
                    <DotList
                        className="mt-0.5 block text-[0.8125rem]"
                        items={[
                            options.showHours && hasHours(store) ? <StoreStatus store={store} /> : null,
                            delivery ? <span className="text-[var(--muted)]">{delivery}</span> : null,
                            options.showNextSlot && store.delivery?.next_slot ? <span className="text-[var(--muted)]">Entrega {store.delivery.next_slot}</span> : null,
                            showCategory && store.category ? <span className="text-[var(--muted)]">{store.category.name}</span> : null,
                        ]}
                    />
                </span>
            </button>

            {selected ? (
                <div className="flex flex-wrap gap-2 px-3 pb-3 sm:pl-[3.75rem]">
                    <BuyLink store={store} label={options.ctaLabel} onBuy={onBuy} />
                    {store.directions_url && <DirectionsLink url={store.directions_url} />}
                </div>
            ) : options.ctaMode === 'all' ? (
                <div className="px-3 pb-1 sm:pl-[3.75rem]">
                    <a href={store.url} onClick={() => onBuy(store)} className="inline-flex min-h-11 items-center gap-1.5 text-[0.875rem] font-semibold text-[var(--text)] hover:underline">
                        {options.ctaLabel} <ArrowRightIcon size={14} />
                    </a>
                </div>
            ) : null}
        </li>
    );
}

/**
 * B · La más cercana primero: una tarjeta ancha con la que más conviene y, debajo, el resto en
 * tarjetas compactas. Sin ubicación no se inventa una «más cercana» (`hero_fallback`).
 */
function NearestFirstLayout({
    stores,
    options,
    onBuy,
    onHover,
    hasLocation,
    last,
    isPreview,
    map,
    mapLayout,
    topMap,
    prompt,
    onSeeOnMap,
}: LayoutProps & {
    hasLocation: boolean;
    last: LastStore | null;
    isPreview: boolean;
    map: FinderMapProps | null;
    mapLayout: (typeof MAP_LAYOUTS)[number];
    topMap: ReactNode;
    prompt: ReactNode;
    onSeeOnMap: (store: LandingStore) => void;
}) {
    const [showAll, setShowAll] = useState(false);

    if (stores.length === 0) {
        return <EmptyList text={options.emptyText} />;
    }

    let hero: LandingStore | null = null;
    let badge = options.heroBadge;

    if (isPreview) {
        // Vista previa honesta: no hay ubicación, así que la destacada es un ejemplo y lo dice.
        hero = stores[0];
        badge = 'Ejemplo: así se verá la más cercana';
    } else if (hasLocation) {
        hero = stores[0];
    } else if (options.heroFallback === 'last_store' && last) {
        hero = stores.find((store) => store.id === last.id) ?? null;
        badge = 'Tu última tienda';
    }

    const showPrompt = !hasLocation && options.heroFallback === 'prompt';
    const heroId = hero?.id ?? null;
    const others = stores.filter((store) => store.id !== heroId);
    const limit = options.othersLimit === 'all' ? others.length : Number(options.othersLimit);
    const visible = showAll ? others : others.slice(0, limit);

    return (
        <>
            {isPreview && <PreviewNote>En la vista previa no se pide la ubicación: la tienda destacada es un ejemplo con la primera de la lista.</PreviewNote>}
            {showPrompt && prompt}
            {hero && <HeroStore store={hero} badge={badge} options={options} map={map} mapLayout={mapLayout} onBuy={onBuy} onSeeOnMap={onSeeOnMap} />}
            {topMap}

            {others.length > 0 && (
                <div>
                    {hero && options.othersTitle && <h3 className="mb-4 text-[1.125rem] font-semibold">{options.othersTitle}</h3>}
                    <ul className={cn('grid gap-3 sm:grid-cols-2', OTHERS_COLUMNS[options.othersColumns])}>
                        {visible.map((store) => (
                            <li key={store.id}>
                                <CompactStore store={store} options={options} onBuy={onBuy} onHover={onHover} />
                            </li>
                        ))}
                    </ul>
                    {visible.length < others.length && (
                        <div className="mt-5 flex justify-center">
                            <button
                                type="button"
                                onClick={() => setShowAll(true)}
                                className="inline-flex h-11 items-center rounded-control border border-[var(--line-2)] bg-[var(--surface)] px-5 text-[0.875rem] font-semibold hover:bg-[var(--surface-2)]"
                            >
                                Ver todas ({others.length})
                            </button>
                        </div>
                    )}
                </div>
            )}
        </>
    );
}

/** El domicilio de la destacada: «Desde $ 4.000 · gratis desde $ 80.000». */
function heroDelivery(delivery: LandingStoreDelivery): string {
    if (!delivery.enabled) {
        return 'No tiene';
    }

    const from = delivery.fee_from === 0 ? 'Gratis' : delivery.fee_from !== null ? `Desde ${money(delivery.fee_from)}` : 'Sí';

    return delivery.free_from !== null && delivery.fee_from !== 0 ? `${from} · gratis desde ${money(delivery.free_from)}` : from;
}

function HeroStore({
    store,
    badge,
    options,
    map,
    mapLayout,
    onBuy,
    onSeeOnMap,
}: {
    store: LandingStore;
    badge: string;
    options: FinderOptions;
    map: FinderMapProps | null;
    mapLayout: (typeof MAP_LAYOUTS)[number];
    onBuy: (store: LandingStore) => void;
    onSeeOnMap: (store: LandingStore) => void;
}) {
    const distance = options.showDistance ? distanceOf(store) : null;
    const located = store.latitude !== null && store.longitude !== null;
    // Cada dato solo si existe.
    const facts = [
        distance !== null ? { label: 'Distancia', value: distanceLabel(distance) } : null,
        options.showDelivery && store.delivery ? { label: 'Domicilio', value: heroDelivery(store.delivery) } : null,
        options.showNextSlot && store.delivery?.next_slot ? { label: 'Próxima franja', value: store.delivery.next_slot } : null,
    ].filter((fact): fact is { label: string; value: string } => fact !== null);

    return (
        <article className="mb-8 grid overflow-hidden rounded-sheet border border-[var(--line)] bg-[var(--surface)] md:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
            <div className="relative h-52 bg-[var(--surface-2)] md:h-auto md:min-h-[18rem]">
                {map && options.heroMap && located ? (
                    <StoreMap
                        {...map}
                        stores={[store]}
                        cluster={false}
                        hoveredId={null}
                        selectedId={store.id}
                        onSelect={noop}
                        focus={null}
                        searchArea={false}
                        labels={undefined}
                        controls={false}
                        card={false}
                        className="absolute inset-0"
                    />
                ) : (
                    <div className="grid size-full place-items-center p-6">
                        <StoreLogo store={store} className="size-24 rounded-card text-[2.5rem]" />
                    </div>
                )}
            </div>

            <div className="flex flex-col gap-3 p-5 md:p-7">
                {badge && <span className="w-fit rounded-full bg-[var(--accent-soft)] px-3 py-1 text-[0.75rem] font-semibold text-[var(--accent)]">{badge}</span>}
                <div>
                    <p className="text-[0.875rem] text-[var(--muted)]">{store.company}</p>
                    <h3 className="text-[1.5rem] font-semibold leading-tight">Sede {store.name}</h3>
                    <p className="mt-1 text-[0.9375rem] text-[var(--muted)]">{[store.address, store.city].filter(Boolean).join(', ') || 'Sin dirección publicada'}</p>
                    {options.showHours && hasHours(store) && <StoreStatus store={store} className="mt-1 block text-[0.875rem]" />}
                </div>

                {facts.length > 0 && (
                    <dl className="grid gap-2 sm:grid-cols-3">
                        {facts.map((fact) => (
                            <div key={fact.label} className="rounded-control bg-[var(--surface-2)] px-3 py-2">
                                <dt className="text-[0.75rem] text-[var(--muted)]">{fact.label}</dt>
                                <dd className="text-[0.9375rem] font-bold">{fact.value}</dd>
                            </div>
                        ))}
                    </dl>
                )}

                <div className="mt-auto flex flex-wrap gap-2 pt-2">
                    <BuyLink store={store} label={options.ctaLabel} onBuy={onBuy} />
                    {mapLayout !== 'none' && located && (
                        <button
                            type="button"
                            onClick={() => onSeeOnMap(store)}
                            className="inline-flex h-11 items-center gap-1.5 rounded-control border border-[var(--line-2)] px-4 text-[0.875rem] font-semibold hover:bg-[var(--surface-2)]"
                        >
                            <MapPinIcon size={16} /> Ver en el mapa
                        </button>
                    )}
                </div>
            </div>
        </article>
    );
}

/** Una sede en corto: toda la tarjeta es el enlace de compra. */
function CompactStore({ store, options, onBuy, onHover }: { store: LandingStore; options: FinderOptions; onBuy: (store: LandingStore) => void; onHover: (id: number | null) => void }) {
    const distance = options.showDistance ? distanceOf(store) : null;

    return (
        <a
            href={store.url}
            onClick={() => onBuy(store)}
            onMouseEnter={() => onHover(store.id)}
            onMouseLeave={() => onHover(null)}
            className="flex min-h-[4.5rem] items-center gap-3 rounded-card border border-[var(--line)] bg-[var(--surface)] p-3 transition-colors hover:border-[var(--line-2)]"
        >
            <StoreLogo store={store} className="size-11 text-[1.0625rem]" />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[0.9375rem] font-semibold">Sede {store.name}</span>
                <span className="block truncate text-[0.8125rem] text-[var(--muted)]">{store.company}</span>
                {options.showHours && hasHours(store) && <StoreStatus store={store} className="block truncate text-[0.8125rem]" />}
            </span>
            {distance !== null && <span className="shrink-0 text-[0.875rem] font-bold tabular">{distanceLabel(distance)}</span>}
            <CaretRightIcon size={16} className="shrink-0 text-[var(--muted)]" aria-hidden="true" />
        </a>
    );
}

/**
 * C · Agrupado por comercio: una tarjeta por empresa con sus sedes. Se agrupa aquí, sobre la
 * lista ya filtrada: una empresa sin sedes después de los filtros no sale.
 */
function ByCompanyLayout({ stores, options, onBuy, onHover, hasLocation, isPreview }: LayoutProps & { hasLocation: boolean; isPreview: boolean }) {
    const [opened, setOpened] = useState<number[]>([]);

    if (stores.length === 0) {
        return <EmptyList text={options.emptyText} />;
    }

    const near = (store: LandingStore) => distanceOf(store) ?? Number.POSITIVE_INFINITY;
    const byName = (a: LandingStore, b: LandingStore) => a.name.localeCompare(b.name, 'es');
    const grouped = new Map<number, LandingStore[]>();

    stores.forEach((store) => grouped.set(store.company_id, [...(grouped.get(store.company_id) ?? []), store]));

    const groups = Array.from(grouped, ([id, items]) => ({
        id,
        // Sus sedes por distancia; sin ubicación, por nombre.
        stores: [...items].sort(hasLocation ? (a, b) => near(a) - near(b) || byName(a, b) : byName),
    }));
    // Sin ubicación no hay distancia que ordenar: «el más cercano» cae a nombre.
    const order = options.companyOrder === 'nearest' && !hasLocation ? 'name' : options.companyOrder;
    const companyName = (group: { stores: LandingStore[] }) => group.stores[0].company;

    groups.sort((a, b) => {
        const alphabetical = companyName(a).localeCompare(companyName(b), 'es');

        if (order === 'nearest') {
            return near(a.stores[0]) - near(b.stores[0]) || alphabetical;
        }

        if (order === 'stores_count') {
            return b.stores.length - a.stores.length || alphabetical;
        }

        return alphabetical;
    });

    const limit = options.storesPerCompany === 'all' ? Number.POSITIVE_INFINITY : Number(options.storesPerCompany);

    return (
        <>
            {isPreview && options.companyOrder === 'nearest' && (
                <PreviewNote>En la tienda en línea los comercios se ordenan por cercanía al visitante; aquí, sin su ubicación, van por nombre.</PreviewNote>
            )}
            <div className="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {groups.map((group) => (
                    <CompanyCard
                        key={group.id}
                        stores={group.stores}
                        options={options}
                        limit={opened.includes(group.id) ? Number.POSITIVE_INFINITY : limit}
                        onOpen={() => setOpened((current) => [...current, group.id])}
                        onBuy={onBuy}
                        onHover={onHover}
                    />
                ))}
            </div>
        </>
    );
}

function CompanyCard({
    stores,
    options,
    limit,
    onOpen,
    onBuy,
    onHover,
}: {
    stores: LandingStore[];
    options: FinderOptions;
    limit: number;
    onOpen: () => void;
    onBuy: (store: LandingStore) => void;
    onHover: (id: number | null) => void;
}) {
    const head = stores[0];
    const accent = options.companyHeader === 'accent';
    const visible = stores.slice(0, limit);
    const hidden = stores.length - visible.length;

    return (
        <article className="flex flex-col overflow-hidden rounded-card border border-[var(--line)] bg-[var(--surface)]">
            <header
                className={cn('flex items-center gap-3 p-4', !accent && 'border-b border-t-4 border-b-[var(--line)]')}
                style={accent ? { background: head.accent, color: head.accent_ink } : { borderTopColor: head.accent }}
            >
                <StoreLogo store={head} inverted={accent} className="size-11 text-[1.125rem]" />
                <div className="min-w-0">
                    <h3 className="truncate text-[1.0625rem] font-semibold">{head.company}</h3>
                    <p className={cn('truncate text-[0.8125rem]', accent ? 'opacity-90' : 'text-[var(--muted)]')}>
                        {[head.category?.name, `${stores.length} ${stores.length === 1 ? 'sede' : 'sedes'}`].filter(Boolean).join(' · ')}
                    </p>
                </div>
            </header>

            <ul className="flex flex-col divide-y divide-[var(--line)]">
                {visible.map((store) => {
                    const distance = options.showDistance ? distanceOf(store) : null;

                    return (
                        <li key={store.id}>
                            <a
                                href={store.url}
                                onClick={() => onBuy(store)}
                                onMouseEnter={() => onHover(store.id)}
                                onMouseLeave={() => onHover(null)}
                                className="flex min-h-11 items-center gap-3 px-4 py-2.5 transition-colors hover:bg-[var(--surface-2)]"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[0.9375rem] font-semibold">Sede {store.name}</span>
                                    <DotList
                                        className="block truncate text-[0.8125rem]"
                                        items={[
                                            options.showHours && hasHours(store) ? <StoreStatus store={store} /> : null,
                                            store.address ? <span className="text-[var(--muted)]">{store.address}</span> : null,
                                        ]}
                                    />
                                </span>
                                {distance !== null && <span className="shrink-0 text-[0.875rem] font-bold tabular">{distanceLabel(distance)}</span>}
                                <CaretRightIcon size={16} className="shrink-0 text-[var(--muted)]" aria-hidden="true" />
                            </a>
                        </li>
                    );
                })}
            </ul>

            {hidden > 0 && (
                <button type="button" onClick={onOpen} className="flex min-h-11 items-center border-t border-[var(--line)] px-4 text-left text-[0.875rem] font-semibold text-[var(--accent)] hover:underline">
                    + {hidden} {hidden === 1 ? 'sede más' : 'sedes más'}
                </button>
            )}

            {options.companyCta && (
                <div className="mt-auto border-t border-[var(--line)] p-4">
                    <BuyLink store={head} label={`Comprar en Sede ${head.name}`} onBuy={onBuy} className="w-full" />
                </div>
            )}
        </article>
    );
}

function StepsBlock({ data }: { data: Record<string, unknown> }) {
    const steps = list<{ icon: string; title: string; text: string }>(data.steps);

    return (
        <Section id="como-funciona">
            <SectionTitle title={text(data.title)} />
            <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {steps.map((step, index) => {
                    const StepIcon = ICONS[step.icon] ?? SparkleIcon;

                    return (
                        <li key={index} className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
                            <div className="mb-4 flex items-center justify-between">
                                <span className="grid size-11 place-items-center rounded-control bg-[var(--accent-soft)] text-[var(--accent)]">
                                    <StepIcon size={22} />
                                </span>
                                <span className="font-mono text-[1.5rem] font-semibold text-[var(--line-2)]">{index + 1}</span>
                            </div>
                            <h3 className="text-[1.0625rem] font-semibold">{step.title}</h3>
                            <p className="mt-1 text-[0.9375rem] text-[var(--muted)]">{step.text}</p>
                        </li>
                    );
                })}
            </ol>
        </Section>
    );
}

/**
 * Las empresas presentes, agrupadas por su clasificación (alimentos, textiles, belleza…) en el
 * orden del catálogo. Las que no tienen van al final, en «Otros comercios».
 */
function CompaniesBlock({ data, companies, categories }: { data: Record<string, unknown>; companies: Record<string, unknown>[]; categories: LandingCategory[] }) {
    if (companies.length === 0) {
        return null;
    }

    const categoryOf = (company: Record<string, unknown>) => (company.category as LandingCategory | null | undefined)?.id ?? null;
    const groups = [
        ...categories.map((category) => ({ key: String(category.id), category, items: companies.filter((company) => categoryOf(company) === category.id) })),
        { key: 'otros', category: null, items: companies.filter((company) => categoryOf(company) === null || !categories.some((category) => category.id === categoryOf(company))) },
    ].filter((group) => group.items.length > 0);

    return (
        <Section className={BAND_BG}>
            <SectionTitle title={text(data.title)} body={text(data.text)} />
            {categories.length === 0 ? (
                <CompanyTiles companies={companies} />
            ) : (
                <div className="flex flex-col gap-8">
                    {groups.map((group) => (
                        <div key={group.key}>
                            <h3 className="mb-3 flex items-center justify-center gap-2 text-[1.0625rem] font-semibold">
                                {group.category ? <CategoryTag category={group.category} big /> : <span className="text-[var(--muted)]">Otros comercios</span>}
                                <span className="font-mono text-[0.8125rem] font-normal text-[var(--muted)] tabular">{group.items.length}</span>
                            </h3>
                            <CompanyTiles companies={group.items} />
                        </div>
                    ))}
                </div>
            )}
        </Section>
    );
}

function CompanyTiles({ companies }: { companies: Record<string, unknown>[] }) {
    return (
        <div className="flex flex-wrap justify-center gap-3">
            {companies.map((company) => (
                <a
                    key={String(company.id)}
                    href={text(company.url)}
                    className="flex min-w-[14rem] items-center gap-3 rounded-card border border-[var(--line)] bg-[var(--surface)] px-4 py-3 hover:border-[var(--line-2)]"
                >
                    {text(company.logo_url) ? (
                        <img src={text(company.logo_url)} alt={text(company.name)} className="size-10 rounded-control object-contain" />
                    ) : (
                        <span className="grid size-10 place-items-center rounded-control font-bold" style={{ background: text(company.accent), color: text(company.accent_ink) }}>
                            {text(company.name).slice(0, 1)}
                        </span>
                    )}
                    <span className="min-w-0">
                        <span className="block truncate font-semibold">{text(company.name)}</span>
                        <span className="block text-[0.8125rem] text-[var(--muted)]">
                            {[text(company.city), `${Number(company.stores_count ?? 0)} ${Number(company.stores_count) === 1 ? 'sede' : 'sedes'}`].filter(Boolean).join(' · ')}
                        </span>
                    </span>
                </a>
            ))}
        </div>
    );
}

/** La clasificación con su ícono: «Textiles y confección». */
function CategoryTag({ category, big = false, className }: { category: LandingCategory; big?: boolean; className?: string }) {
    const CategoryIcon = landingIcon(category.icon);

    return (
        <span className={cn('inline-flex items-center gap-1.5', big ? 'text-[1.0625rem] font-semibold' : 'text-[0.8125rem] font-medium text-[var(--muted)]', className)}>
            <span className={cn('grid shrink-0 place-items-center rounded-full bg-[var(--accent-soft)] text-[var(--accent)]', big ? 'size-8' : 'size-6')}>
                <CategoryIcon size={big ? 18 : 14} />
            </span>
            {category.name}
        </span>
    );
}

function CategoryChip({ label, icon, count, selected, onSelect }: { label: string; icon?: string; count: number; selected: boolean; onSelect: () => void }) {
    const ChipIcon = icon ? landingIcon(icon) : null;

    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onSelect}
            className={cn(
                'inline-flex h-11 shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-[0.875rem] font-medium transition-colors',
                selected ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]' : 'border-[var(--line)] bg-[var(--surface)] text-[var(--text)] hover:border-[var(--line-2)]',
            )}
        >
            {ChipIcon && <ChipIcon size={16} />}
            {label}
            <span className={cn('font-mono text-[0.75rem] tabular', selected ? 'opacity-80' : 'text-[var(--muted)]')}>{count}</span>
        </button>
    );
}

function BenefitsBlock({ data }: { data: Record<string, unknown> }) {
    const items = list<{ icon: string; title: string; text: string }>(data.items);

    return (
        <Section>
            <SectionTitle title={text(data.title)} body={text(data.text)} />
            <div className="grid gap-4 md:grid-cols-3">
                {items.map((item, index) => {
                    const ItemIcon = ICONS[item.icon] ?? SparkleIcon;

                    return (
                        <div key={index} className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-6">
                            <ItemIcon size={28} className="text-[var(--accent)]" />
                            <h3 className="mt-4 text-[1.125rem] font-semibold">{item.title}</h3>
                            <p className="mt-1.5 text-[0.9375rem] text-[var(--muted)]">{item.text}</p>
                        </div>
                    );
                })}
            </div>
        </Section>
    );
}

function PlansBlock({ data, plans, cycles, comparison, signupUrl }: { data: Record<string, unknown>; plans: LandingPlan[]; cycles: LandingCycle[]; comparison: LandingComparisonRow[]; signupUrl: string }) {
    const [cycleId, setCycleId] = useState<number | null>(cycles[0]?.id ?? null);
    const cycle = cycles.find((item) => item.id === cycleId) ?? cycles[0];

    if (plans.length === 0 || !cycle) {
        return null;
    }

    return (
        <Section id="planes" className={BAND_BG}>
            <SectionTitle title={text(data.title)} body={text(data.text)} />

            {cycles.length > 1 && (
                <div className="mx-auto mb-8 flex w-fit flex-wrap justify-center gap-1 rounded-control border border-[var(--line)] bg-[var(--surface)] p-1">
                    {cycles.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => setCycleId(item.id)}
                            className={cn(
                                'rounded-[0.5rem] px-3.5 py-2 text-[0.875rem] font-semibold transition-colors',
                                item.id === cycle.id ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'text-[var(--muted)] hover:text-[var(--text)]',
                            )}
                        >
                            {item.name}
                            {item.discount_percent > 0 && <span className="ml-1 text-[0.75rem] opacity-80">−{item.discount_percent} %</span>}
                        </button>
                    ))}
                </div>
            )}

            <div className={cn('grid gap-4', plans.length >= 3 ? 'lg:grid-cols-3' : 'md:grid-cols-2', 'md:grid-cols-2')}>
                {plans.map((plan) => {
                    const price = plan.prices[String(cycle.id)] ?? 0;
                    const monthly = plan.monthly[String(cycle.id)] ?? 0;

                    return (
                        <div
                            key={plan.id}
                            className={cn(
                                'relative flex flex-col rounded-card border bg-[var(--surface)] p-6',
                                plan.is_featured ? 'border-[var(--accent)] shadow-lg' : 'border-[var(--line)]',
                            )}
                        >
                            {plan.is_featured && text(data.featured_label) && (
                                <span className="absolute -top-3 left-6 rounded-full bg-[var(--accent)] px-3 py-1 text-[0.75rem] font-semibold text-[var(--accent-ink)]">
                                    {text(data.featured_label)}
                                </span>
                            )}
                            <h3 className="text-[1.25rem] font-semibold">{plan.name}</h3>
                            {plan.description && <p className="mt-1 text-[0.875rem] text-[var(--muted)]">{plan.description}</p>}
                            <p className="mt-5 font-mono text-[2rem] font-semibold tabular">{money(price)}</p>
                            <p className="text-[0.8125rem] text-[var(--muted)]">
                                {cycle.months === 1 ? 'al mes' : `cada ${cycle.months} meses · ${money(monthly)} al mes`}
                            </p>
                            {plan.trial_days > 0 && (
                                <p className="mt-2 w-fit rounded-full bg-[var(--accent-soft)] px-2.5 py-0.5 text-[0.75rem] font-semibold text-[var(--accent)]">
                                    {plan.trial_days} días gratis
                                </p>
                            )}
                            <ul className="mt-5 flex flex-1 flex-col gap-2 text-[0.9375rem]">
                                <PlanLine>{plan.limits.stores === null ? 'Sedes ilimitadas' : `${plan.limits.stores} ${plan.limits.stores === 1 ? 'sede' : 'sedes'}`}</PlanLine>
                                <PlanLine>{plan.limits.users === null ? 'Usuarios ilimitados' : `${plan.limits.users} usuarios`}</PlanLine>
                                <PlanLine>{plan.limits.registers === null ? 'Cajas ilimitadas' : `${plan.limits.registers} ${plan.limits.registers === 1 ? 'caja' : 'cajas'}`}</PlanLine>
                                {plan.highlights.map((highlight) => (
                                    <PlanLine key={highlight}>{highlight}</PlanLine>
                                ))}
                            </ul>
                            <a
                                href={`${signupUrl}?plan=${encodeURIComponent(plan.slug)}&ciclo=${encodeURIComponent(cycle.code)}`}
                                className={cn(
                                    'mt-6 inline-flex h-11 items-center justify-center rounded-control text-[0.9375rem] font-semibold',
                                    plan.is_featured ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'border border-[var(--line-2)] text-[var(--text)] hover:bg-[var(--surface-2)]',
                                )}
                            >
                                {text(data.cta_label) || 'Contratar'}
                            </a>
                        </div>
                    );
                })}
            </div>

            {comparison.length > 0 && plans.length > 1 && (
                <div className="mt-10 overflow-x-auto rounded-card border border-[var(--line)] bg-[var(--surface)]">
                    <table className="w-full min-w-[32rem] text-left text-[0.875rem]">
                        <caption className="sr-only">Qué incluye cada plan</caption>
                        <thead>
                            <tr className="border-b border-[var(--line)]">
                                <th scope="col" className="px-4 py-3 font-semibold">Qué incluye</th>
                                {plans.map((plan) => (
                                    <th key={plan.id} scope="col" className="px-4 py-3 text-center font-semibold">
                                        {plan.name}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {comparison.map((row) => (
                                <tr key={row.key} className="border-b border-[var(--line)] last:border-0">
                                    <th scope="row" className="px-4 py-2.5 font-medium">
                                        {row.display}
                                    </th>
                                    {plans.map((plan) => {
                                        const state = row.plans[String(plan.id)] ?? 'none';

                                        return (
                                            <td key={plan.id} className="px-4 py-2.5 text-center">
                                                {state === 'all' && <CheckIcon size={18} weight="bold" className="inline text-[var(--accent)]" aria-label="Incluido" />}
                                                {state === 'some' && <span className="text-[0.8125rem] text-[var(--muted)]">En parte</span>}
                                                {state === 'none' && <span className="text-[var(--muted)]" aria-label="No incluido">—</span>}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Section>
    );
}

function PlanLine({ children }: { children: ReactNode }) {
    return (
        <li className="flex items-start gap-2">
            <CheckIcon size={18} weight="bold" className="mt-0.5 shrink-0 text-[var(--accent)]" />
            <span>{children}</span>
        </li>
    );
}

function TestimonialsBlock({ data }: { data: Record<string, unknown> }) {
    const items = list<{ quote: string; name: string; role: string }>(data.items).filter((item) => item.quote);

    if (items.length === 0) {
        return null;
    }

    return (
        <Section>
            <SectionTitle title={text(data.title)} />
            <div className="grid gap-4 md:grid-cols-2">
                {items.map((item, index) => (
                    <figure key={index} className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-6">
                        <QuotesIcon size={28} weight="fill" className="text-[var(--accent)]" />
                        <blockquote className="mt-3 text-[1.0625rem]">{item.quote}</blockquote>
                        <figcaption className="mt-4 text-[0.875rem]">
                            <strong>{item.name}</strong>
                            {item.role && <span className="text-[var(--muted)]"> · {item.role}</span>}
                        </figcaption>
                    </figure>
                ))}
            </div>
        </Section>
    );
}

function FaqBlock({ data }: { data: Record<string, unknown> }) {
    const items = list<{ question: string; answer: string }>(data.items).filter((item) => item.question);

    if (items.length === 0) {
        return null;
    }

    return (
        <Section className={BAND_BG}>
            <SectionTitle title={text(data.title)} />
            <div className="mx-auto flex max-w-[48rem] flex-col gap-2">
                {items.map((item, index) => (
                    <details key={index} className="group rounded-card border border-[var(--line)] bg-[var(--surface)] px-5 py-4">
                        <summary className="cursor-pointer list-none text-[1rem] font-semibold marker:hidden">
                            <span className="flex items-center justify-between gap-3">
                                {item.question}
                                <span className="text-[var(--accent)] transition-transform group-open:rotate-45">+</span>
                            </span>
                        </summary>
                        <p className="mt-3 whitespace-pre-line text-[0.9375rem] text-[var(--muted)]">{item.answer}</p>
                    </details>
                ))}
            </div>
        </Section>
    );
}

function CtaBandBlock({ data }: { data: Record<string, unknown> }) {
    const dark = data.tone === 'dark';

    return (
        <section className="px-[var(--sec-px,1rem)] pt-[var(--sec-pt,2.5rem)] pb-[var(--sec-pb,2.5rem)]">
            <div
                className={cn(
                    'mx-auto flex w-full max-w-[var(--sec-max,70rem)] flex-col items-start gap-5 rounded-sheet px-6 py-10 md:flex-row md:items-center md:justify-between md:px-10',
                    dark ? 'bg-[#14171A] text-white' : 'bg-[var(--accent)] text-[var(--accent-ink)]',
                )}
            >
                <div>
                    <h2 className="text-[1.5rem] leading-tight md:text-[1.875rem]">{text(data.title)}</h2>
                    {text(data.text) && <p className="mt-2 max-w-[40rem] opacity-90">{text(data.text)}</p>}
                </div>
                <CtaLink href={text(data.button_url)} variant="light" className="shrink-0">
                    {text(data.button_label)}
                </CtaLink>
            </div>
        </section>
    );
}

function ContactBlock({ data, site }: { data: Record<string, unknown>; site: LandingSite }) {
    const { flash } = usePage<PageProps>().props;
    const [sent, setSent] = useState(false);
    const form = useForm({ source: 'contact', company_name: '', city: '', contact_name: '', email: '', phone: '', estimated_stores: '', message: '', website: '' });
    const whatsapp = bool(data.show_whatsapp) && site.whatsapp ? `https://wa.me/${site.whatsapp.replace(/[^0-9]/g, '')}` : null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(site.contact_url, { preserveScroll: true, onSuccess: () => { setSent(true); form.reset(); } });
    };

    return (
        <Section id="contacto">
            <div className="grid gap-8 md:grid-cols-[1fr_1.2fr]">
                <div>
                    <h2 className="text-[1.75rem] leading-tight md:text-[2.25rem]">{text(data.title)}</h2>
                    <p className="mt-3 text-[var(--muted)]">{text(data.text)}</p>
                    {whatsapp && (
                        <a href={whatsapp} target="_blank" rel="noreferrer" className="mt-5 inline-flex items-center gap-2 font-semibold text-[var(--accent)] hover:underline">
                            <WhatsappLogoIcon size={20} weight="fill" /> Escríbenos por WhatsApp
                        </a>
                    )}
                </div>

                {sent ? (
                    <p className="rounded-card border border-[var(--accent)] bg-[var(--accent-soft)] p-6 text-[1rem] font-medium text-[var(--accent)]">
                        {text(data.success_text) || flash.success || 'Recibimos tus datos.'}
                    </p>
                ) : (
                    <form onSubmit={submit} className="grid gap-3 rounded-card border border-[var(--line)] bg-[var(--surface)] p-5 sm:grid-cols-2">
                        <ContactInput label="Tu nombre" value={form.data.contact_name} onChange={(value) => form.setData('contact_name', value)} error={form.errors.contact_name} required />
                        <ContactInput label="Correo" type="email" value={form.data.email} onChange={(value) => form.setData('email', value)} error={form.errors.email} required />
                        <ContactInput label="Mercado" value={form.data.company_name} onChange={(value) => form.setData('company_name', value)} error={form.errors.company_name} />
                        <ContactInput label="Celular" value={form.data.phone} onChange={(value) => form.setData('phone', value)} error={form.errors.phone} />
                        <ContactInput label="Ciudad" value={form.data.city} onChange={(value) => form.setData('city', value)} error={form.errors.city} />
                        <ContactInput label="¿Cuántas sedes?" inputMode="numeric" value={form.data.estimated_stores} onChange={(value) => form.setData('estimated_stores', value.replace(/\D/g, ''))} error={form.errors.estimated_stores} />
                        <label className="flex flex-col gap-1 text-[0.8125rem] font-medium sm:col-span-2">
                            Mensaje
                            <textarea
                                value={form.data.message}
                                onChange={(event) => form.setData('message', event.target.value)}
                                rows={3}
                                className="rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[0.9375rem] font-normal outline-none focus:border-[var(--accent)]"
                            />
                        </label>
                        {/* Campo trampa: una persona no lo ve; un robot lo llena. */}
                        <input
                            type="text"
                            name="website"
                            tabIndex={-1}
                            autoComplete="off"
                            value={form.data.website}
                            onChange={(event) => form.setData('website', event.target.value)}
                            className="absolute left-[-9999px] h-px w-px opacity-0"
                            aria-hidden
                        />
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex h-11 items-center justify-center rounded-control bg-[var(--accent)] px-5 font-semibold text-[var(--accent-ink)] disabled:opacity-70 sm:col-span-2"
                        >
                            {text(data.button_label) || 'Enviar'}
                        </button>
                    </form>
                )}
            </div>
        </Section>
    );
}

function ContactInput({
    label,
    value,
    onChange,
    error,
    type = 'text',
    required,
    inputMode,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    type?: string;
    required?: boolean;
    inputMode?: 'numeric' | 'text';
}) {
    return (
        <label className="flex flex-col gap-1 text-[0.8125rem] font-medium">
            {label}
            <input
                type={type}
                value={value}
                required={required}
                inputMode={inputMode}
                onChange={(event) => onChange(event.target.value)}
                className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.9375rem] font-normal outline-none focus:border-[var(--accent)]"
            />
            {error && <span className="text-[0.75rem] font-medium text-[var(--danger)]">{error}</span>}
        </label>
    );
}

function FooterBlock({ data, context }: { data: Record<string, unknown>; context: Context }) {
    const site = context.site;
    const links = list<{ label: string; url: string }>(data.links).filter((link) => link.label && link.url);
    const social = list<{ network: string; url: string }>(data.social).filter((item) => item.url);

    return (
        <footer className="border-t border-[var(--line)] bg-[var(--surface)] px-4 py-12">
            <div className="mx-auto grid w-full max-w-[70rem] gap-8 md:grid-cols-[1.4fr_1fr_1fr]">
                <div>
                    <p className="flex items-center gap-2 text-[1.0625rem] font-semibold">
                        {site.mark_url ? (
                            <img src={site.mark_url} alt="" className="size-8 rounded-control object-contain" />
                        ) : (
                            <span className="grid size-8 place-items-center rounded-control bg-[var(--accent)] text-[var(--accent-ink)]">
                                <BasketIcon size={18} weight="bold" />
                            </span>
                        )}
                        {site.name}
                    </p>
                    {text(data.about) && <p className="mt-3 max-w-[26rem] text-[0.9375rem] text-[var(--muted)]">{text(data.about)}</p>}
                    {social.length > 0 && (
                        <div className="mt-4 flex gap-2">
                            {social.map((item) => {
                                const SocialIcon = SOCIAL[item.network] ?? GlobeIcon;

                                return (
                                    <a key={`${item.network}${item.url}`} href={item.url} target="_blank" rel="noreferrer" aria-label={item.network} className="grid size-9 place-items-center rounded-control border border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]">
                                        <SocialIcon size={18} />
                                    </a>
                                );
                            })}
                        </div>
                    )}
                </div>
                <nav className="flex flex-col gap-2 text-[0.9375rem]">
                    {links.map((link) => (
                        <a key={`${link.label}${link.url}`} href={link.url} className="text-[var(--muted)] hover:text-[var(--text)]">
                            {link.label}
                        </a>
                    ))}
                </nav>
                <div className="flex flex-col gap-2 text-[0.9375rem]">
                    <Link href={site.terms_url} className="text-[var(--muted)] hover:text-[var(--text)]">
                        Términos y condiciones
                    </Link>
                    <Link href={site.privacy_url} className="text-[var(--muted)] hover:text-[var(--text)]">
                        Tratamiento de datos personales
                    </Link>
                    {site.support_email && (
                        <a href={`mailto:${site.support_email}`} className="text-[var(--muted)] hover:text-[var(--text)]">
                            {site.support_email}
                        </a>
                    )}
                </div>
            </div>
            <div className="mx-auto mt-10 flex w-full max-w-[70rem] flex-wrap items-center justify-between gap-4">
                <p className="text-[0.8125rem] text-[var(--muted)]">
                    © {new Date().getFullYear()} {site.name}. {text(data.legal)}
                </p>
                {context.themeToggle && (
                    <div className="flex items-center gap-2 text-[0.8125rem] text-[var(--muted)]">
                        Modo de pantalla
                        <LandingThemeToggle context={context} />
                    </div>
                )}
            </div>
        </footer>
    );
}
