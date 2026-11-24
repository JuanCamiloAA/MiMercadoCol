import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, PackageIcon, PencilSimpleIcon, StackIcon, TagIcon } from '@phosphor-icons/react';
import { Fragment, type FormEvent, useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { MovementReference, type MovementReferenceData } from '@/components/inventory/MovementReference';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader, StockBar } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, number, percent, UNIT_LABELS } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

interface ProductShowProps {
    product: {
        id: number;
        name: string;
        sku: string;
        barcode: string | null;
        brand: string | null;
        unit: string;
        sold_by_weight: boolean;
        price: number;
        cost?: number;
        margin?: number;
        wholesale_price: number | null;
        online_price: number | null;
        tax_rate: number;
        min_stock: number;
        is_active: boolean;
        is_online_visible: boolean;
        is_featured: boolean;
        track_lots: boolean;
        allow_negative_stock: boolean;
        description: string | null;
        category: { id: number; name: string } | null;
        supplier: { id: number; name: string; contact_name: string | null; phone: string | null } | null;
        images: { id: number; path: string; url: string | null }[];
        /** Si es un subartículo: su artículo principal. */
        family: { id: number; name: string; label: string | null } | null;
    };
    stocks: {
        store_id: number;
        store_name: string;
        quantity: number;
        reserved_quantity: number;
        location: string | null;
        warehouses: { warehouse_id: number; warehouse_name: string; quantity: number; reserved_quantity: number; location: string | null }[];
    }[];
    salesTrend: { date: string; label: string; quantity: number; total: number }[];
    movements: {
        id: number;
        type: string;
        type_label: string;
        quantity: number;
        balance_after: number;
        store: string | null;
        user: string | null;
        note: string | null;
        reference: MovementReferenceData | null;
        occurred_at: string;
    }[];
    canViewCost: boolean;
}

export default function ProductShow({ product, stocks, salesTrend, movements, canViewCost }: ProductShowProps) {
    const { can } = usePermission();
    const [priceModal, setPriceModal] = useState(false);

    const priceForm = useForm({
        price: product.price,
        wholesale_price: product.wholesale_price ?? '',
        online_price: product.online_price ?? '',
    });

    const submitPrice = (event: FormEvent) => {
        event.preventDefault();
        priceForm.post(route('products.price', product.id), {
            preserveScroll: true,
            onSuccess: () => setPriceModal(false),
        });
    };

    const taxBase = Math.round(product.price / (1 + product.tax_rate / 100));
    const hasTrend = salesTrend.some((point) => point.total > 0);

    return (
        <AppLayout title={product.name}>
            <PageHeader
                title={product.name}
                description={`${product.sku}${product.brand ? ` · ${product.brand}` : ''}`}
                breadcrumb={
                    <Link href={route('products.index')} className="hover:text-[var(--accent)]">
                        Artículos
                    </Link>
                }
                actions={
                    <>
                        <ButtonLink href={route('products.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                            Volver
                        </ButtonLink>
                        {can('products.show.edit_price') && (
                            <Button variant="secondary" icon={<TagIcon size={16} />} onClick={() => setPriceModal(true)}>
                                Cambiar precio
                            </Button>
                        )}
                        {can('products.index.edit') && can('products.index.create') && !product.family && (
                            <ButtonLink href={route('products.convert.form', product.id)} variant="secondary" icon={<StackIcon size={16} />}>
                                Convertir en artículo con subartículos
                            </ButtonLink>
                        )}
                        {can('products.index.edit') && (
                            <ButtonLink href={route('products.edit', product.id)} icon={<PencilSimpleIcon size={16} />}>
                                Editar
                            </ButtonLink>
                        )}
                    </>
                }
            />

            {product.family && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-card border border-[var(--line)] bg-[var(--surface-2)] px-4 py-3 text-[0.8125rem]">
                    <StackIcon size={18} className="text-[var(--accent)]" />
                    <span>
                        Subartículo de{' '}
                        <Link href={route('products.families.show', product.family.id)} className="font-semibold text-[var(--accent)] hover:underline">
                            {product.family.name}
                        </Link>
                        {product.family.label ? ` — ${product.family.label}` : ''}
                    </span>
                    {can('products.index.edit') && (
                        <Button variant="ghost" size="sm" className="ml-auto" onClick={() => router.post(route('products.detach', product.id))}>
                            Separar del artículo
                        </Button>
                    )}
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-[340px_1fr]">
                <div className="flex flex-col gap-4">
                    <Card>
                        <div className="grid h-40 place-items-center rounded-control bg-[var(--surface-2)] text-[var(--muted)]">
                            {product.images.length > 0 ? (
                                <img src={product.images[0].url ?? undefined} alt={product.name} className="h-full w-full rounded-control object-cover" />
                            ) : (
                                <PackageIcon size={44} />
                            )}
                        </div>

                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {product.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                            {product.is_online_visible && <Badge tone="info">En la tienda</Badge>}
                            {product.is_featured && <Badge tone="warn">Destacado</Badge>}
                            {product.track_lots && <Badge tone="neutral">Controla lotes</Badge>}
                        </div>

                        {product.description && <p className="mt-3 text-[0.8125rem] leading-relaxed text-[var(--muted)]">{product.description}</p>}
                    </Card>

                    <Card>
                        <CardHeader title="Identificación" />
                        <dl className="flex flex-col gap-2.5 text-[0.8125rem]">
                            <Row label="SKU" value={product.sku} mono />
                            <Row label="Código de barras" value={product.barcode} mono />
                            <Row label="Categoría" value={product.category?.name} />
                            <Row label="Unidad" value={UNIT_LABELS[product.unit] ?? product.unit} />
                            <Row label="Existencia mínima" value={String(product.min_stock)} mono />
                        </dl>
                    </Card>

                    {product.supplier && (
                        <Card>
                            <CardHeader title="Proveedor" />
                            <p className="text-[0.84375rem] font-medium text-[var(--text)]">{product.supplier.name}</p>
                            {product.supplier.contact_name && <p className="text-[0.78125rem] text-[var(--muted)]">{product.supplier.contact_name}</p>}
                            {product.supplier.phone && <p className="font-mono text-[0.78125rem] text-[var(--muted)] tabular">{product.supplier.phone}</p>}
                        </Card>
                    )}
                </div>

                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader title="Precios y tributos" />
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <PriceBox label="Precio al público" value={money(product.price)} highlight />
                            <PriceBox label={`Base gravable (IVA ${product.tax_rate}%)`} value={money(taxBase)} />
                            <PriceBox label="Precio mayorista" value={product.wholesale_price ? money(product.wholesale_price) : '—'} />
                            <PriceBox label="Precio en línea" value={product.online_price ? money(product.online_price) : '—'} />
                            {canViewCost && <PriceBox label="Costo" value={money(product.cost ?? 0)} />}
                            {canViewCost && <PriceBox label="Margen" value={percent(product.margin ?? 0)} />}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Existencias por sede" />
                        <Table className="border-0">
                            <THead>
                                <TR>
                                    <TH>Sede</TH>
                                    <TH>Disponible</TH>
                                    <TH align="right">Reservado</TH>
                                    <TH align="right">Ubicación</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {stocks.map((stock) => (
                                    <Fragment key={stock.store_id}>
                                        <TR>
                                            <TD className="text-[0.8125rem] font-medium">{stock.store_name}</TD>
                                            <TD>
                                                <StockBar quantity={stock.quantity} minStock={product.min_stock} />
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] text-[var(--muted)] tabular">
                                                {number(stock.reserved_quantity)}
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.78125rem] text-[var(--muted)]">
                                                {stock.location ?? '—'}
                                            </TD>
                                        </TR>
                                        {/* El desglose solo aparece donde hay mas de una bodega: con una sola
                                            repetiria la misma cifra que la linea de arriba. */}
                                        {stock.warehouses.length > 1 &&
                                            stock.warehouses.map((warehouse) => (
                                                <TR key={warehouse.warehouse_id}>
                                                    <TD className="pl-8 text-[0.78125rem] text-[var(--muted)]">
                                                        {warehouse.warehouse_name}
                                                    </TD>
                                                    <TD className="font-mono text-[0.78125rem] text-[var(--muted)] tabular">
                                                        {number(warehouse.quantity)}
                                                    </TD>
                                                    <TD align="right" className="font-mono text-[0.78125rem] text-[var(--muted)] tabular">
                                                        {warehouse.reserved_quantity > 0 ? number(warehouse.reserved_quantity) : '—'}
                                                    </TD>
                                                    <TD align="right" className="font-mono text-[0.75rem] text-[var(--muted)]">
                                                        {warehouse.location ?? '—'}
                                                    </TD>
                                                </TR>
                                            ))}
                                    </Fragment>
                                ))}
                            </TBody>
                        </Table>
                    </Card>

                    <Card>
                        <CardHeader title="Ventas de los últimos 14 días" />
                        {hasTrend ? (
                            <div className="h-[12.5rem] w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={salesTrend} margin={{ top: 4, right: 4, bottom: 0, left: -12 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="var(--line)" vertical={false} />
                                        <XAxis
                                            dataKey="label"
                                            tickLine={false}
                                            axisLine={{ stroke: 'var(--line)' }}
                                            tick={{ fill: 'var(--muted)', fontSize: 11, fontFamily: 'IBM Plex Mono' }}
                                        />
                                        <YAxis
                                            tickLine={false}
                                            axisLine={false}
                                            width={54}
                                            tick={{ fill: 'var(--muted)', fontSize: 11, fontFamily: 'IBM Plex Mono' }}
                                            tickFormatter={(value: number) => (value >= 1000 ? `${Math.round(value / 1000)}k` : String(value))}
                                        />
                                        <Tooltip
                                            cursor={{ fill: 'var(--surface-2)' }}
                                            contentStyle={{ background: 'var(--surface)', border: '1px solid var(--line)', borderRadius: 10, fontSize: 12 }}
                                            formatter={(value: number) => [money(value), 'Vendido']}
                                        />
                                        <Bar dataKey="total" fill="var(--accent)" radius={[5, 5, 0, 0]} maxBarSize={26} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        ) : (
                            <p className="text-[0.8125rem] text-[var(--muted)]">Este artículo no se ha vendido en los últimos 14 días.</p>
                        )}
                    </Card>

                    <Card padded={false}>
                        <div className="px-5 pt-5">
                            <CardHeader title="Movimientos recientes" description="Últimos 12 registros del kardex." />
                        </div>
                        <Table className="rounded-none border-x-0 border-b-0">
                            <THead>
                                <TR>
                                    <TH>Fecha</TH>
                                    <TH>Tipo</TH>
                                    <TH>Sede</TH>
                                    <TH align="right">Cantidad</TH>
                                    <TH align="right">Saldo</TH>
                                    <TH>Responsable</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {movements.length === 0 && (
                                    <TR>
                                        <TD colSpan={6} className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">
                                            Sin movimientos registrados.
                                        </TD>
                                    </TR>
                                )}
                                {movements.map((movement) => (
                                    <TR key={movement.id}>
                                        <TD className="text-[0.78125rem] text-[var(--muted)]">{dateTime(movement.occurred_at)}</TD>
                                        <TD>
                                            <Badge tone={movement.quantity >= 0 ? 'accent' : 'neutral'}>{movement.type_label}</Badge>
                                            <MovementReference reference={movement.reference} />
                                        </TD>
                                        <TD className="text-[0.78125rem] text-[var(--muted)]">{movement.store ?? '—'}</TD>
                                        <TD
                                            align="right"
                                            className="font-mono text-[0.8125rem] tabular"
                                            style={{ color: movement.quantity >= 0 ? 'var(--accent)' : 'var(--danger)' }}
                                        >
                                            {movement.quantity > 0 ? '+' : ''}
                                            {number(movement.quantity)}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {number(movement.balance_after)}
                                        </TD>
                                        <TD className="text-[0.78125rem] text-[var(--muted)]">{movement.user ?? '—'}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </Card>
                </div>
            </div>

            <Modal
                open={priceModal}
                onClose={() => setPriceModal(false)}
                title="Cambiar los precios"
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setPriceModal(false)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitPrice} loading={priceForm.processing}>
                            Guardar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitPrice} className="flex flex-col gap-4">
                    <Input
                        label="Precio al público"
                        type="number"
                        min={0}
                        required
                        value={priceForm.data.price}
                        onChange={(event) => priceForm.setData('price', Number(event.target.value))}
                        error={priceForm.errors.price}
                        prefix="$"
                    />
                    <Input
                        label="Precio mayorista"
                        type="number"
                        min={0}
                        value={priceForm.data.wholesale_price}
                        onChange={(event) => priceForm.setData('wholesale_price', event.target.value)}
                        error={priceForm.errors.wholesale_price}
                        prefix="$"
                    />
                    <Input
                        label="Precio en línea"
                        type="number"
                        min={0}
                        value={priceForm.data.online_price}
                        onChange={(event) => priceForm.setData('online_price', event.target.value)}
                        error={priceForm.errors.online_price}
                        prefix="$"
                    />
                </form>
            </Modal>
        </AppLayout>
    );
}

function Row({ label, value, mono }: { label: string; value: string | null | undefined; mono?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <dt className="shrink-0 text-[var(--muted)]">{label}</dt>
            <dd className={`text-right ${mono ? 'font-mono text-[0.78125rem] tabular' : ''}`}>{value || '—'}</dd>
        </div>
    );
}

function PriceBox({ label, value, highlight }: { label: string; value: string; highlight?: boolean }) {
    return (
        <div
            className="rounded-control border px-3 py-2.5"
            style={{
                borderColor: highlight ? 'var(--accent)' : 'var(--line)',
                backgroundColor: highlight ? 'var(--accent-soft)' : 'var(--surface-2)',
            }}
        >
            <p className="text-[0.71875rem] uppercase tracking-wide text-[var(--muted)]">{label}</p>
            <p
                className="font-mono text-[1.0625rem] font-semibold tabular"
                style={{ color: highlight ? 'var(--accent)' : 'var(--text)' }}
            >
                {value}
            </p>
        </div>
    );
}
