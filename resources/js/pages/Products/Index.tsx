import { Link, router } from '@inertiajs/react';
import {
    CaretDownIcon,
    CaretRightIcon,
    DownloadSimpleIcon,
    PackageIcon,
    PencilSimpleIcon,
    PlusIcon,
    PrinterIcon,
    StackIcon,
    UploadSimpleIcon,
} from '@phosphor-icons/react';
import { type FormEvent, Fragment, useMemo, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, Chip, PageHeader, SearchInput, StockBar } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { money, number, percent, UNIT_SHORT } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';

interface ProductRow {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    brand: string | null;
    unit: string;
    price: number;
    cost?: number;
    margin?: number;
    tax_rate: number;
    min_stock: number;
    is_active: boolean;
    is_online_visible: boolean;
    image_path: string | null;
    thumb_path: string | null;
    thumb_url?: string | null;
    category: { id: number; name: string } | null;
    supplier_name: string | null;
    stock_quantity: number;
    family_id?: number | null;
    variant_label?: string | null;
    /** Una fila agrupada: el artículo con subartículos, que se despliega. */
    kind?: 'family';
    price_max?: number;
    variants_count?: number;
    variants?: ProductRow[];
}

interface ProductsIndexProps {
    products: Paginated<ProductRow>;
    filters: { search: string; category: string; supplier: string; status: string; stock: string; type: string; ungroup: string };
    categories: { value: number; label: string }[];
    suppliers: { value: number; label: string }[];
    canViewCost: boolean;
}

export default function ProductsIndex({ products, filters: initialFilters, categories, suppliers, canViewCost }: ProductsIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('products.index', initialFilters);
    const [selected, setSelected] = useState<number[]>([]);
    const [bulkModal, setBulkModal] = useState<'price' | 'category' | null>(null);
    const [percentValue, setPercentValue] = useState('5');
    const [categoryValue, setCategoryValue] = useState('');
    const [busy, setBusy] = useState(false);
    const [open, setOpen] = useState<number[]>([]);

    // Lo que se selecciona son siempre artículos: una familia aporta sus subartículos (etiquetas,
    // lote y exportación son por subartículo).
    const idsOf = (row: ProductRow) => (row.kind === 'family' ? (row.variants ?? []).map((variant) => variant.id) : [row.id]);
    const selectable = useMemo(() => products.data.flatMap(idsOf), [products.data]);
    const allSelected = useMemo(() => selectable.length > 0 && selectable.every((id) => selected.includes(id)), [selectable, selected]);

    const toggle = (row: ProductRow) => {
        const ids = idsOf(row);
        const on = ids.every((id) => selected.includes(id));
        setSelected((current) => (on ? current.filter((value) => !ids.includes(value)) : Array.from(new Set([...current, ...ids]))));
    };

    const toggleAll = () => {
        setSelected(allSelected ? [] : selectable);
    };

    // Agrupar pide artículos individuales: los que ya son subartículos no entran.
    const individualIds = useMemo(() => products.data.filter((row) => row.kind !== 'family' && !row.family_id).map((row) => row.id), [products.data]);
    const groupable = selected.filter((id) => individualIds.includes(id));

    const runBulk = (payload: Record<string, unknown>) => {
        setBusy(true);
        router.post(
            route('products.bulk'),
            { product_ids: selected, ...payload },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setBulkModal(null);
                    setSelected([]);
                },
            },
        );
    };

    const printLabels = (event: FormEvent) => {
        event.preventDefault();
        // La descarga se hace con un formulario real para que el navegador reciba el PDF.
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = route('products.labels');
        form.style.display = 'none';

        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        const addField = (name: string, value: string) => {
            const input = document.createElement('input');
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };

        if (token) {
            addField('_token', token);
        }

        selected.forEach((id) => addField('product_ids[]', String(id)));
        document.body.appendChild(form);
        form.submit();
        form.remove();
    };

    /** La fila de un artículo: suelto, o dentro de su artículo principal. */
    const renderLine = (product: ProductRow, nested = false) => (
        <TR key={product.id} className={nested ? 'bg-[var(--surface-2)]/40' : undefined}>
            <TD>
                <Checkbox label="" checked={selected.includes(product.id)} onChange={() => toggle(product)} />
            </TD>
            <TD>
                <div className={nested ? 'flex items-center gap-2.5 pl-11' : 'flex items-center gap-2.5'}>
                    {product.thumb_url ? (
                        <img src={product.thumb_url} alt="" loading="lazy" className="size-9 shrink-0 rounded-chip object-cover" />
                    ) : (
                        <span className="grid size-9 shrink-0 place-items-center rounded-chip bg-[var(--surface-2)] text-[var(--muted)]">
                            <PackageIcon size={16} />
                        </span>
                    )}
                    <div className="min-w-0">
                        {can('products.show.view') ? (
                            <Link
                                href={route('products.show', product.id)}
                                className="block truncate text-[0.84375rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                            >
                                {nested && product.variant_label ? product.variant_label : product.name}
                            </Link>
                        ) : (
                            <span className="block truncate text-[0.84375rem] font-medium text-[var(--text)]">{nested && product.variant_label ? product.variant_label : product.name}</span>
                        )}
                        <p className="truncate font-mono text-[0.71875rem] text-[var(--muted)]">
                            {product.sku}
                            {product.brand && !nested ? ` · ${product.brand}` : ''}
                        </p>
                    </div>
                </div>
            </TD>
            <TD className="text-[0.78125rem] text-[var(--muted)]">{product.category?.name ?? '—'}</TD>
            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                {money(product.price)}
                <span className="block text-[0.6875rem] text-[var(--muted)]">IVA {product.tax_rate}%</span>
            </TD>
            {canViewCost && (
                <TD align="right" className="font-mono text-[0.8125rem] text-[var(--muted)] tabular">
                    {money(product.cost ?? 0)}
                </TD>
            )}
            {canViewCost && (
                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                    <span className={(product.margin ?? 0) < 15 ? 'text-[var(--warn)]' : 'text-[var(--accent)]'}>{percent(product.margin ?? 0)}</span>
                </TD>
            )}
            <TD>
                <StockBar quantity={product.stock_quantity} minStock={product.min_stock} />
                <span className="mt-0.5 block text-[0.6875rem] text-[var(--muted)]">
                    min {product.min_stock} {UNIT_SHORT[product.unit] ?? ''}
                </span>
            </TD>
            <TD align="center">
                {product.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                {product.is_online_visible && (
                    <span className="mt-1 block">
                        <Badge tone="info">En linea</Badge>
                    </span>
                )}
            </TD>
            <TD align="right">
                {can('products.index.edit') && <ButtonLink href={route('products.edit', product.id)} size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />} />}
            </TD>
        </TR>
    );

    /** La fila del artículo con subartículos: rango de precios, existencia sumada y se despliega. */
    const renderFamily = (row: ProductRow) => {
        const expanded = open.includes(row.id);

        return (
            <Fragment key={`f${row.id}`}>
                <TR>
                    <TD>
                        <Checkbox label="" checked={idsOf(row).length > 0 && idsOf(row).every((id) => selected.includes(id))} onChange={() => toggle(row)} />
                    </TD>
                    <TD>
                        <div className="flex items-center gap-2.5">
                            <button
                                type="button"
                                onClick={() => setOpen(expanded ? open.filter((id) => id !== row.id) : [...open, row.id])}
                                aria-expanded={expanded}
                                aria-label={expanded ? 'Ocultar subartículos' : 'Ver subartículos'}
                                className="grid size-9 shrink-0 place-items-center rounded-chip bg-[var(--accent-soft)] text-[var(--accent)]"
                            >
                                {expanded ? <CaretDownIcon size={16} /> : <CaretRightIcon size={16} />}
                            </button>
                            <div className="min-w-0">
                                <Link
                                    href={route('products.families.show', row.id)}
                                    className="block truncate text-[0.84375rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                >
                                    {row.name}
                                </Link>
                                <p className="truncate text-[0.71875rem] text-[var(--muted)]">
                                    {row.variants_count === 1 ? '1 subartículo' : `${row.variants_count} subartículos`}
                                    {row.sku ? ` · ${row.sku}` : ''}
                                    {row.brand ? ` · ${row.brand}` : ''}
                                </p>
                            </div>
                        </div>
                    </TD>
                    <TD className="text-[0.78125rem] text-[var(--muted)]">{row.category?.name ?? '—'}</TD>
                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                        {row.price_max && row.price_max !== row.price ? `${money(row.price)} – ${money(row.price_max)}` : money(row.price)}
                    </TD>
                    {canViewCost && <TD />}
                    {canViewCost && <TD />}
                    <TD>
                        <span className="text-[0.8125rem] font-semibold tabular-nums">{number(row.stock_quantity)}</span>
                        <span className="block text-[0.6875rem] text-[var(--muted)]">sumando los subartículos</span>
                    </TD>
                    <TD align="center">
                        {row.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                        {row.is_online_visible && (
                            <span className="mt-1 block">
                                <Badge tone="info">En linea</Badge>
                            </span>
                        )}
                    </TD>
                    <TD align="right">
                        {can('products.index.edit') && <ButtonLink href={route('products.families.edit', row.id)} size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />} />}
                    </TD>
                </TR>
                {expanded && (row.variants ?? []).map((variant) => renderLine(variant, true))}
            </Fragment>
        );
    };

    return (
        <AppLayout title="Articulos">
            <PageHeader
                title="Articulos"
                description="Catalogo de la empresa con existencias de la sede activa."
                actions={
                    <>
                        {can('products.index.import') && (
                            <ButtonLink href={route('products.import.form')} variant="secondary" icon={<UploadSimpleIcon size={16} />}>
                                Importar
                            </ButtonLink>
                        )}
                        {can('products.index.export') && (
                            <a
                                href={route('products.export')}
                                className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                            >
                                <DownloadSimpleIcon size={16} />
                                Exportar
                            </a>
                        )}
                        {can('products.index.create') && (
                            <ButtonLink href={route('products.create')} icon={<PlusIcon size={16} weight="bold" />}>
                                Nuevo articulo
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <Card className="mb-4">
                <div className="grid gap-3 lg:grid-cols-4">
                    <SearchInput placeholder="Nombre, SKU o codigo de barras" value={filters.search} onChange={(event) => update('search', event.target.value)} />
                    <Select options={suppliers} placeholder="Todos los proveedores" value={filters.supplier} onChange={(event) => update('supplier', event.target.value)} />
                    <Select
                        options={[
                            { value: 'active', label: 'Activos' },
                            { value: 'inactive', label: 'Inactivos' },
                        ]}
                        placeholder="Cualquier estado"
                        value={filters.status}
                        onChange={(event) => update('status', event.target.value)}
                    />
                    <div className="flex items-start gap-2">
                        <div className="flex-1">
                            <Select
                                options={[
                                    { value: 'low', label: 'Bajo el minimo' },
                                    { value: 'out', label: 'Agotados' },
                                ]}
                                placeholder="Cualquier existencia"
                                value={filters.stock}
                                onChange={(event) => update('stock', event.target.value)}
                            />
                        </div>
                        {hasActive && (
                            <Button variant="ghost" onClick={reset}>
                                Limpiar
                            </Button>
                        )}
                    </div>
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-4 border-t border-[var(--line)] pt-3">
                    <div className="w-56">
                        <Select
                            options={[
                                { value: 'single', label: 'Individuales' },
                                { value: 'family', label: 'Con subartículos' },
                            ]}
                            placeholder="Todos los tipos"
                            value={filters.type}
                            onChange={(event) => update('type', event.target.value)}
                        />
                    </div>
                    <Checkbox
                        label="Agrupar subartículos"
                        hint="Un artículo con subartículos en una sola fila, que se despliega."
                        checked={filters.ungroup !== '1'}
                        onChange={(event) => update('ungroup', event.target.checked ? '' : '1')}
                    />
                </div>

                <div className="mt-3 flex flex-wrap gap-1.5 border-t border-[var(--line)] pt-3">
                    <Chip active={filters.category === ''} onClick={() => update('category', '')}>
                        Todas
                    </Chip>
                    {categories.map((category) => (
                        <Chip key={category.value} active={filters.category === String(category.value)} onClick={() => update('category', String(category.value))}>
                            {category.label}
                        </Chip>
                    ))}
                </div>
            </Card>

            {selected.length > 0 && (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-card border border-[var(--accent)]/30 bg-[var(--accent-soft)] px-4 py-2.5">
                    <span className="text-[0.8125rem] font-medium text-[var(--accent)]">
                        {selected.length} {selected.length === 1 ? 'articulo seleccionado' : 'articulos seleccionados'}
                    </span>
                    <div className="ml-auto flex flex-wrap gap-2">
                        {can('products.show.edit_price') && (
                            <Button size="sm" variant="secondary" onClick={() => setBulkModal('price')}>
                                Cambiar precio
                            </Button>
                        )}
                        {can('products.index.edit') && (
                            <>
                                <Button size="sm" variant="secondary" onClick={() => setBulkModal('category')}>
                                    Cambiar categoria
                                </Button>
                                <Button size="sm" variant="secondary" onClick={() => runBulk({ action: 'deactivate' })} loading={busy}>
                                    Desactivar
                                </Button>
                                <Button size="sm" variant="secondary" onClick={() => runBulk({ action: 'activate' })} loading={busy}>
                                    Activar
                                </Button>
                            </>
                        )}
                        {can('products.index.edit') && can('products.index.create') && groupable.length >= 2 && (
                            <Button
                                size="sm"
                                variant="secondary"
                                icon={<StackIcon size={15} />}
                                onClick={() => router.get(route('products.families.group.form'), { ids: groupable })}
                            >
                                Agrupar con subartículos
                            </Button>
                        )}
                        {can('products.index.print_labels') && (
                            <Button size="sm" variant="secondary" icon={<PrinterIcon size={15} />} onClick={printLabels}>
                                Etiquetas
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                            Quitar seleccion
                        </Button>
                    </div>
                </div>
            )}

            {products.data.length === 0 ? (
                <EmptyState
                    title="Ningun articulo coincide"
                    description="Cambia los filtros o crea uno nuevo."
                    icon={<PackageIcon size={24} />}
                    action={
                        can('products.index.create') ? (
                            <ButtonLink href={route('products.create')} icon={<PlusIcon size={16} weight="bold" />}>
                                Nuevo articulo
                            </ButtonLink>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH className="w-10">
                                    <Checkbox label="" checked={allSelected} onChange={toggleAll} />
                                </TH>
                                <TH>Articulo</TH>
                                <TH>Categoria</TH>
                                <TH align="right">Precio</TH>
                                {canViewCost && <TH align="right">Costo</TH>}
                                {canViewCost && <TH align="right">Margen</TH>}
                                <TH>Existencias</TH>
                                <TH align="center">Estado</TH>
                                <TH align="right">Acciones</TH>
                            </TR>
                        </THead>
                        <TBody>{products.data.map((row) => (row.kind === 'family' ? renderFamily(row) : renderLine(row)))}</TBody>
                    </Table>

                    <Pagination meta={products} />
                </>
            )}

            <Modal
                open={bulkModal === 'price'}
                onClose={() => setBulkModal(null)}
                title="Cambiar el precio en lote"
                description="Se aplica el porcentaje sobre el precio actual de cada articulo."
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setBulkModal(null)}>
                            Cancelar
                        </Button>
                        <Button loading={busy} onClick={() => runBulk({ action: 'price_percent', percent: Number(percentValue) })}>
                            Aplicar
                        </Button>
                    </>
                }
            >
                <Input
                    label="Porcentaje de cambio"
                    type="number"
                    step="0.5"
                    value={percentValue}
                    onChange={(event) => setPercentValue(event.target.value)}
                    suffix="%"
                    hint="Usa un valor negativo para bajar precios."
                />
            </Modal>

            <Modal
                open={bulkModal === 'category'}
                onClose={() => setBulkModal(null)}
                title="Cambiar la categoria en lote"
                description="A un subartículo se le cambia en su artículo principal: la categoría es de todos."
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setBulkModal(null)}>
                            Cancelar
                        </Button>
                        <Button loading={busy} disabled={categoryValue === ''} onClick={() => runBulk({ action: 'category', category_id: Number(categoryValue) })}>
                            Aplicar
                        </Button>
                    </>
                }
            >
                <Select label="Categoria destino" options={categories} placeholder="Elige una categoria" value={categoryValue} onChange={(event) => setCategoryValue(event.target.value)} />
            </Modal>
        </AppLayout>
    );
}
