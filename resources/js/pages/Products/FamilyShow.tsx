import { Link, router } from '@inertiajs/react';
import { ArrowLeftIcon, ArrowsSplitIcon, PencilSimpleIcon, StarIcon } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Select } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { ConfirmDialog } from '@/components/ui/Modal';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

interface Variant {
    id: number;
    name: string;
    label: string | null;
    sku: string;
    barcode: string | null;
    price: number;
    online_price: number | null;
    cost?: number;
    min_stock: number;
    is_active: boolean;
    is_online_visible: boolean;
    value_1: { id: number; value: string } | null;
    value_2: { id: number; value: string } | null;
    stock: Record<string, number>;
    sold_30: number;
    sold_30_total: number;
}

interface Props {
    family: {
        id: number;
        name: string;
        code: string | null;
        slug: string;
        brand: string | null;
        description: string | null;
        tax_rate: number;
        category: string | null;
        supplier: string | null;
        is_active: boolean;
        is_online_visible: boolean;
        is_featured: boolean;
        default_variant_id: number | null;
        attribute_1: { id: number; name: string } | null;
        attribute_2: { id: number; name: string } | null;
        images: { id: number; url: string; value_id: number | null }[];
    };
    variants: Variant[];
    stores: { id: number; name: string }[];
    canViewCost: boolean;
}

/**
 * La ficha del artículo con subartículos: lo común, la matriz de existencias de cada sede (sumando
 * sus bodegas), las ventas de los últimos 30 días y la ficha de cada subartículo.
 */
export default function FamilyShow({ family, variants, stores, canViewCost }: Props) {
    const { can } = usePermission();
    const [store, setStore] = useState(String(stores[0]?.id ?? ''));
    const [ungrouping, setUngrouping] = useState(false);
    const [detaching, setDetaching] = useState<Variant | null>(null);

    // Con dos características, una tabla (filas × columnas); con una, una fila.
    const rows = useMemo(() => unique(variants.map((variant) => variant.value_1)).filter(Boolean) as { id: number; value: string }[], [variants]);
    const columns = useMemo(() => (family.attribute_2 ? (unique(variants.map((variant) => variant.value_2)).filter(Boolean) as { id: number; value: string }[]) : []), [variants, family.attribute_2]);
    const cellFor = (v1: number, v2: number | null) => variants.find((variant) => variant.value_1?.id === v1 && (v2 === null || variant.value_2?.id === v2)) ?? null;
    const totalSold = variants.reduce((sum, variant) => sum + variant.sold_30_total, 0);

    return (
        <AppLayout title={family.name}>
            <PageHeader
                title={family.name}
                breadcrumb={<Link href={route('products.index')}>Artículos</Link>}
                description={`${variants.length} subartículos · ${family.attribute_1?.name ?? ''}${family.attribute_2 ? ` × ${family.attribute_2.name}` : ''}`}
                actions={
                    <>
                        <ButtonLink href={route('products.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                            Volver
                        </ButtonLink>
                        {can('products.index.edit') && (
                            <>
                                <Button variant="ghost" icon={<ArrowsSplitIcon size={16} />} onClick={() => setUngrouping(true)}>
                                    Deshacer agrupación
                                </Button>
                                <ButtonLink href={route('products.families.edit', family.id)} icon={<PencilSimpleIcon size={16} />}>
                                    Editar
                                </ButtonLink>
                            </>
                        )}
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <Card padded={false}>
                    <div className="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--line)] p-4">
                        <div>
                            <h2 className="text-[1rem] font-semibold">Existencias por sede</h2>
                            <p className="text-[0.8125rem] text-[var(--muted)]">Suma de las bodegas de la sede. En rojo lo agotado, en ámbar lo que está en el mínimo o por debajo.</p>
                        </div>
                        {stores.length > 1 && <Select label="Sede" options={stores.map((entry) => ({ value: entry.id, label: entry.name }))} value={store} onChange={(event) => setStore(event.target.value)} />}
                    </div>
                    <div className="overflow-x-auto p-4">
                        <table className="text-[0.8125rem]">
                            <thead>
                                <tr>
                                    <th className="px-2 py-1.5 text-left text-[0.75rem] font-semibold uppercase text-[var(--muted)]">{family.attribute_1?.name}</th>
                                    {family.attribute_2 ? (
                                        columns.map((column) => (
                                            <th key={column.id} className="px-2 py-1.5 text-center text-[0.75rem] font-semibold text-[var(--muted)]">
                                                {column.value}
                                            </th>
                                        ))
                                    ) : (
                                        <th className="px-2 py-1.5 text-center text-[0.75rem] font-semibold uppercase text-[var(--muted)]">Existencia</th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.id}>
                                        <th className="px-2 py-1.5 text-left font-semibold">{row.value}</th>
                                        {(family.attribute_2 ? columns : [null]).map((column) => {
                                            const variant = cellFor(row.id, column?.id ?? null);
                                            const quantity = variant ? (variant.stock[store] ?? 0) : null;

                                            return (
                                                <td key={column?.id ?? 'single'} className="px-1 py-1 text-center">
                                                    {variant ? (
                                                        <Link
                                                            href={route('products.show', variant.id)}
                                                            className={cn(
                                                                'block min-w-16 rounded-control border px-2 py-1.5 font-semibold tabular-nums hover:border-[var(--accent)]',
                                                                !variant.is_active && 'opacity-50',
                                                                quantity !== null && quantity <= 0
                                                                    ? 'border-[var(--danger)]/30 bg-[var(--danger-soft)] text-[var(--danger)]'
                                                                    : quantity !== null && variant.min_stock > 0 && quantity <= variant.min_stock
                                                                      ? 'border-[var(--warn)]/30 bg-[var(--warn-soft)] text-[var(--warn)]'
                                                                      : 'border-[var(--line)]',
                                                            )}
                                                            title={variant.name}
                                                        >
                                                            {number(quantity ?? 0)}
                                                        </Link>
                                                    ) : (
                                                        <span className="block min-w-16 px-2 py-1.5 text-[var(--muted)]" title="Esta combinación no existe">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card>
                    <CardHeader title="Lo común" />
                    <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-[0.8125rem]">
                        <dt className="text-[var(--muted)]">Código</dt>
                        <dd>{family.code ?? '—'}</dd>
                        <dt className="text-[var(--muted)]">Categoría</dt>
                        <dd>{family.category ?? '—'}</dd>
                        <dt className="text-[var(--muted)]">Marca</dt>
                        <dd>{family.brand ?? '—'}</dd>
                        <dt className="text-[var(--muted)]">Proveedor</dt>
                        <dd>{family.supplier ?? '—'}</dd>
                        <dt className="text-[var(--muted)]">IVA</dt>
                        <dd>{family.tax_rate} %</dd>
                        <dt className="text-[var(--muted)]">Estado</dt>
                        <dd className="flex flex-wrap gap-1">
                            <Badge tone={family.is_active ? 'accent' : 'warn'}>{family.is_active ? 'Activo' : 'Inactivo'}</Badge>
                            {family.is_online_visible && <Badge tone="info">En línea</Badge>}
                            {family.is_featured && <Badge>Destacado</Badge>}
                        </dd>
                        <dt className="text-[var(--muted)]">Vendido 30 días</dt>
                        <dd className="font-semibold tabular-nums">{money(totalSold)}</dd>
                    </dl>
                    {family.images.length > 0 && (
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {family.images.map((image) => (
                                <img key={image.id} src={image.url} alt="" className="size-16 rounded-control border border-[var(--line)] object-cover" />
                            ))}
                        </div>
                    )}
                    {family.description && <p className="mt-3 whitespace-pre-line text-[0.8125rem] text-[var(--muted)]">{family.description}</p>}
                </Card>
            </div>

            <Card padded={false} className="mt-4">
                <div className="border-b border-[var(--line)] p-4">
                    <h2 className="text-[1rem] font-semibold">Subartículos</h2>
                    <p className="text-[0.8125rem] text-[var(--muted)]">Cada uno se cobra, se compra y se cuenta por separado. Ventas de los últimos 30 días.</p>
                </div>
                <Table>
                    <THead>
                        <TH>Subartículo</TH>
                        <TH>SKU / código</TH>
                        <TH align="right">Precio</TH>
                        {canViewCost && <TH align="right">Costo</TH>}
                        <TH align="right">Existencia (sede)</TH>
                        <TH align="right">Vendido 30 días</TH>
                        <TH />
                    </THead>
                    <TBody>
                        {variants.map((variant) => (
                            <TR key={variant.id} className={cn(!variant.is_active && 'opacity-60')}>
                                <TD>
                                    <Link href={route('products.show', variant.id)} className="font-semibold hover:underline">
                                        {variant.label ?? variant.name}
                                    </Link>
                                    <div className="mt-0.5 flex flex-wrap gap-1">
                                        {family.default_variant_id === variant.id && (
                                            <Badge tone="accent">
                                                <StarIcon size={11} weight="fill" /> Primero en la tienda
                                            </Badge>
                                        )}
                                        {!variant.is_active && <Badge tone="warn">Inactivo</Badge>}
                                        {!variant.is_online_visible && <Badge>Oculto en línea</Badge>}
                                    </div>
                                </TD>
                                <TD>
                                    <span className="tabular-nums">{variant.sku}</span>
                                    {variant.barcode && <span className="block text-[0.75rem] text-[var(--muted)] tabular-nums">{variant.barcode}</span>}
                                </TD>
                                <TD align="right">
                                    <span className="tabular-nums">{money(variant.price)}</span>
                                    {variant.online_price ? <span className="block text-[0.75rem] text-[var(--muted)]">En línea {money(variant.online_price)}</span> : null}
                                </TD>
                                {canViewCost && <TD align="right">{money(variant.cost ?? 0)}</TD>}
                                <TD align="right">
                                    <span className="tabular-nums">{number(variant.stock[store] ?? 0)}</span>
                                </TD>
                                <TD align="right">
                                    <span className="tabular-nums">{number(variant.sold_30)}</span>
                                    <span className="block text-[0.75rem] text-[var(--muted)]">{money(variant.sold_30_total)}</span>
                                </TD>
                                <TD align="right">
                                    {can('products.index.edit') && (
                                        <Button variant="ghost" size="sm" onClick={() => setDetaching(variant)}>
                                            Separar
                                        </Button>
                                    )}
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </Card>

            <ConfirmDialog
                open={ungrouping}
                onClose={() => setUngrouping(false)}
                onConfirm={() => {
                    router.post(route('products.families.ungroup', family.id));
                    setUngrouping(false);
                }}
                title="Deshacer la agrupación"
                message="Cada subartículo vuelve a ser un artículo individual, con su existencia, su kardex y sus ventas. En la tienda dejará de verse como uno solo."
                confirmLabel="Deshacer"
            />
            <ConfirmDialog
                open={detaching !== null}
                onClose={() => setDetaching(null)}
                onConfirm={() => {
                    if (detaching) {
                        router.post(route('products.detach', detaching.id));
                    }
                    setDetaching(null);
                }}
                title="Separar el subartículo"
                message={`«${detaching?.name ?? ''}» queda como artículo individual, con su nombre actual. Lo demás del artículo sigue igual.`}
                confirmLabel="Separar"
            />
        </AppLayout>
    );
}

function unique<T extends { id: number } | null>(items: T[]): T[] {
    const seen = new Set<number>();

    return items.filter((item) => {
        if (!item || seen.has(item.id)) {
            return false;
        }

        seen.add(item.id);

        return true;
    });
}
