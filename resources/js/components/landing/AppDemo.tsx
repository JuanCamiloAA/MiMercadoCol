import { bool, list, Section, SectionTitle, text } from '@/components/landing/landing-ui';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import {
    ArrowDownRightIcon,
    ArrowUpRightIcon,
    CashRegisterIcon,
    ChartLineUpIcon,
    CheckCircleIcon,
    HouseIcon,
    MagnifyingGlassIcon,
    MinusIcon,
    PackageIcon,
    PlusIcon,
    StackIcon,
    TagIcon,
    TrashIcon,
    UsersIcon,
    type Icon,
} from '@phosphor-icons/react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

/**
 * «Demo del sistema»: una ventana del panel con datos de ejemplo que llegan del servidor
 * (`DemoWorkspace`). Quien visita cobra, mueve pedidos, busca y apaga artículos; todo eso vive en
 * el estado de este componente y se pierde al recargar. No hay peticiones: nada llega a la base.
 */

interface DemoModule {
    key: ModuleKey;
    label: string;
    title: string;
    subtitle: string;
}

type ModuleKey = 'dashboard' | 'pos' | 'orders' | 'products' | 'inventory' | 'customers' | 'finance';

interface DemoProduct {
    id: number;
    name: string;
    sku: string;
    category: string;
    price: number;
    cost: number;
    stock: number;
    min_stock: number;
    unit: string;
    active: boolean;
}

interface DemoSource {
    business: { name: string; initials: string; store: string };
    modules: DemoModule[];
    data: Record<string, Record<string, unknown>>;
    notifications: string[];
}

const MODULE_ICONS: Record<ModuleKey, Icon> = {
    dashboard: HouseIcon,
    pos: CashRegisterIcon,
    orders: PackageIcon,
    products: TagIcon,
    inventory: StackIcon,
    customers: UsersIcon,
    finance: ChartLineUpIcon,
};

/** Cuánto se queda el recorrido automático en cada módulo, y cada cuánto llega un aviso. */
const TOUR_MS = 7000;
const NOTICE_EVERY_MS = 11000;
const NOTICE_FOR_MS = 4500;

export function AppDemoBlock({ data, source }: { data: Record<string, unknown>; source: Record<string, unknown> | null }) {
    const demo = source as DemoSource | null;
    const modules = useMemo(() => demo?.modules ?? [], [demo]);
    const [active, setActive] = useState<ModuleKey | null>(modules[0]?.key ?? null);
    const [touched, setTouched] = useState(false);
    const [visible, setVisible] = useState(false);
    const [toast, setToast] = useState<{ id: number; message: string } | null>(null);
    const frameRef = useRef<HTMLDivElement>(null);
    const lessMotion = useMemo(() => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches, []);
    const current = modules.find((module) => module.key === active) ?? modules[0];

    const notify = (message: string) => setToast({ id: Date.now(), message });

    useEffect(() => {
        const element = frameRef.current;

        if (!element) {
            return;
        }

        const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting), { threshold: 0.3 });
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    // El recorrido pasa solo por los módulos hasta que la persona toca algo: desde ahí manda ella.
    useEffect(() => {
        if (!bool(data.tour) || touched || !visible || lessMotion || modules.length < 2) {
            return;
        }

        const timer = window.setTimeout(() => {
            const index = modules.findIndex((module) => module.key === current?.key);
            setActive(modules[(index + 1) % modules.length].key);
        }, TOUR_MS);

        return () => window.clearTimeout(timer);
    }, [data.tour, touched, visible, lessMotion, modules, current?.key]);

    // Los avisos en vivo: un pedido o una venta que «llega» de vez en cuando, solo con la demo a la vista.
    useEffect(() => {
        const messages = demo?.notifications ?? [];

        if (!bool(data.notifications) || !visible || messages.length === 0) {
            return;
        }

        let turn = 0;
        const timer = window.setInterval(() => {
            notify(messages[turn % messages.length]);
            turn += 1;
        }, NOTICE_EVERY_MS);

        return () => window.clearInterval(timer);
    }, [data.notifications, visible, demo?.notifications]);

    useEffect(() => {
        if (!toast) {
            return;
        }

        const timer = window.setTimeout(() => setToast(null), NOTICE_FOR_MS);

        return () => window.clearTimeout(timer);
    }, [toast]);

    if (!demo || !current) {
        return null;
    }

    return (
        <section data-theme={data.tone === 'light' ? undefined : 'dark'} className="relative overflow-hidden bg-[var(--sec-own-bg,var(--bg))] text-[var(--text)]">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 opacity-60"
                style={{ background: 'radial-gradient(45% 50% at 50% 0%, var(--accent-soft), transparent 70%)' }}
            />
            <Section className="relative">
                <SectionTitle title={text(data.title)} body={text(data.text) || undefined} />

                <div
                    ref={frameRef}
                    onPointerDown={() => setTouched(true)}
                    onKeyDown={() => setTouched(true)}
                    className="rounded-sheet overflow-hidden border border-[var(--line)] bg-[var(--surface)] shadow-2xl"
                >
                    <div className="flex h-11 items-center gap-3 border-b border-[var(--line)] bg-[var(--surface-2)] px-4">
                        <span aria-hidden className="flex gap-1.5">
                            <span className="size-2.5 rounded-full bg-[#FF5F57]" />
                            <span className="size-2.5 rounded-full bg-[#FEBC2E]" />
                            <span className="size-2.5 rounded-full bg-[#28C840]" />
                        </span>
                        <span className="truncate rounded-full bg-[var(--surface)] px-3 py-1 text-[0.75rem] text-[var(--muted)]">
                            {demo.business.name} · panel
                        </span>
                        <span className="ml-auto shrink-0 rounded-full bg-[var(--accent-soft)] px-2.5 py-1 text-[0.6875rem] font-semibold text-[var(--accent)]">
                            Demostración · datos de ejemplo
                        </span>
                    </div>

                    {/* En el celular el menú lateral se vuelve una fila que se desliza. */}
                    <nav aria-label="Módulos de la demostración" className="flex gap-1 overflow-x-auto border-b border-[var(--line)] p-2 md:hidden">
                        {modules.map((module) => (
                            <ModuleTab
                                key={module.key}
                                module={module}
                                active={module.key === current.key}
                                onSelect={() => setActive(module.key)}
                                compact
                            />
                        ))}
                    </nav>

                    <div className="flex h-[40rem] md:h-[38rem]">
                        <aside className="hidden w-56 shrink-0 flex-col border-r border-[var(--line)] bg-[var(--bg)] p-3 md:flex">
                            <div className="mb-4 flex items-center gap-2.5 px-2 pt-1">
                                <span className="rounded-control grid size-9 place-items-center bg-[var(--accent)] text-[0.8125rem] font-bold text-[var(--accent-ink)]">
                                    {demo.business.initials}
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate text-[0.875rem] font-semibold">{demo.business.name}</span>
                                    <span className="block truncate text-[0.6875rem] text-[var(--muted)]">{demo.business.store}</span>
                                </span>
                            </div>
                            <nav aria-label="Módulos de la demostración" className="flex flex-col gap-0.5">
                                {modules.map((module) => (
                                    <ModuleTab
                                        key={module.key}
                                        module={module}
                                        active={module.key === current.key}
                                        onSelect={() => setActive(module.key)}
                                    />
                                ))}
                            </nav>
                        </aside>

                        <div className="relative min-w-0 flex-1 overflow-y-auto">
                            <div className="p-4 md:p-6">
                                <header className="mb-5">
                                    <h3 className="text-[1.25rem] leading-tight font-semibold md:text-[1.5rem]">{current.title}</h3>
                                    <p className="text-[0.8125rem] text-[var(--muted)]">{current.subtitle}</p>
                                </header>
                                {/* Todos quedan montados: volver a un módulo lo encuentra como se dejó. */}
                                {modules.map((module) => (
                                    <div key={module.key} hidden={module.key !== current.key}>
                                        <ModuleView module={module.key} data={demo.data[module.key] ?? {}} notify={notify} />
                                    </div>
                                ))}
                            </div>

                            {toast && (
                                <div
                                    key={toast.id}
                                    role="status"
                                    className="animate-fade-in rounded-card sticky bottom-4 mr-4 ml-auto flex w-fit max-w-[22rem] items-center gap-3 border border-[var(--line)] bg-[var(--surface)] px-4 py-3 text-[0.8125rem] shadow-xl"
                                >
                                    <span className="size-2.5 shrink-0 rounded-full bg-[#22A06B]" />
                                    {toast.message}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </Section>
        </section>
    );
}

function ModuleTab({ module, active, onSelect, compact = false }: { module: DemoModule; active: boolean; onSelect: () => void; compact?: boolean }) {
    const ModuleIcon = MODULE_ICONS[module.key];

    return (
        <button
            type="button"
            onClick={onSelect}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'rounded-control flex shrink-0 items-center gap-2.5 px-3 text-left text-[0.8125rem] font-medium transition-colors',
                compact ? 'h-9' : 'h-10',
                active ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]',
            )}
        >
            <ModuleIcon size={18} weight={active ? 'fill' : 'regular'} />
            {module.label}
        </button>
    );
}

function ModuleView({ module, data, notify }: { module: ModuleKey; data: Record<string, unknown>; notify: (message: string) => void }) {
    switch (module) {
        case 'dashboard':
            return <DashboardDemo data={data} />;
        case 'pos':
            return <PosDemo data={data} notify={notify} />;
        case 'orders':
            return <OrdersDemo data={data} notify={notify} />;
        case 'products':
            return <ProductsDemo data={data} notify={notify} />;
        case 'inventory':
            return <InventoryDemo data={data} notify={notify} />;
        case 'customers':
            return <CustomersDemo data={data} />;
        case 'finance':
            return <FinanceDemo data={data} />;
    }
}

/* ---------- Piezas compartidas ---------- */

function Panel({ title, subtitle, children, className }: { title?: string; subtitle?: string; children: ReactNode; className?: string }) {
    return (
        <div className={cn('rounded-card border border-[var(--line)] bg-[var(--surface)] p-4', className)}>
            {title && (
                <div className="mb-3">
                    <p className="text-[0.875rem] font-semibold">{title}</p>
                    {subtitle && <p className="text-[0.75rem] text-[var(--muted)]">{subtitle}</p>}
                </div>
            )}
            {children}
        </div>
    );
}

function Change({ value }: { value: number }) {
    const up = value >= 0;
    const ArrowIcon = up ? ArrowUpRightIcon : ArrowDownRightIcon;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[0.6875rem] font-semibold',
                up ? 'bg-[#22A06B]/15 text-[#22A06B]' : 'bg-[var(--danger-soft)] text-[var(--danger)]',
            )}
        >
            <ArrowIcon size={11} weight="bold" />
            {up ? '+' : ''}
            {String(value).replace('.', ',')} %
        </span>
    );
}

function SearchBox({ value, onChange, placeholder }: { value: string; onChange: (value: string) => void; placeholder: string }) {
    return (
        <label className="rounded-control flex h-9 items-center gap-2 border border-[var(--line)] bg-[var(--surface)] px-3 text-[0.8125rem]">
            <MagnifyingGlassIcon size={15} className="text-[var(--muted)]" />
            <input
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-[var(--muted)]"
            />
        </label>
    );
}

function Chips<T extends string>({ options, value, onChange }: { options: { value: T; label: string }[]; value: T; onChange: (value: T) => void }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'h-8 rounded-full border px-3 text-[0.75rem] font-medium transition-colors',
                        value === option.value
                            ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                            : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function StockMeter({ stock, min }: { stock: number; min: number }) {
    const share = Math.min(1, stock / Math.max(1, min * 3));
    const tone = stock === 0 ? 'bg-[var(--danger)]' : stock <= min ? 'bg-[var(--warn)]' : 'bg-[#22A06B]';

    return (
        <span className="block h-1.5 w-full overflow-hidden rounded-full bg-[var(--surface-2)]">
            <span className={cn('block h-full rounded-full', tone)} style={{ width: `${Math.max(4, share * 100)}%` }} />
        </span>
    );
}

/* ---------- Inicio ---------- */

function DashboardDemo({ data }: { data: Record<string, unknown> }) {
    const kpis = list<{ label: string; value: number; money: boolean; change: number }>(data.kpis);
    const week = list<{ label: string; value: number }>(data.week);
    const top = list<{ name: string; units: number; total: number }>(data.top);
    const recent = list<{ number: string; customer: string; method: string; total: number; ago: string }>(data.recent);
    const peak = Math.max(1, ...week.map((day) => day.value));

    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                {kpis.map((kpi) => (
                    <Panel key={kpi.label}>
                        <p className="text-[0.6875rem] font-semibold tracking-wide text-[var(--muted)] uppercase">{kpi.label}</p>
                        <p className="tabular mt-1 font-mono text-[1.25rem] font-semibold md:text-[1.375rem]">
                            {kpi.money ? money(kpi.value) : number(kpi.value)}
                        </p>
                        <div className="mt-1.5">
                            <Change value={kpi.change} />
                        </div>
                    </Panel>
                ))}
            </div>
            <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                <Panel title="Ventas de la semana" subtitle="Toca una barra para ver el día">
                    <WeekBars week={week} peak={peak} />
                </Panel>
                <Panel title="Lo más vendido" subtitle="Esta semana">
                    <ol className="flex flex-col gap-2.5">
                        {top.map((product, index) => (
                            <li key={product.name} className="flex items-center gap-3 text-[0.8125rem]">
                                <span className="w-4 font-mono text-[var(--muted)]">{index + 1}</span>
                                <span className="min-w-0 flex-1 truncate">{product.name}</span>
                                <span className="tabular font-mono text-[var(--muted)]">{number(product.units)} und</span>
                            </li>
                        ))}
                    </ol>
                </Panel>
            </div>
            <Panel title="Últimas ventas">
                <ul className="divide-y divide-[var(--line)]">
                    {recent.map((sale) => (
                        <li key={sale.number} className="flex items-center gap-3 py-2 text-[0.8125rem]">
                            <span className="font-mono text-[var(--accent)]">{sale.number}</span>
                            <span className="min-w-0 flex-1 truncate">{sale.customer}</span>
                            <span className="hidden text-[var(--muted)] sm:inline">{sale.method}</span>
                            <span className="hidden text-[0.75rem] text-[var(--muted)] sm:inline">{sale.ago}</span>
                            <span className="tabular font-mono font-semibold">{money(sale.total)}</span>
                        </li>
                    ))}
                </ul>
            </Panel>
        </div>
    );
}

function WeekBars({ week, peak }: { week: { label: string; value: number }[]; peak: number }) {
    const [picked, setPicked] = useState(week.length - 1);
    const day = week[picked];

    return (
        <div>
            <p className="mb-3 text-[0.8125rem]">
                <span className="text-[var(--muted)]">{day?.label}: </span>
                <span className="tabular font-mono font-semibold">{money(day?.value ?? 0)}</span>
            </p>
            <div className="flex h-36 items-end gap-2">
                {week.map((entry, index) => (
                    <button
                        key={entry.label}
                        type="button"
                        onClick={() => setPicked(index)}
                        aria-label={`${entry.label}: ${money(entry.value)}`}
                        className="flex h-full flex-1 flex-col items-center justify-end gap-1.5"
                    >
                        <span
                            className={cn(
                                'rounded-t-chip w-full transition-colors',
                                index === picked ? 'bg-[var(--accent)]' : 'bg-[var(--accent-soft)] hover:bg-[var(--accent)]/60',
                            )}
                            style={{ height: `${(entry.value / peak) * 100}%` }}
                        />
                        <span className="text-[0.6875rem] text-[var(--muted)]">{entry.label}</span>
                    </button>
                ))}
            </div>
        </div>
    );
}

/* ---------- Punto de venta ---------- */

function PosDemo({ data, notify }: { data: Record<string, unknown>; notify: (message: string) => void }) {
    const products = list<DemoProduct>(data.products);
    const methods = list<string>(data.methods);
    const [category, setCategory] = useState('all');
    const [search, setSearch] = useState('');
    const [lines, setLines] = useState<Record<number, number>>({});
    const [method, setMethod] = useState(methods[0] ?? 'Efectivo');
    // «C1-000413»: el prefijo de la caja y su consecutivo, que la demo va subiendo.
    const [, prefix = 'C1', start = '1'] = /^(.+)-(\d+)$/.exec(text(data.next_number)) ?? [];
    const [sequence, setSequence] = useState(Number(start));
    const [done, setDone] = useState<{ number: string; total: number; method: string } | null>(null);

    const shown = products.filter(
        (product) => (category === 'all' || product.category === category) && product.name.toLowerCase().includes(search.trim().toLowerCase()),
    );
    const ticket = products.filter((product) => lines[product.id] > 0).map((product) => ({ product, quantity: lines[product.id] }));
    const total = ticket.reduce((sum, line) => sum + line.product.price * line.quantity, 0);

    const add = (id: number, delta: number) => {
        setDone(null);
        setLines((current) => {
            const quantity = Math.max(0, (current[id] ?? 0) + delta);
            const next = { ...current, [id]: quantity };

            if (quantity === 0) {
                delete next[id];
            }

            return next;
        });
    };

    const charge = () => {
        const saleNumber = `${prefix}-${String(sequence).padStart(6, '0')}`;
        setDone({ number: saleNumber, total, method });
        setSequence((value) => value + 1);
        setLines({});
        notify(`Venta ${saleNumber} por ${money(total)}. Es una demostración: no se guardó nada.`);
    };

    return (
        <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
            <div className="flex min-w-0 flex-col gap-3">
                <SearchBox value={search} onChange={setSearch} placeholder="Buscar o escanear un artículo" />
                <Chips
                    options={[{ value: 'all', label: 'Todos' }, ...list<string>(data.categories).map((name) => ({ value: name, label: name }))]}
                    value={category}
                    onChange={setCategory}
                />
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {shown.map((product) => (
                        <button
                            key={product.id}
                            type="button"
                            onClick={() => add(product.id, 1)}
                            className="rounded-card flex flex-col items-start border border-[var(--line)] bg-[var(--surface)] p-3 text-left transition-colors hover:border-[var(--accent)]"
                        >
                            <span className="line-clamp-2 text-[0.8125rem] leading-snug font-medium">{product.name}</span>
                            <span className="tabular mt-1 font-mono text-[0.875rem] font-semibold">{money(product.price)}</span>
                            <span className="text-[0.6875rem] text-[var(--muted)]">
                                {number(product.stock)} {product.unit}
                            </span>
                        </button>
                    ))}
                    {shown.length === 0 && (
                        <p className="col-span-full py-6 text-center text-[0.8125rem] text-[var(--muted)]">Ningún artículo coincide.</p>
                    )}
                </div>
            </div>

            <Panel title="Tiquete" className="flex flex-col self-start">
                {done ? (
                    <div className="flex flex-col items-center gap-2 py-4 text-center">
                        <CheckCircleIcon size={36} weight="fill" className="text-[#22A06B]" />
                        <p className="text-[0.9375rem] font-semibold">Venta {done.number}</p>
                        <p className="tabular font-mono text-[1.125rem]">{money(done.total)}</p>
                        <p className="text-[0.75rem] text-[var(--muted)]">Cobrada en {done.method.toLowerCase()} · datos de ejemplo</p>
                        <button
                            type="button"
                            onClick={() => setDone(null)}
                            className="rounded-control mt-2 h-9 border border-[var(--line)] px-4 text-[0.8125rem] font-medium hover:bg-[var(--surface-2)]"
                        >
                            Nueva venta
                        </button>
                    </div>
                ) : ticket.length === 0 ? (
                    <p className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">Toca un artículo para agregarlo.</p>
                ) : (
                    <>
                        <ul className="flex flex-col gap-2">
                            {ticket.map(({ product, quantity }) => (
                                <li key={product.id} className="text-[0.8125rem]">
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="min-w-0 truncate">{product.name}</span>
                                        <span className="tabular font-mono">{money(product.price * quantity)}</span>
                                    </div>
                                    <div className="mt-1 flex items-center gap-1">
                                        <QuantityButton label="Quitar uno" onClick={() => add(product.id, -1)}>
                                            {quantity === 1 ? <TrashIcon size={13} /> : <MinusIcon size={13} />}
                                        </QuantityButton>
                                        <span className="tabular w-6 text-center font-mono">{quantity}</span>
                                        <QuantityButton label="Agregar uno" onClick={() => add(product.id, 1)}>
                                            <PlusIcon size={13} />
                                        </QuantityButton>
                                        <span className="ml-1 text-[0.6875rem] text-[var(--muted)]">× {money(product.price)}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-4 border-t border-[var(--line)] pt-3">
                            <div className="flex items-baseline justify-between">
                                <span className="text-[0.8125rem] text-[var(--muted)]">Total</span>
                                <span className="tabular font-mono text-[1.375rem] font-semibold">{money(total)}</span>
                            </div>
                            <div className="mt-3">
                                <Chips options={methods.map((name) => ({ value: name, label: name }))} value={method} onChange={setMethod} />
                            </div>
                            <button
                                type="button"
                                onClick={charge}
                                className="rounded-control mt-3 h-11 w-full bg-[var(--accent)] text-[0.9375rem] font-semibold text-[var(--accent-ink)] hover:opacity-90"
                            >
                                Cobrar {money(total)}
                            </button>
                        </div>
                    </>
                )}
            </Panel>
        </div>
    );
}

function QuantityButton({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={onClick}
            className="rounded-chip grid size-7 place-items-center border border-[var(--line)] hover:bg-[var(--surface-2)]"
        >
            {children}
        </button>
    );
}

/* ---------- Pedidos en línea ---------- */

const ORDER_FLOW = [
    { status: 'new', label: 'Nuevos', action: 'Alistar' },
    { status: 'picking', label: 'Alistando', action: 'Despachar' },
    { status: 'shipping', label: 'En camino', action: 'Entregar' },
    { status: 'delivered', label: 'Entregados', action: null },
] as const;

function OrdersDemo({ data, notify }: { data: Record<string, unknown>; notify: (message: string) => void }) {
    const initial = list<{ number: string; customer: string; items: number; total: number; status: string; delivery: string; slot: string }>(
        data.orders,
    );
    const [statuses, setStatuses] = useState<Record<string, string>>(() => Object.fromEntries(initial.map((order) => [order.number, order.status])));

    const advance = (orderNumber: string) => {
        const position = ORDER_FLOW.findIndex((step) => step.status === statuses[orderNumber]);
        const next = ORDER_FLOW[position + 1];

        if (next) {
            setStatuses((current) => ({ ...current, [orderNumber]: next.status }));
            notify(`Pedido ${orderNumber}: ${next.label.toLowerCase()}. En la demostración no se avisa a nadie.`);
        }
    };

    return (
        <div className="-mx-4 flex gap-3 overflow-x-auto px-4 pb-2 md:mx-0 md:grid md:grid-cols-4 md:px-0">
            {ORDER_FLOW.map((column) => {
                const orders = initial.filter((order) => statuses[order.number] === column.status);

                return (
                    <div key={column.status} className="rounded-card w-60 shrink-0 bg-[var(--surface-2)] p-2.5 md:w-auto">
                        <p className="mb-2 flex items-center justify-between px-1 text-[0.75rem] font-semibold tracking-wide text-[var(--muted)] uppercase">
                            {column.label}
                            <span className="rounded-full bg-[var(--surface)] px-2 py-0.5 font-mono">{orders.length}</span>
                        </p>
                        <div className="flex flex-col gap-2">
                            {orders.map((order) => (
                                <div
                                    key={order.number}
                                    className="animate-fade-in rounded-control border border-[var(--line)] bg-[var(--surface)] p-3 text-[0.8125rem]"
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="font-mono font-semibold text-[var(--accent)]">{order.number}</span>
                                        <span className="tabular font-mono">{money(order.total)}</span>
                                    </div>
                                    <p className="mt-1 truncate font-medium">{order.customer}</p>
                                    <p className="text-[0.6875rem] text-[var(--muted)]">
                                        {order.items} {order.items === 1 ? 'artículo' : 'artículos'} · {order.delivery}
                                    </p>
                                    <p className="text-[0.6875rem] text-[var(--muted)]">{order.slot}</p>
                                    {column.action && (
                                        <button
                                            type="button"
                                            onClick={() => advance(order.number)}
                                            className="rounded-chip mt-2 h-8 w-full bg-[var(--accent-soft)] text-[0.75rem] font-semibold text-[var(--accent)] hover:bg-[var(--accent)] hover:text-[var(--accent-ink)]"
                                        >
                                            {column.action}
                                        </button>
                                    )}
                                </div>
                            ))}
                            {orders.length === 0 && <p className="px-1 py-3 text-[0.75rem] text-[var(--muted)]">Nada por aquí.</p>}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

/* ---------- Artículos ---------- */

function ProductsDemo({ data, notify }: { data: Record<string, unknown>; notify: (message: string) => void }) {
    const products = list<DemoProduct>(data.products);
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');
    const [off, setOff] = useState<Record<number, boolean>>({});
    const shown = products.filter(
        (product) =>
            (category === 'all' || product.category === category) &&
            `${product.name} ${product.sku}`.toLowerCase().includes(search.trim().toLowerCase()),
    );

    const toggle = (product: DemoProduct) => {
        const nextOff = !off[product.id];
        setOff((current) => ({ ...current, [product.id]: nextOff }));
        notify(`${product.name} ${nextOff ? 'ya no se vende' : 'vuelve a venderse'} en la caja ni en la tienda (demostración).`);
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                <SearchBox value={search} onChange={setSearch} placeholder="Nombre o código" />
                <p className="self-center text-[0.75rem] text-[var(--muted)]">{shown.length} artículos</p>
            </div>
            <Chips
                options={[{ value: 'all', label: 'Todas' }, ...list<string>(data.categories).map((name) => ({ value: name, label: name }))]}
                value={category}
                onChange={setCategory}
            />
            <div className="rounded-card overflow-hidden border border-[var(--line)]">
                {shown.map((product) => (
                    <div
                        key={product.id}
                        className={cn(
                            'grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 border-b border-[var(--line)] px-4 py-2.5 last:border-b-0 sm:grid-cols-[1.6fr_1fr_0.8fr_1fr_auto]',
                            off[product.id] && 'opacity-50',
                        )}
                    >
                        <span className="min-w-0">
                            <span className="block truncate text-[0.8125rem] font-medium">{product.name}</span>
                            <span className="block font-mono text-[0.6875rem] text-[var(--muted)]">{product.sku}</span>
                        </span>
                        <span className="hidden truncate text-[0.75rem] text-[var(--muted)] sm:block">{product.category}</span>
                        <span className="tabular hidden font-mono text-[0.8125rem] sm:block">{money(product.price)}</span>
                        <span className="hidden sm:block">
                            <StockMeter stock={product.stock} min={product.min_stock} />
                            <span className="mt-0.5 block font-mono text-[0.6875rem] text-[var(--muted)]">
                                {number(product.stock)} {product.unit}
                            </span>
                        </span>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={!off[product.id]}
                            aria-label={`Vender ${product.name}`}
                            onClick={() => toggle(product)}
                            className={cn(
                                'relative h-5 w-9 rounded-full transition-colors',
                                off[product.id] ? 'bg-[var(--line-2)]' : 'bg-[var(--accent)]',
                            )}
                        >
                            <span
                                className={cn(
                                    'absolute top-0.5 size-4 rounded-full bg-white shadow transition-[left]',
                                    off[product.id] ? 'left-0.5' : 'left-[1.125rem]',
                                )}
                            />
                        </button>
                    </div>
                ))}
                {shown.length === 0 && <p className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">Ningún artículo coincide.</p>}
            </div>
        </div>
    );
}

/* ---------- Inventario ---------- */

function InventoryDemo({ data, notify }: { data: Record<string, unknown>; notify: (message: string) => void }) {
    const products = list<DemoProduct>(data.products);
    const [filter, setFilter] = useState<'all' | 'low' | 'out'>('all');
    const [ordered, setOrdered] = useState<Record<number, boolean>>({});
    const low = products.filter((product) => product.stock > 0 && product.stock <= product.min_stock);
    const out = products.filter((product) => product.stock === 0);
    const value = products.reduce((sum, product) => sum + product.cost * product.stock, 0);
    const shown = filter === 'low' ? low : filter === 'out' ? out : products;

    const order = (product: DemoProduct) => {
        setOrdered((current) => ({ ...current, [product.id]: true }));
        notify(`${product.name} quedó en la orden de compra de la demostración.`);
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <Stat label="Referencias" value={number(products.length)} />
                <Stat label="Bajo el mínimo" value={number(low.length)} tone="warn" />
                <Stat label="Agotados" value={number(out.length)} tone="danger" />
                <Stat label="Valor al costo" value={money(value)} />
            </div>
            <Chips
                options={[
                    { value: 'all', label: 'Todo' },
                    { value: 'low', label: `Bajo el mínimo (${low.length})` },
                    { value: 'out', label: `Agotados (${out.length})` },
                ]}
                value={filter}
                onChange={setFilter}
            />
            <div className="rounded-card overflow-hidden border border-[var(--line)]">
                {shown.map((product) => {
                    const needs = product.stock <= product.min_stock;

                    return (
                        <div
                            key={product.id}
                            className="grid grid-cols-[1fr_auto] items-center gap-4 border-b border-[var(--line)] px-4 py-2.5 last:border-b-0 sm:grid-cols-[1.6fr_1.2fr_auto]"
                        >
                            <span className="min-w-0">
                                <span className="block truncate text-[0.8125rem] font-medium">{product.name}</span>
                                <span className="block text-[0.6875rem] text-[var(--muted)]">
                                    Mínimo {number(product.min_stock)} {product.unit}
                                </span>
                            </span>
                            <span className="hidden sm:block">
                                <StockMeter stock={product.stock} min={product.min_stock} />
                                <span className="mt-0.5 block font-mono text-[0.6875rem] text-[var(--muted)]">
                                    {number(product.stock)} {product.unit}
                                </span>
                            </span>
                            {needs ? (
                                ordered[product.id] ? (
                                    <span className="inline-flex items-center gap-1 text-[0.75rem] font-medium text-[#22A06B]">
                                        <CheckCircleIcon size={15} weight="fill" /> En la orden
                                    </span>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => order(product)}
                                        className="rounded-chip h-8 bg-[var(--accent-soft)] px-3 text-[0.75rem] font-semibold text-[var(--accent)] hover:bg-[var(--accent)] hover:text-[var(--accent-ink)]"
                                    >
                                        Pedir
                                    </button>
                                )
                            ) : (
                                <span className="text-[0.75rem] text-[var(--muted)]">Al día</span>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: 'warn' | 'danger' }) {
    return (
        <Panel>
            <p className="text-[0.6875rem] font-semibold tracking-wide text-[var(--muted)] uppercase">{label}</p>
            <p
                className={cn(
                    'tabular mt-1 font-mono text-[1.125rem] font-semibold',
                    tone === 'warn' && 'text-[var(--warn)]',
                    tone === 'danger' && 'text-[var(--danger)]',
                )}
            >
                {value}
            </p>
        </Panel>
    );
}

/* ---------- Clientes ---------- */

function CustomersDemo({ data }: { data: Record<string, unknown> }) {
    const customers = list<{
        id: number;
        name: string;
        email: string;
        purchases: number;
        spent: number;
        points: number;
        last: string;
        channel: string;
    }>(data.customers);
    const [search, setSearch] = useState('');
    const shown = customers.filter((customer) => `${customer.name} ${customer.email}`.toLowerCase().includes(search.trim().toLowerCase()));

    return (
        <div className="flex flex-col gap-3">
            <SearchBox value={search} onChange={setSearch} placeholder="Nombre o correo" />
            <div className="rounded-card overflow-hidden border border-[var(--line)]">
                {shown.map((customer) => (
                    <div
                        key={customer.id}
                        className="grid grid-cols-[auto_1fr_auto] items-center gap-3 border-b border-[var(--line)] px-4 py-2.5 last:border-b-0 sm:grid-cols-[auto_1.6fr_0.8fr_1fr_1fr]"
                    >
                        <span className="grid size-8 place-items-center rounded-full bg-[var(--accent-soft)] text-[0.6875rem] font-bold text-[var(--accent)]">
                            {customer.name
                                .split(' ')
                                .slice(0, 2)
                                .map((word) => word[0])
                                .join('')}
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-[0.8125rem] font-medium">{customer.name}</span>
                            <span className="block truncate text-[0.6875rem] text-[var(--muted)]">{customer.email}</span>
                        </span>
                        <span className="hidden text-[0.75rem] text-[var(--muted)] sm:block">{customer.purchases} compras</span>
                        <span className="tabular hidden font-mono text-[0.8125rem] sm:block">{money(customer.spent)}</span>
                        <span className="text-right text-[0.6875rem] text-[var(--muted)]">
                            <span className="block">{customer.channel}</span>
                            <span className="block">{customer.last}</span>
                        </span>
                    </div>
                ))}
                {shown.length === 0 && <p className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">Ningún cliente coincide.</p>}
            </div>
        </div>
    );
}

/* ---------- Ingresos y gastos ---------- */

const CONCEPT_COLORS = ['#E5484D', '#D6409F', '#8E4EC6', '#12A594', '#F5A524', '#3E63DD'];

function FinanceDemo({ data }: { data: Record<string, unknown> }) {
    const months = list<{ label: string; income: number; expenses: number }>(data.months);
    const totals = (data.totals ?? {}) as {
        income: number;
        expenses: number;
        profit: number;
        margin: number;
        income_change: number;
        expenses_change: number;
        profit_change: number;
    };
    const concepts = list<{ label: string; value: number }>(data.concepts);
    const peak = Math.max(1, ...months.map((month) => Math.max(month.income, month.expenses)));
    const biggest = Math.max(1, ...concepts.map((concept) => concept.value));
    const [picked, setPicked] = useState(months.length - 1);
    const month = months[picked];

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-3 sm:grid-cols-3">
                <FinanceCard label="Ingresos" value={totals.income} change={totals.income_change} note="últimos 6 meses" color="#22A06B" />
                <FinanceCard label="Gastos" value={totals.expenses} change={totals.expenses_change} note="con soportes" color="var(--danger)" />
                <FinanceCard
                    label="Utilidad real"
                    value={totals.profit}
                    change={totals.profit_change}
                    note={`margen ${String(totals.margin).replace('.', ',')} %`}
                    color="var(--accent)"
                />
            </div>
            <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                <Panel title="Ingresos y gastos" subtitle="Toca un mes para ver su utilidad">
                    <p className="mb-3 text-[0.8125rem]">
                        <span className="text-[var(--muted)]">{month?.label}: utilidad </span>
                        <span className="tabular font-mono font-semibold">{money((month?.income ?? 0) - (month?.expenses ?? 0))}</span>
                    </p>
                    <div className="flex h-40 items-end gap-3">
                        {months.map((entry, index) => (
                            <button
                                key={entry.label}
                                type="button"
                                onClick={() => setPicked(index)}
                                aria-label={`${entry.label}: ingresos ${money(entry.income)}, gastos ${money(entry.expenses)}`}
                                className={cn(
                                    'rounded-chip flex h-full flex-1 flex-col items-center justify-end gap-1.5',
                                    index === picked && 'bg-[var(--surface-2)]',
                                )}
                            >
                                <span className="flex h-full w-full items-end justify-center gap-1 px-1">
                                    <span
                                        className="rounded-t-chip w-1/2 max-w-4 bg-[#22A06B]"
                                        style={{ height: `${(entry.income / peak) * 100}%` }}
                                    />
                                    <span
                                        className="rounded-t-chip w-1/2 max-w-4 bg-[var(--danger)]"
                                        style={{ height: `${(entry.expenses / peak) * 100}%` }}
                                    />
                                </span>
                                <span className="text-[0.6875rem] text-[var(--muted)]">{entry.label}</span>
                            </button>
                        ))}
                    </div>
                    <div className="mt-3 flex gap-4 text-[0.75rem] text-[var(--muted)]">
                        <span className="flex items-center gap-1.5">
                            <span className="size-2.5 rounded-full bg-[#22A06B]" /> Ingresos
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="size-2.5 rounded-full bg-[var(--danger)]" /> Gastos
                        </span>
                    </div>
                </Panel>
                <Panel title="Gastos por concepto" subtitle="Este mes">
                    <ul className="flex flex-col gap-3">
                        {concepts.map((concept, index) => (
                            <li key={concept.label} className="text-[0.8125rem]">
                                <span className="flex items-center gap-2">
                                    <span
                                        className="size-2 shrink-0 rounded-full"
                                        style={{ background: CONCEPT_COLORS[index % CONCEPT_COLORS.length] }}
                                    />
                                    <span className="min-w-0 flex-1 truncate">{concept.label}</span>
                                    <span className="tabular font-mono font-semibold">{money(concept.value)}</span>
                                </span>
                                <span className="mt-1 block h-1.5 overflow-hidden rounded-full bg-[var(--surface-2)]">
                                    <span
                                        className="block h-full rounded-full"
                                        style={{
                                            width: `${(concept.value / biggest) * 100}%`,
                                            background: CONCEPT_COLORS[index % CONCEPT_COLORS.length],
                                        }}
                                    />
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            </div>
        </div>
    );
}

function FinanceCard({ label, value, change, note, color }: { label: string; value: number; change: number; note: string; color: string }) {
    return (
        <div className="rounded-card relative overflow-hidden border border-[var(--line)] bg-[var(--surface)] p-4 pl-5">
            <span aria-hidden className="absolute inset-y-0 left-0 w-1" style={{ background: color }} />
            <p className="text-[0.6875rem] font-semibold tracking-wide text-[var(--muted)] uppercase">{label}</p>
            <p className="tabular mt-1 font-mono text-[1.25rem] font-semibold">{money(value)}</p>
            <p className="mt-1.5 flex items-center gap-2 text-[0.6875rem] text-[var(--muted)]">
                <Change value={change} /> {note}
            </p>
        </div>
    );
}
