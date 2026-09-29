import { router, usePage } from '@inertiajs/react';
import { PencilSimpleIcon, PlusIcon, TicketIcon, TrashIcon, XIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { date, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { BadgeTone } from '@/components/ui/Misc';
import type { PageProps, Paginated, SelectOption } from '@/types';

interface CouponRow {
    id: number;
    code: string;
    type: 'percent' | 'amount' | 'free_delivery';
    type_label: string;
    value: number;
    min_order_total: number;
    max_discount: number | null;
    scope: 'all' | 'category' | 'product';
    scope_ids: number[];
    starts_at: string | null;
    ends_at: string | null;
    usage_limit: number | null;
    usage_limit_per_customer: number | null;
    first_order_only: boolean;
    is_active: boolean;
    storefront_id: number | null;
    store: string | null;
    used: number;
    status: string;
    scope_names: Record<string, string>;
}

interface ProductHit {
    id: number;
    name: string;
    sku: string;
}

interface CouponsIndexProps {
    coupons: Paginated<CouponRow>;
    filters: { search: string; status: string };
    metrics: { active: number; used: number };
    types: SelectOption[];
    scopes: SelectOption[];
    categories: SelectOption[];
    storefronts: SelectOption[];
}

interface FormState {
    code: string;
    type: 'percent' | 'amount' | 'free_delivery';
    value: string;
    min_order_total: string;
    max_discount: string;
    scope: 'all' | 'category' | 'product';
    scope_ids: number[];
    storefront_id: string;
    starts_at: string;
    ends_at: string;
    usage_limit: string;
    usage_limit_per_customer: string;
    first_order_only: boolean;
    is_active: boolean;
}

const EMPTY: FormState = {
    code: '',
    type: 'percent',
    value: '10',
    min_order_total: '0',
    max_discount: '',
    scope: 'all',
    scope_ids: [],
    storefront_id: '',
    starts_at: '',
    ends_at: '',
    usage_limit: '',
    usage_limit_per_customer: '',
    first_order_only: false,
    is_active: true,
};

const TONES: Record<string, BadgeTone> = {
    Activo: 'accent',
    Programado: 'info',
    Vencido: 'neutral',
    Agotado: 'warn',
    Apagado: 'neutral',
};

export default function CouponsIndex({ coupons, filters: initialFilters, metrics, types, scopes, categories, storefronts }: CouponsIndexProps) {
    const { can } = usePermission();
    const { filters, update } = useFilters('coupons.index', initialFilters);
    const errors = (usePage<PageProps>().props.errors ?? {}) as Record<string, string>;

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<CouponRow | null>(null);
    const [removing, setRemoving] = useState<CouponRow | null>(null);
    const [data, setForm] = useState<FormState>({ ...EMPTY });
    const [saving, setSaving] = useState(false);
    const [productNames, setProductNames] = useState<Record<number, string>>({});

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((current) => ({ ...current, [key]: value }));

    const openCreate = () => {
        setEditing(null);
        setForm({ ...EMPTY });
        setProductNames({});
        setOpen(true);
    };

    const openEdit = (coupon: CouponRow) => {
        setEditing(coupon);
        setProductNames(
            Object.fromEntries(Object.entries(coupon.scope_names).map(([id, name]) => [Number(id), name])),
        );
        setForm({
            code: coupon.code,
            type: coupon.type,
            value: String(coupon.value),
            min_order_total: String(coupon.min_order_total),
            max_discount: coupon.max_discount ? String(coupon.max_discount) : '',
            scope: coupon.scope,
            scope_ids: coupon.scope_ids,
            storefront_id: coupon.storefront_id ? String(coupon.storefront_id) : '',
            starts_at: coupon.starts_at ?? '',
            ends_at: coupon.ends_at ?? '',
            usage_limit: coupon.usage_limit ? String(coupon.usage_limit) : '',
            usage_limit_per_customer: coupon.usage_limit_per_customer ? String(coupon.usage_limit_per_customer) : '',
            first_order_only: coupon.first_order_only,
            is_active: coupon.is_active,
        });
        setOpen(true);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);

        const payload = {
            code: data.code,
            type: data.type,
            value: Number(data.value),
            min_order_total: Number(data.min_order_total) || 0,
            max_discount: data.max_discount === '' ? null : Number(data.max_discount),
            scope: data.scope,
            scope_ids: data.scope_ids,
            storefront_id: data.storefront_id === '' ? null : Number(data.storefront_id),
            starts_at: data.starts_at || null,
            ends_at: data.ends_at || null,
            usage_limit: data.usage_limit === '' ? null : Number(data.usage_limit),
            usage_limit_per_customer: data.usage_limit_per_customer === '' ? null : Number(data.usage_limit_per_customer),
            first_order_only: data.first_order_only,
            is_active: data.is_active,
        };

        const options = { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: () => setSaving(false) };

        if (editing) {
            router.put(route('coupons.update', editing.id), payload, options);
        } else {
            router.post(route('coupons.store'), payload, options);
        }
    };

    return (
        <AppLayout title="Cupones">
            <PageHeader
                title="Cupones"
                description="Códigos de descuento para la tienda en línea, con su vigencia y sus topes."
                actions={
                    can('coupons.index.create') ? (
                        <Button onClick={openCreate} icon={<PlusIcon size={16} weight="bold" />}>
                            Nuevo cupón
                        </Button>
                    ) : undefined
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2">
                <StatCard label="Activos hoy" value={number(metrics.active)} icon={<TicketIcon size={16} weight="bold" />} />
                <StatCard label="Veces usados" value={number(metrics.used)} tone="neutral" />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <SearchInput placeholder="Código" value={filters.search} onChange={(event) => update('search', event.target.value)} />
                    <Select
                        options={[
                            { value: 'active', label: 'Activos' },
                            { value: 'off', label: 'Apagados' },
                        ]}
                        placeholder="Cualquier estado"
                        value={filters.status}
                        onChange={(event) => update('status', event.target.value)}
                    />
                </div>
            </Card>

            {coupons.data.length === 0 ? (
                <EmptyState
                    title="Todavía no hay cupones"
                    description="Crea el primero y el cliente podrá escribirlo al pagar."
                    icon={<TicketIcon size={24} />}
                    action={
                        can('coupons.index.create') ? (
                            <Button onClick={openCreate} icon={<PlusIcon size={16} weight="bold" />}>
                                Nuevo cupón
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Código</TH>
                                <TH>Descuento</TH>
                                <TH>Condiciones</TH>
                                <TH>Vigencia</TH>
                                <TH align="center">Usos</TH>
                                <TH align="center">Estado</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {coupons.data.map((coupon) => (
                                <TR key={coupon.id}>
                                    <TD>
                                        <p className="font-mono text-[0.84375rem] font-semibold">{coupon.code}</p>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">{coupon.store ?? 'Todas las sedes'}</p>
                                    </TD>
                                    <TD className="text-[0.8125rem]">
                                        {coupon.type === 'percent'
                                            ? `${coupon.value}%`
                                            : coupon.type === 'amount'
                                              ? money(coupon.value)
                                              : 'Domicilio gratis'}
                                    </TD>
                                    <TD className="text-[0.75rem] text-[var(--muted)]">
                                        {coupon.min_order_total > 0 ? `Desde ${money(coupon.min_order_total)}` : 'Sin mínimo'}
                                        {coupon.first_order_only && ' · primer pedido'}
                                    </TD>
                                    <TD className="text-[0.75rem] text-[var(--muted)]">
                                        {coupon.starts_at || coupon.ends_at
                                            ? `${coupon.starts_at ? date(coupon.starts_at) : 'ya'} → ${coupon.ends_at ? date(coupon.ends_at) : 'sin fin'}`
                                            : 'Siempre'}
                                    </TD>
                                    <TD align="center" className="font-mono text-[0.8125rem] tabular">
                                        {coupon.used}
                                        {coupon.usage_limit ? ` / ${coupon.usage_limit}` : ''}
                                    </TD>
                                    <TD align="center">
                                        <Badge tone={TONES[coupon.status] ?? 'neutral'}>{coupon.status}</Badge>
                                    </TD>
                                    <TD align="right">
                                        <div className="flex items-center justify-end gap-1">
                                            {can('coupons.index.edit') && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() => router.patch(route('coupons.toggle', coupon.id), {}, { preserveScroll: true })}
                                                        className="min-h-[2.125rem] rounded-chip px-2 text-[0.75rem] text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                    >
                                                        {coupon.is_active ? 'Apagar' : 'Encender'}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => openEdit(coupon)}
                                                        aria-label="Editar"
                                                        className="grid size-9 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                    >
                                                        <PencilSimpleIcon size={15} />
                                                    </button>
                                                </>
                                            )}
                                            {can('coupons.index.delete') && (
                                                <button
                                                    type="button"
                                                    onClick={() => setRemoving(coupon)}
                                                    aria-label="Eliminar"
                                                    className="grid size-9 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
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

                    <Pagination meta={coupons} />
                </>
            )}

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={editing ? 'Editar el cupón' : 'Nuevo cupón'}
                description="El cliente lo escribe al pagar y el descuento se aplica al instante."
                size="lg"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Cancelar
                        </Button>
                        <Button loading={saving} onClick={submit}>
                            {editing ? 'Guardar' : 'Crear el cupón'}
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="grid gap-4 md:grid-cols-2">
                    <div className="flex flex-col gap-3">
                        <Input
                            label="Código"
                            value={data.code}
                            onChange={(event) => set('code', event.target.value.toUpperCase())}
                            error={errors.code}
                            placeholder="BIENVENIDO"
                            hint="Letras, números y guiones."
                        />

                        <Select
                            label="Tipo"
                            options={types}
                            value={data.type}
                            onChange={(event) => set('type', event.target.value as FormState['type'])}
                        />

                        {data.type !== 'free_delivery' && (
                            <Input
                                label={data.type === 'percent' ? 'Porcentaje' : 'Valor'}
                                type="number"
                                min={0}
                                max={data.type === 'percent' ? 100 : undefined}
                                prefix={data.type === 'amount' ? '$' : undefined}
                                suffix={data.type === 'percent' ? '%' : undefined}
                                value={data.value}
                                onChange={(event) => set('value', event.target.value)}
                                error={errors.value}
                            />
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                label="Pedido mínimo"
                                type="number"
                                min={0}
                                prefix="$"
                                value={data.min_order_total}
                                onChange={(event) => set('min_order_total', event.target.value)}
                                hint="0 = sin mínimo."
                            />
                            <Input
                                label="Tope del descuento"
                                type="number"
                                min={0}
                                prefix="$"
                                value={data.max_discount}
                                onChange={(event) => set('max_discount', event.target.value)}
                                hint="Vacío = sin tope."
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                label="Desde"
                                type="date"
                                value={data.starts_at}
                                onChange={(event) => set('starts_at', event.target.value)}
                            />
                            <Input
                                label="Hasta"
                                type="date"
                                value={data.ends_at}
                                onChange={(event) => set('ends_at', event.target.value)}
                                error={errors.ends_at}
                            />
                        </div>
                    </div>

                    <div className="flex flex-col gap-3">
                        <Select
                            label="A qué aplica"
                            options={scopes}
                            value={data.scope}
                            onChange={(event) => set('scope', event.target.value as FormState['scope'])}
                        />

                        {data.scope === 'category' && (
                            <div>
                                <p className="mb-1.5 text-[0.78125rem] font-medium">Categorías</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {categories.map((category) => {
                                        const id = Number(category.value);
                                        const on = data.scope_ids.includes(id);

                                        return (
                                            <button
                                                key={category.value}
                                                type="button"
                                                onClick={() =>
                                                    set(
                                                        'scope_ids',
                                                        on ? data.scope_ids.filter((item) => item !== id) : [...data.scope_ids, id],
                                                    )
                                                }
                                                className={`min-h-[2.25rem] rounded-control border px-2.5 text-[0.78125rem] font-medium ${
                                                    on
                                                        ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                        : 'border-[var(--line)] text-[var(--muted)]'
                                                }`}
                                            >
                                                {category.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                {errors.scope_ids && <p className="mt-1.5 text-[0.75rem] text-[var(--danger)]">{errors.scope_ids}</p>}
                            </div>
                        )}

                        {data.scope === 'product' && (
                            <ProductPicker
                                value={data.scope_ids}
                                names={productNames}
                                error={errors.scope_ids}
                                onNames={setProductNames}
                                onChange={(ids) => set('scope_ids', ids)}
                            />
                        )}

                        <Select
                            label="Sede"
                            options={storefronts}
                            placeholder="Todas las sedes"
                            value={data.storefront_id}
                            onChange={(event) => set('storefront_id', event.target.value)}
                        />

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                label="Usos totales"
                                type="number"
                                min={1}
                                value={data.usage_limit}
                                onChange={(event) => set('usage_limit', event.target.value)}
                                hint="Vacío = sin tope."
                            />
                            <Input
                                label="Usos por cliente"
                                type="number"
                                min={1}
                                value={data.usage_limit_per_customer}
                                onChange={(event) => set('usage_limit_per_customer', event.target.value)}
                                hint="Vacío = sin tope."
                            />
                        </div>

                        <div className="rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-3">
                            <div className="flex flex-col gap-2">
                                <Checkbox
                                    label="Solo para el primer pedido"
                                    checked={data.first_order_only}
                                    onChange={(event) => set('first_order_only', event.target.checked)}
                                />
                                <Checkbox
                                    label="Encendido"
                                    checked={data.is_active}
                                    onChange={(event) => set('is_active', event.target.checked)}
                                />
                            </div>
                        </div>
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (removing) {
                        router.delete(route('coupons.destroy', removing.id), { preserveScroll: true });
                    }
                    setRemoving(null);
                }}
                title="Eliminar el cupón"
                message={`«${removing?.code}» dejará de servir de inmediato. Los pedidos que ya lo usaron no cambian.`}
                confirmLabel="Eliminar"
                tone="danger"
            />
        </AppLayout>
    );
}

/** Buscador de articulos con los elegidos en fichas debajo. */
function ProductPicker({
    value,
    names,
    error,
    onChange,
    onNames,
}: {
    value: number[];
    names: Record<number, string>;
    error?: string;
    onChange: (value: number[]) => void;
    onNames: (updater: (current: Record<number, string>) => Record<number, string>) => void;
}) {
    const [search, setSearch] = useState('');
    const [hits, setHits] = useState<ProductHit[]>([]);

    useEffect(() => {
        const timer = setTimeout(() => {
            fetch(`${route('coupons.products')}?search=${encodeURIComponent(search)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((rows: ProductHit[]) => {
                    setHits(rows);
                    onNames((current) => {
                        const next = { ...current };
                        rows.forEach((row) => {
                            next[row.id] = row.name;
                        });

                        return next;
                    });
                })
                .catch(() => setHits([]));
        }, 300);

        return () => clearTimeout(timer);
    }, [search, onNames]);

    return (
        <div>
            <p className="mb-1.5 text-[0.78125rem] font-medium">Artículos</p>

            <input
                type="text"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Buscar por nombre o SKU"
                className="h-11 w-full rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] focus:border-[var(--accent)] focus:outline-none"
            />

            <ul className="mt-1.5 max-h-[7.75rem] overflow-y-auto rounded-control border border-[var(--line)]">
                {hits.length === 0 && <li className="px-3 py-2 text-[0.78125rem] text-[var(--muted)]">Ningún artículo coincide.</li>}
                {hits.map((hit) => (
                    <li key={hit.id}>
                        <button
                            type="button"
                            disabled={value.includes(hit.id)}
                            onClick={() => onChange([...value, hit.id])}
                            className="flex min-h-[2.375rem] w-full items-center justify-between px-3 py-1.5 text-left text-[0.78125rem] hover:bg-[var(--surface-2)] disabled:opacity-40"
                        >
                            <span className="truncate">{hit.name}</span>
                            <span className="ml-2 shrink-0 font-mono text-[0.6875rem] text-[var(--muted)]">{hit.sku}</span>
                        </button>
                    </li>
                ))}
            </ul>

            {value.length > 0 && (
                <ul className="mt-2 flex flex-wrap gap-1.5">
                    {value.map((id) => (
                        <li
                            key={id}
                            className="flex min-h-[2rem] items-center gap-1.5 rounded-chip border border-[var(--line)] bg-[var(--surface-2)] px-2 text-[0.75rem]"
                        >
                            {names[id] ?? `Artículo ${id}`}
                            <button
                                type="button"
                                onClick={() => onChange(value.filter((item) => item !== id))}
                                aria-label="Quitar"
                                className="text-[var(--muted)] hover:text-[var(--danger)]"
                            >
                                <XIcon size={12} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {error && <p className="mt-1.5 text-[0.75rem] text-[var(--danger)]">{error}</p>}
        </div>
    );
}
