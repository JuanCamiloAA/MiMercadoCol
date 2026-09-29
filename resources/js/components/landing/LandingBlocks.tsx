import { Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowRightIcon,
    BasketIcon,
    BuildingsIcon,
    CalendarIcon,
    CashRegisterIcon,
    ChartLineUpIcon,
    CheckIcon,
    ClockIcon,
    CreditCardIcon,
    CrosshairIcon,
    DeviceMobileIcon,
    FacebookLogoIcon,
    GlobeIcon,
    HandshakeIcon,
    HeadsetIcon,
    HeartIcon,
    HouseIcon,
    InstagramLogoIcon,
    LightningIcon,
    LinkedinLogoIcon,
    ListIcon,
    MagnifyingGlassIcon,
    MapPinIcon,
    PackageIcon,
    PercentIcon,
    QuotesIcon,
    ReceiptIcon,
    RocketIcon,
    ShieldCheckIcon,
    ShoppingCartIcon,
    SparkleIcon,
    StarIcon,
    StorefrontIcon,
    TagIcon,
    TiktokLogoIcon,
    TruckIcon,
    UserPlusIcon,
    UsersIcon,
    WhatsappLogoIcon,
    XIcon,
    YoutubeLogoIcon,
    type Icon,
} from '@phosphor-icons/react';
import { type FormEvent, type ReactNode, useEffect, useMemo, useState } from 'react';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { csrfHeaders } from '@/lib/http';
import type { PageProps } from '@/types';

/** Los iconos de `config/landing_icons.php`, uno por uno: un `import *` metería toda la librería. */
export const ICONS: Record<string, Icon> = {
    Storefront: StorefrontIcon,
    MapPin: MapPinIcon,
    UserPlus: UserPlusIcon,
    ShoppingCart: ShoppingCartIcon,
    Basket: BasketIcon,
    Truck: TruckIcon,
    Package: PackageIcon,
    Receipt: ReceiptIcon,
    CreditCard: CreditCardIcon,
    CashRegister: CashRegisterIcon,
    ChartLineUp: ChartLineUpIcon,
    Users: UsersIcon,
    Buildings: BuildingsIcon,
    Globe: GlobeIcon,
    DeviceMobile: DeviceMobileIcon,
    ShieldCheck: ShieldCheckIcon,
    Lightning: LightningIcon,
    Clock: ClockIcon,
    Headset: HeadsetIcon,
    Sparkle: SparkleIcon,
    Star: StarIcon,
    Heart: HeartIcon,
    Tag: TagIcon,
    Percent: PercentIcon,
    House: HouseIcon,
    Calendar: CalendarIcon,
    Handshake: HandshakeIcon,
    Rocket: RocketIcon,
};

const SOCIAL: Record<string, Icon> = {
    instagram: InstagramLogoIcon,
    facebook: FacebookLogoIcon,
    tiktok: TiktokLogoIcon,
    youtube: YoutubeLogoIcon,
    linkedin: LinkedinLogoIcon,
};

export interface LandingSite {
    name: string;
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

export interface LandingStore {
    id: number;
    name: string;
    company: string;
    company_name: string;
    company_id: number;
    address: string | null;
    city: string | null;
    latitude: number | null;
    longitude: number | null;
    opens_at: string | null;
    closes_at: string | null;
    is_open: boolean;
    accent: string;
    accent_ink: string;
    logo_url: string | null;
    url: string;
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
    features: string[];
    prices: Record<string, number>;
    monthly: Record<string, number>;
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
}

interface Context {
    site: LandingSite;
    panel: { name: string; url: string } | null;
    isPreview: boolean;
}

const text = (value: unknown): string => (typeof value === 'string' ? value : '');
const bool = (value: unknown): boolean => value === true;
const list = <T,>(value: unknown): T[] => (Array.isArray(value) ? (value as T[]) : []);

/**
 * Pinta la landing bloque por bloque. Lo mismo en la página pública y en la vista previa del
 * editor: los datos de la base (sedes, empresas, planes) ya vienen resueltos del servidor.
 */
export function LandingBlocks({ blocks, site, panel, isPreview }: { blocks: LandingBlockView[] } & Context) {
    const context: Context = { site, panel, isPreview };

    return (
        <>
            {blocks.map((block) => (
                <LandingBlock key={block.uuid} block={block} context={context} />
            ))}
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
            return <StoreFinderBlock data={block.data} stores={list<LandingStore>(block.source?.stores)} isPreview={context.isPreview} />;
        case 'how_it_works':
            return <StepsBlock data={block.data} />;
        case 'companies_showcase':
            return <CompaniesBlock data={block.data} companies={list<Record<string, unknown>>(block.source?.companies)} />;
        case 'benefits':
            return <BenefitsBlock data={block.data} />;
        case 'plans':
            return (
                <PlansBlock
                    data={block.data}
                    plans={list<LandingPlan>(block.source?.plans)}
                    cycles={list<LandingCycle>(block.source?.cycles)}
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
            return <FooterBlock data={block.data} site={context.site} />;
        default:
            return null;
    }
}

function Section({ id, children, className }: { id?: string; children: ReactNode; className?: string }) {
    return (
        <section id={id} className={cn('scroll-mt-20 px-4 py-14 md:py-20', className)}>
            <div className="mx-auto w-full max-w-[70rem]">{children}</div>
        </section>
    );
}

function SectionTitle({ title, body, center = true }: { title: string; body?: string; center?: boolean }) {
    if (!title && !body) {
        return null;
    }

    return (
        <div className={cn('mb-8 max-w-[44rem]', center && 'mx-auto text-center')}>
            {title && <h2 className="text-[1.75rem] leading-tight md:text-[2.25rem]">{title}</h2>}
            {body && <p className="mt-3 text-[1rem] text-[var(--muted)]">{body}</p>}
        </div>
    );
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

function HeaderBlock({ data, context }: { data: Record<string, unknown>; context: Context }) {
    const [open, setOpen] = useState(false);
    const name = text(data.brand_name) || context.site.name;
    const links = list<{ label: string; url: string }>(data.links).filter((link) => link.label && link.url);
    const logo = text(data.logo_url);

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
    const image = text(data.image_url);

    return (
        <section className="relative overflow-hidden px-4 pb-14 pt-12 md:pb-20 md:pt-20">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-x-0 top-0 h-[28rem] opacity-60"
                style={{ background: 'radial-gradient(60% 60% at 20% 0%, var(--accent-soft), transparent 70%)' }}
            />
            <div className={cn('relative mx-auto grid w-full max-w-[70rem] items-center gap-10', !centered && image && 'md:grid-cols-[1.1fr_1fr]')}>
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
                {image && !centered && <img src={image} alt="" className="aspect-[4/3] w-full rounded-sheet object-cover shadow-xl" />}
                {image && centered && <img src={image} alt="" className="mx-auto mt-2 aspect-[16/7] w-full max-w-[60rem] rounded-sheet object-cover shadow-xl" />}
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

function StoreFinderBlock({ data, stores, isPreview }: { data: Record<string, unknown>; stores: LandingStore[]; isPreview: boolean }) {
    const cities = useMemo(() => Array.from(new Set(stores.map((store) => store.city).filter((city): city is string => Boolean(city)))).sort(), [stores]);
    const defaultCity = text(data.default_city);
    const [city, setCity] = useState(cities.includes(defaultCity) ? defaultCity : '');
    const [query, setQuery] = useState('');
    const [nearby, setNearby] = useState<LandingStore[] | null>(null);
    const [locating, setLocating] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [last, setLast] = useState<LastStore | null>(null);

    useEffect(() => {
        const saved = readLastStore();
        // Solo si esa sede sigue vendiendo.
        setLast(saved && stores.some((store) => store.id === saved.id) ? saved : null);
    }, [stores]);

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

    const needle = query.trim().toLowerCase();
    const shown = (nearby ?? stores).filter(
        (store) =>
            (city === '' || store.city === city) &&
            (needle === '' || [store.name, store.company, store.address, store.city].some((value) => value?.toLowerCase().includes(needle))),
    );

    return (
        <Section id="tiendas" className="bg-[var(--surface-2)]">
            <SectionTitle title={text(data.title)} body={text(data.text)} />

            {last && (
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

            <div className="mb-5 flex flex-col gap-3 md:flex-row md:items-center">
                <button
                    type="button"
                    onClick={locate}
                    disabled={locating}
                    className="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-[var(--accent)] px-4 text-[0.875rem] font-semibold text-[var(--accent-ink)] disabled:opacity-70"
                >
                    <CrosshairIcon size={18} /> {locating ? 'Buscando…' : 'Usar mi ubicación'}
                </button>
                <label className="flex h-11 flex-1 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3">
                    <MagnifyingGlassIcon size={18} className="text-[var(--muted)]" />
                    <input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Nombre, barrio o dirección"
                        className="h-full w-full bg-transparent text-[0.9375rem] outline-none"
                    />
                </label>
                {cities.length > 1 && (
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
                )}
            </div>

            {error && <p className="mb-4 text-[0.875rem] text-[var(--warn)]">{error}</p>}

            {shown.length === 0 ? (
                <p className="rounded-card border border-dashed border-[var(--line-2)] bg-[var(--surface)] px-4 py-10 text-center text-[var(--muted)]">
                    {text(data.empty_text) || 'No hay tiendas.'}
                </p>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {shown.map((store) => (
                        <article key={store.id} className="flex flex-col rounded-card border border-[var(--line)] bg-[var(--surface)] p-4">
                            <div className="flex items-center gap-3">
                                {store.logo_url ? (
                                    <img src={store.logo_url} alt={store.company} className="size-11 rounded-control object-contain" />
                                ) : (
                                    <span className="grid size-11 place-items-center rounded-control text-[1.0625rem] font-bold" style={{ background: store.accent, color: store.accent_ink }}>
                                        {store.company.slice(0, 1)}
                                    </span>
                                )}
                                <div className="min-w-0">
                                    <p className="truncate text-[0.8125rem] font-semibold" style={{ color: store.accent }}>
                                        {store.company}
                                    </p>
                                    <h3 className="truncate text-[1.0625rem] font-semibold">Sede {store.name}</h3>
                                </div>
                            </div>
                            <p className="mt-3 flex items-start gap-1.5 text-[0.875rem] text-[var(--muted)]">
                                <MapPinIcon size={16} className="mt-0.5 shrink-0" />
                                <span>{[store.address, store.city].filter(Boolean).join(', ') || 'Sin dirección publicada'}</span>
                            </p>
                            <div className="mt-2 flex flex-wrap items-center gap-2 text-[0.8125rem]">
                                {bool(data.show_hours) && store.opens_at && store.closes_at && (
                                    <>
                                        <span
                                            className={cn(
                                                'rounded-full px-2 py-0.5 font-semibold',
                                                store.is_open ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'bg-[var(--surface-2)] text-[var(--muted)]',
                                            )}
                                        >
                                            {store.is_open ? 'Abierta' : 'Cerrada'}
                                        </span>
                                        <span className="text-[var(--muted)]">
                                            {store.opens_at} – {store.closes_at}
                                        </span>
                                    </>
                                )}
                                {bool(data.show_distance) && typeof store.distance_km === 'number' && (
                                    <span className="text-[var(--muted)]">a {store.distance_km.toLocaleString('es-CO', { maximumFractionDigits: 1 })} km</span>
                                )}
                            </div>
                            <a
                                href={store.url}
                                onClick={() => remember(store)}
                                className="mt-4 inline-flex h-11 items-center justify-center gap-2 rounded-control text-[0.9375rem] font-semibold"
                                style={{ background: store.accent, color: store.accent_ink }}
                            >
                                <ShoppingCartIcon size={18} /> Comprar aquí
                            </a>
                        </article>
                    ))}
                </div>
            )}
        </Section>
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

function CompaniesBlock({ data, companies }: { data: Record<string, unknown>; companies: Record<string, unknown>[] }) {
    if (companies.length === 0) {
        return null;
    }

    return (
        <Section className="bg-[var(--surface-2)]">
            <SectionTitle title={text(data.title)} body={text(data.text)} />
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
        </Section>
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

function PlansBlock({ data, plans, cycles, signupUrl }: { data: Record<string, unknown>; plans: LandingPlan[]; cycles: LandingCycle[]; signupUrl: string }) {
    const [cycleId, setCycleId] = useState<number | null>(cycles[0]?.id ?? null);
    const cycle = cycles.find((item) => item.id === cycleId) ?? cycles[0];

    if (plans.length === 0 || !cycle) {
        return null;
    }

    return (
        <Section id="planes" className="bg-[var(--surface-2)]">
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
                                {plan.features.map((feature) => (
                                    <PlanLine key={feature}>{feature}</PlanLine>
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
        <Section className="bg-[var(--surface-2)]">
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
        <section className="px-4 py-10">
            <div
                className={cn(
                    'mx-auto flex w-full max-w-[70rem] flex-col items-start gap-5 rounded-sheet px-6 py-10 md:flex-row md:items-center md:justify-between md:px-10',
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

function FooterBlock({ data, site }: { data: Record<string, unknown>; site: LandingSite }) {
    const links = list<{ label: string; url: string }>(data.links).filter((link) => link.label && link.url);
    const social = list<{ network: string; url: string }>(data.social).filter((item) => item.url);

    return (
        <footer className="border-t border-[var(--line)] bg-[var(--surface)] px-4 py-12">
            <div className="mx-auto grid w-full max-w-[70rem] gap-8 md:grid-cols-[1.4fr_1fr_1fr]">
                <div>
                    <p className="flex items-center gap-2 text-[1.0625rem] font-semibold">
                        <span className="grid size-8 place-items-center rounded-control bg-[var(--accent)] text-[var(--accent-ink)]">
                            <BasketIcon size={18} weight="bold" />
                        </span>
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
            <p className="mx-auto mt-10 w-full max-w-[70rem] text-[0.8125rem] text-[var(--muted)]">
                © {new Date().getFullYear()} {site.name}. {text(data.legal)}
            </p>
        </footer>
    );
}
