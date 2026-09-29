import { Button } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { date, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { PageProps, Paginated, SelectOption } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { MagnifyingGlassIcon, PencilSimpleIcon, PlusIcon, TagIcon, TrashIcon, XIcon } from '@phosphor-icons/react';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';

type PromotionType = 'special_price' | 'percent_off' | 'n_for_m' | 'bundle';

interface Target {
    /** `family`: un artículo con subartículos; alcanza a todos sus subartículos. */
    target_type: 'product' | 'category' | 'family';
    target_id: number;
    value: number | null;
    quantity: number;
    label: string;
    price: number | null;
}

interface PromotionRow {
    id: number;
    name: string;
    type: PromotionType;
    type_label: string;
    badge: string;
    label: string | null;
    color: string | null;
    starts_at: string | null;
    ends_at: string | null;
    applies_online: boolean;
    applies_pos: boolean;
    priority: number;
    config: { percent?: number; buy?: number; pay?: number; price?: number };
    is_active: boolean;
    is_running: boolean;
    targets_count: number;
    targets: Target[];
}

interface ProductHit {
    target_type?: 'product' | 'family';
    id: number;
    name: string;
    sku: string;
    price: number;
    online_price: number | null;
}

interface PromotionsIndexProps {
    promotions: Paginated<PromotionRow>;
    filters: { search: string; type: string; status: string };
    metrics: { running: number; scheduled: number; ended: number };
    types: SelectOption[];
    categories: SelectOption[];
}

interface FormState {
    name: string;
    type: PromotionType;
    label: string;
    priority: string;
    starts_at: string;
    ends_at: string;
    applies_online: boolean;
    applies_pos: boolean;
    is_active: boolean;
    percent: string;
    buy: string;
    pay: string;
    bundle_price: string;
    targets: Target[];
}

const EMPTY: FormState = {
    name: '',
    type: 'special_price',
    label: '',
    priority: '0',
    starts_at: '',
    ends_at: '',
    applies_online: true,
    applies_pos: false,
    is_active: true,
    percent: '',
    buy: '3',
    pay: '2',
    bundle_price: '',
    targets: [],
};

/** Los tipos que se arman con articulos concretos; el porcentaje admite categorias. */
const PRODUCT_ONLY: PromotionType[] = ['special_price', 'n_for_m', 'bundle'];

export default function PromotionsIndex({ promotions, filters: initialFilters, metrics, types, categories }: PromotionsIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('promotions.index', initialFilters);

    const [editing, setEditing] = useState<PromotionRow | null>(null);
    const [open, setOpen] = useState(false);
    const [removing, setRemoving] = useState<PromotionRow | null>(null);

    // El formulario se lleva a mano: `targets` es una lista de objetos y el `useForm` de
    // Inertia solo admite valores planos.
    const [data, setForm] = useState<FormState>({ ...EMPTY });
    const [processing, setProcessing] = useState(false);
    const errors = usePage<PageProps>().props.errors ?? {};

    const setData = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((current) => ({ ...current, [key]: value }));

    const openCreate = () => {
        setEditing(null);
        setForm({ ...EMPTY });
        setOpen(true);
    };

    const openEdit = (promotion: PromotionRow) => {
        setEditing(promotion);
        setForm({
            name: promotion.name,
            type: promotion.type,
            label: promotion.label ?? '',
            priority: String(promotion.priority),
            starts_at: promotion.starts_at ?? '',
            ends_at: promotion.ends_at ?? '',
            applies_online: promotion.applies_online,
            applies_pos: promotion.applies_pos,
            is_active: promotion.is_active,
            percent: promotion.config.percent ? String(promotion.config.percent) : '',
            buy: promotion.config.buy ? String(promotion.config.buy) : '3',
            pay: promotion.config.pay ? String(promotion.config.pay) : '2',
            bundle_price: promotion.config.price ? String(promotion.config.price) : '',
            targets: promotion.targets,
        });
        setOpen(true);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true);

        const payload = {
            ...data,
            targets: data.targets.map((target) => ({
                target_type: target.target_type,
                target_id: target.target_id,
                value: target.value,
                quantity: target.quantity,
            })),
        };

        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onFinish: () => setProcessing(false),
        };

        if (editing) {
            router.put(route('promotions.update', editing.id), payload, options);
        } else {
            router.post(route('promotions.store'), payload, options);
        }
    };

    const addTarget = (target: Target) => {
        if (data.targets.some((item) => item.target_type === target.target_type && item.target_id === target.target_id)) {
            return;
        }

        setData('targets', [...data.targets, target]);
    };

    const removeTarget = (index: number) => {
        setData(
            'targets',
            data.targets.filter((_, position) => position !== index),
        );
    };

    const setTargetValue = (index: number, key: 'value' | 'quantity', value: number) => {
        setData(
            'targets',
            data.targets.map((target, position) => (position === index ? { ...target, [key]: value } : target)),
        );
    };

    return (
        <AppLayout title="Promociones">
            <PageHeader
                title="Promociones"
                description="Precios especiales, descuentos por categoria, 3x2 y combos, con su vigencia y su canal."
                actions={
                    can('promotions.index.create') ? (
                        <Button onClick={openCreate} icon={<PlusIcon size={16} weight="bold" />}>
                            Nueva promocion
                        </Button>
                    ) : undefined
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <StatCard label="Vigentes hoy" value={number(metrics.running)} icon={<TagIcon size={16} weight="bold" />} />
                <StatCard label="Programadas" value={number(metrics.scheduled)} tone="warn" />
                <StatCard label="Terminadas" value={number(metrics.ended)} tone="neutral" />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    <SearchInput
                        placeholder="Nombre de la promocion"
                        value={filters.search}
                        onChange={(event) => update('search', event.target.value)}
                    />
                    <Select
                        options={types}
                        placeholder="Todos los tipos"
                        value={filters.type}
                        onChange={(event) => update('type', event.target.value)}
                    />
                    <div className="flex items-start gap-2">
                        <div className="flex-1">
                            <Select
                                options={[
                                    { value: 'running', label: 'Vigentes' },
                                    { value: 'off', label: 'Apagadas' },
                                    { value: 'ended', label: 'Terminadas' },
                                ]}
                                placeholder="Cualquier estado"
                                value={filters.status}
                                onChange={(event) => update('status', event.target.value)}
                            />
                        </div>
                        {hasActive && (
                            <Button variant="ghost" onClick={reset}>
                                Limpiar
                            </Button>
                        )}
                    </div>
                </div>
            </Card>

            {promotions.data.length === 0 ? (
                <EmptyState
                    title="Todavia no hay promociones"
                    description="Crea la primera y aparecera en la tienda apenas empiece su vigencia."
                    icon={<TagIcon size={24} />}
                    action={
                        can('promotions.index.create') ? (
                            <Button onClick={openCreate} icon={<PlusIcon size={16} weight="bold" />}>
                                Nueva promocion
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Promocion</TH>
                                <TH>Tipo</TH>
                                <TH>Aplica en</TH>
                                <TH>Vigencia</TH>
                                <TH align="center">Alcance</TH>
                                <TH align="center">Estado</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {promotions.data.map((promotion) => (
                                <TR key={promotion.id}>
                                    <TD>
                                        <div className="flex items-center gap-2">
                                            <span className="rounded-chip bg-[var(--accent)] px-1.5 py-0.5 font-mono text-[0.6875rem] font-semibold text-[var(--accent-ink)]">
                                                {promotion.badge}
                                            </span>
                                            <span className="text-[0.84375rem] font-medium text-[var(--text)]">{promotion.name}</span>
                                        </div>
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{promotion.type_label}</TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">
                                        {[promotion.applies_online && 'Tienda', promotion.applies_pos && 'Mostrador'].filter(Boolean).join(' y ')}
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">
                                        {promotion.starts_at || promotion.ends_at
                                            ? `${promotion.starts_at ? date(promotion.starts_at) : 'ya'} → ${promotion.ends_at ? date(promotion.ends_at) : 'sin fin'}`
                                            : 'Siempre'}
                                    </TD>
                                    <TD align="center" className="tabular font-mono text-[0.8125rem]">
                                        {promotion.targets_count}
                                    </TD>
                                    <TD align="center">
                                        <Badge tone={promotion.is_running ? 'accent' : promotion.is_active ? 'warn' : 'neutral'}>
                                            {promotion.is_running ? 'Vigente' : promotion.is_active ? 'Programada' : 'Apagada'}
                                        </Badge>
                                    </TD>
                                    <TD align="right">
                                        <div className="flex items-center justify-end gap-1">
                                            {can('promotions.index.edit') && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.patch(route('promotions.toggle', promotion.id), {}, { preserveScroll: true })
                                                        }
                                                        title={promotion.is_active ? 'Apagar' : 'Encender'}
                                                        className="rounded-chip px-2 py-1 text-[0.75rem] text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                    >
                                                        {promotion.is_active ? 'Apagar' : 'Encender'}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => openEdit(promotion)}
                                                        aria-label="Editar"
                                                        className="rounded-chip grid size-8 place-items-center text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                    >
                                                        <PencilSimpleIcon size={15} />
                                                    </button>
                                                </>
                                            )}
                                            {can('promotions.index.delete') && (
                                                <button
                                                    type="button"
                                                    onClick={() => setRemoving(promotion)}
                                                    aria-label="Eliminar"
                                                    className="rounded-chip grid size-8 place-items-center text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                                >
                                                    <TrashIcon size={15} />
                                                </button>
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={promotions} />
                </>
            )}

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={editing ? 'Editar la promocion' : 'Nueva promocion'}
                description="El cliente ve la etiqueta sobre el articulo y el precio anterior tachado."
                size="xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Cancelar
                        </Button>
                        <Button onClick={submit} loading={processing}>
                            {editing ? 'Guardar' : 'Crear la promocion'}
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="grid gap-4 lg:grid-cols-2">
                    <div className="flex flex-col gap-3">
                        <Input
                            label="Nombre"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            error={errors.name}
                            placeholder="Semana de la despensa"
                        />

                        <Select
                            label="Tipo"
                            options={types}
                            value={data.type}
                            onChange={(event) => {
                                const next = event.target.value as PromotionType;
                                setForm((current) => ({ ...current, type: next, targets: [] }));
                            }}
                            error={errors.type}
                        />

                        {data.type === 'percent_off' && (
                            <Input
                                label="Descuento"
                                type="number"
                                min={0.1}
                                max={100}
                                step={0.1}
                                suffix="%"
                                value={data.percent}
                                onChange={(event) => setData('percent', event.target.value)}
                                error={errors.percent}
                            />
                        )}

                        {data.type === 'n_for_m' && (
                            <div className="grid grid-cols-2 gap-3">
                                <Input
                                    label="Lleva"
                                    type="number"
                                    min={2}
                                    value={data.buy}
                                    onChange={(event) => setData('buy', event.target.value)}
                                    error={errors.buy}
                                />
                                <Input
                                    label="Paga"
                                    type="number"
                                    min={1}
                                    value={data.pay}
                                    onChange={(event) => setData('pay', event.target.value)}
                                    error={errors.pay}
                                />
                            </div>
                        )}

                        {data.type === 'bundle' && (
                            <Input
                                label="Precio del combo"
                                type="number"
                                min={1}
                                prefix="$"
                                value={data.bundle_price}
                                onChange={(event) => setData('bundle_price', event.target.value)}
                                error={errors.bundle_price}
                                hint="Lo que paga el cliente por llevarse todos los articulos juntos."
                            />
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                label="Desde"
                                type="date"
                                value={data.starts_at}
                                onChange={(event) => setData('starts_at', event.target.value)}
                                error={errors.starts_at}
                                hint="Vacio: desde ya."
                            />
                            <Input
                                label="Hasta"
                                type="date"
                                value={data.ends_at}
                                onChange={(event) => setData('ends_at', event.target.value)}
                                error={errors.ends_at}
                                hint="Vacio: sin fin."
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                label="Etiqueta"
                                value={data.label}
                                onChange={(event) => setData('label', event.target.value)}
                                error={errors.label}
                                hint="Vacio: se arma sola."
                                placeholder="-20%"
                            />
                            <Input
                                label="Prioridad"
                                type="number"
                                min={0}
                                value={data.priority}
                                onChange={(event) => setData('priority', event.target.value)}
                                error={errors.priority}
                                hint="Manda la mas alta."
                            />
                        </div>

                        <div className="rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-3">
                            <p className="mb-2 text-[0.75rem] font-semibold tracking-wide text-[var(--muted)] uppercase">Donde aplica</p>
                            <div className="flex flex-col gap-2">
                                <Checkbox
                                    label="Tienda en linea"
                                    checked={data.applies_online}
                                    onChange={(event) => setData('applies_online', event.target.checked)}
                                />
                                <Checkbox
                                    label="Mostrador (punto de venta)"
                                    hint="El cajero cobrara el precio promocional."
                                    checked={data.applies_pos}
                                    onChange={(event) => setData('applies_pos', event.target.checked)}
                                />
                                <Checkbox
                                    label="Encendida"
                                    checked={data.is_active}
                                    onChange={(event) => setData('is_active', event.target.checked)}
                                />
                            </div>
                            {errors.applies_online && <p className="mt-2 text-[0.75rem] font-medium text-[var(--danger)]">{errors.applies_online}</p>}
                        </div>
                    </div>

                    <TargetPicker
                        type={data.type}
                        targets={data.targets}
                        categories={categories}
                        error={errors.targets}
                        onAdd={addTarget}
                        onRemove={removeTarget}
                        onValue={setTargetValue}
                    />
                </form>
            </Modal>

            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (removing) {
                        router.delete(route('promotions.destroy', removing.id), { preserveScroll: true });
                    }
                    setRemoving(null);
                }}
                title="Eliminar la promocion"
                message={`«${removing?.name}» dejara de aplicar de inmediato. Las ventas ya hechas no cambian.`}
                confirmLabel="Eliminar"
                tone="danger"
            />
        </AppLayout>
    );
}

interface TargetPickerProps {
    type: PromotionType;
    targets: Target[];
    categories: SelectOption[];
    error?: string;
    onAdd: (target: Target) => void;
    onRemove: (index: number) => void;
    onValue: (index: number, key: 'value' | 'quantity', value: number) => void;
}

/** Elige a que aplica la promocion: articulos buscados uno a uno, o una categoria entera. */
function TargetPicker({ type, targets, categories, error, onAdd, onRemove, onValue }: TargetPickerProps) {
    const [search, setSearch] = useState('');
    const [hits, setHits] = useState<ProductHit[]>([]);
    const [searching, setSearching] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const allowsCategories = type === 'percent_off';

    const lookup = useCallback((term: string) => {
        setSearching(true);

        fetch(`${route('promotions.products')}?search=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : []))
            .then((rows: ProductHit[]) => setHits(rows))
            .catch(() => setHits([]))
            .finally(() => setSearching(false));
    }, []);

    useEffect(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => lookup(search), 300);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [search, lookup]);

    return (
        <div className="flex flex-col gap-3">
            <div>
                <p className="mb-1.5 text-[0.75rem] font-semibold tracking-wide text-[var(--muted)] uppercase">
                    {allowsCategories ? 'Articulos o categorias' : 'Articulos'}
                </p>

                {allowsCategories && (
                    <Select
                        options={categories}
                        placeholder="Agregar una categoria entera"
                        value=""
                        onChange={(event) => {
                            const id = Number(event.target.value);
                            const found = categories.find((option) => Number(option.value) === id);

                            if (found) {
                                onAdd({
                                    target_type: 'category',
                                    target_id: id,
                                    value: null,
                                    quantity: 1,
                                    label: found.label,
                                    price: null,
                                });
                            }
                        }}
                        className="mb-2"
                    />
                )}

                <div className="relative">
                    <MagnifyingGlassIcon size={16} className="pointer-events-none absolute top-3 left-3 text-[var(--muted)]" />
                    <input
                        type="text"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Buscar un articulo por nombre o SKU"
                        className="rounded-control h-10 w-full border border-[var(--line)] bg-[var(--surface-2)] pr-3 pl-9 text-[0.84375rem] focus:border-[var(--accent)] focus:outline-none"
                    />
                </div>

                <ul className="rounded-control mt-1.5 max-h-[10.5rem] overflow-y-auto border border-[var(--line)]">
                    {searching && <li className="px-3 py-2 text-[0.78125rem] text-[var(--muted)]">Buscando…</li>}
                    {!searching && hits.length === 0 && (
                        <li className="px-3 py-2 text-[0.78125rem] text-[var(--muted)]">Ningun articulo coincide.</li>
                    )}
                    {hits
                        // Un combo se arma con artículos puntuales, no con todas las tallas de uno.
                        .filter((hit) => type !== 'bundle' || hit.target_type !== 'family')
                        .map((hit) => (
                            <li key={`${hit.target_type ?? 'product'}-${hit.id}`}>
                                <button
                                    type="button"
                                    onClick={() =>
                                        onAdd({
                                            target_type: hit.target_type ?? 'product',
                                            target_id: hit.id,
                                            value: type === 'special_price' ? (hit.online_price ?? hit.price) : null,
                                            quantity: 1,
                                            label: hit.name,
                                            price: hit.online_price ?? hit.price,
                                        })
                                    }
                                    className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-[0.8125rem] hover:bg-[var(--surface-2)]"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-[var(--text)]">
                                            {hit.name}
                                            {hit.target_type === 'family' && (
                                                <span className="ml-1.5 text-[0.6875rem] font-semibold text-[var(--accent)]">
                                                    todas sus presentaciones
                                                </span>
                                            )}
                                        </span>
                                        <span className="block truncate font-mono text-[0.6875rem] text-[var(--muted)]">{hit.sku}</span>
                                    </span>
                                    <span className="tabular shrink-0 font-mono text-[0.78125rem] text-[var(--muted)]">
                                        {money(hit.online_price ?? hit.price)}
                                    </span>
                                </button>
                            </li>
                        ))}
                </ul>
            </div>

            <div className="min-h-0 flex-1">
                <div className="mb-1.5 flex items-baseline justify-between">
                    <p className="text-[0.75rem] font-semibold tracking-wide text-[var(--muted)] uppercase">Elegidos</p>
                    <span className="tabular font-mono text-[0.71875rem] text-[var(--muted)]">{targets.length}</span>
                </div>

                {targets.length === 0 ? (
                    <p className="rounded-control border border-dashed border-[var(--line-2)] px-3 py-5 text-center text-[0.78125rem] text-[var(--muted)]">
                        Todavia no has elegido nada.
                    </p>
                ) : (
                    <ul className="flex max-h-[16.25rem] flex-col gap-1.5 overflow-y-auto">
                        {targets.map((target, index) => (
                            <li
                                key={`${target.target_type}-${target.target_id}`}
                                className={cn(
                                    'rounded-control flex items-center gap-2 border px-3 py-2',
                                    target.target_type === 'category'
                                        ? 'border-[var(--accent)]/40 bg-[var(--accent-soft)]'
                                        : 'border-[var(--line)] bg-[var(--surface-2)]',
                                )}
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[0.8125rem] text-[var(--text)]">{target.label}</span>
                                    <span className="block text-[0.6875rem] text-[var(--muted)]">
                                        {target.target_type === 'category'
                                            ? 'Categoria completa'
                                            : target.target_type === 'family'
                                              ? 'Todos sus subartículos: sus unidades cuentan juntas'
                                              : target.price
                                                ? `Antes ${money(target.price)}`
                                                : 'Articulo'}
                                    </span>
                                </span>

                                {type === 'special_price' && target.target_type !== 'category' && (
                                    <input
                                        type="number"
                                        min={0}
                                        value={target.value ?? ''}
                                        onChange={(event) => onValue(index, 'value', Number(event.target.value))}
                                        aria-label={`Precio promocional de ${target.label}`}
                                        className="rounded-chip tabular h-9 w-28 border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none"
                                    />
                                )}

                                {type === 'bundle' && (
                                    <input
                                        type="number"
                                        min={1}
                                        step={1}
                                        value={target.quantity}
                                        onChange={(event) => onValue(index, 'quantity', Number(event.target.value))}
                                        aria-label={`Cuantas unidades de ${target.label}`}
                                        className="rounded-chip tabular h-9 w-16 border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none"
                                    />
                                )}

                                <button
                                    type="button"
                                    onClick={() => onRemove(index)}
                                    aria-label={`Quitar ${target.label}`}
                                    className="rounded-chip grid size-7 shrink-0 place-items-center text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                >
                                    <XIcon size={14} />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {error && <p className="mt-2 text-[0.75rem] font-medium text-[var(--danger)]">{error}</p>}

                {type === 'bundle' && targets.length > 0 && (
                    <p className="rounded-chip mt-2 bg-[var(--surface-2)] px-2.5 py-1.5 text-[0.75rem] text-[var(--muted)]">
                        Por separado costarian{' '}
                        <span className="tabular font-mono">
                            {money(targets.reduce((sum, target) => sum + (target.price ?? 0) * target.quantity, 0))}
                        </span>
                        .
                    </p>
                )}
            </div>

            {PRODUCT_ONLY.includes(type) && (
                <p className="text-[0.71875rem] text-[var(--muted)]">
                    {type === 'special_price'
                        ? 'Escribe el precio promocional de cada articulo.'
                        : type === 'bundle'
                          ? 'El combo se arma cuando el cliente lleva todos los articulos en las cantidades indicadas.'
                          : 'Las unidades que se regalan son las mas baratas del grupo.'}
                </p>
            )}
        </div>
    );
}
