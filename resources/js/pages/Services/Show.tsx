import { Link } from '@inertiajs/react';
import { ArrowLeftIcon, PencilSimpleIcon, WarningCircleIcon, WrenchIcon } from '@phosphor-icons/react';
import { ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, number, percent, UNIT_SHORT } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { ServiceRow } from '@/types/services';

interface ServiceShowProps {
    service: ServiceRow & {
        description: string | null;
        image_path: string | null;
        image_url?: string | null;
        tax_rate: number;
        is_featured: boolean;
        online_price: number | null;
        price_pos: number;
        price_online: number | null;
        value_pos: number;
        value_online: number;
    };
    components: {
        id: number;
        product_id: number;
        name: string;
        sku: string | null;
        unit: string | null;
        quantity: number;
        returnable_by_default: boolean;
        unit_price: number;
        /** El precio se fijó en el servicio, no es el vigente del artículo. */
        fixed_price: boolean;
        unit_cost: number | null;
        is_active: boolean;
        is_deleted: boolean;
        stock_pos: number | null;
        stock_online: number | null;
    }[];
    warehouses: { pos: string | null; online: string | null; shared: boolean };
    sales: {
        count: number;
        adjusted: number;
        recent: { id: number; sale_id: number; number: string | null; sold_at: string | null; channel: string | null; quantity: number; line_total: number; adjusted: boolean }[];
    };
    canViewCost: boolean;
}

/**
 * La ficha de un servicio: precio por canal, lo que consume con su existencia, y cómo se ha
 * vendido. Un artículo inactivo o sin existencias se avisa aquí antes de que lo encuentre la caja.
 */
export default function ServiceShow({ service, components, warehouses, sales, canViewCost }: ServiceShowProps) {
    const { can } = usePermission();
    const short = components.filter((line) => line.stock_pos !== null && line.stock_pos < line.quantity);

    return (
        <AppLayout title={service.name}>
            <PageHeader
                title={service.name}
                description={`${service.code}${service.category ? ` · ${service.category.name}` : ''}`}
                breadcrumb={
                    <Link href={route('services.index')} className="hover:text-[var(--accent)]">
                        Servicios
                    </Link>
                }
                actions={
                    <>
                        <ButtonLink href={route('services.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                            Volver
                        </ButtonLink>
                        {can('services.index.edit') && (
                            <ButtonLink href={route('services.edit', service.id)} icon={<PencilSimpleIcon size={16} />}>
                                Editar
                            </ButtonLink>
                        )}
                    </>
                }
            />

            {(service.blocked.length > 0 || short.length > 0) && (
                <Card className="mb-4">
                    {service.blocked.length > 0 && (
                        <p className="flex items-start gap-2 text-[0.8125rem] font-medium text-[var(--danger)]">
                            <WarningCircleIcon size={16} weight="fill" className="mt-px shrink-0" />
                            No se puede cobrar hasta corregir el maestro: {service.blocked.join(', ')}.
                        </p>
                    )}
                    {short.length > 0 && (
                        <p className="mt-1 flex items-start gap-2 text-[0.8125rem] font-medium text-[var(--warn)]">
                            <WarningCircleIcon size={16} weight="fill" className="mt-px shrink-0" />
                            Sin existencias suficientes en {warehouses.pos} para uno: {short.map((line) => line.name).join(', ')}.
                        </p>
                    )}
                </Card>
            )}

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card>
                    <div className="flex items-start gap-3">
                        {service.image_url ? (
                            <img src={service.image_url} alt="" className="size-20 shrink-0 rounded-control object-cover" />
                        ) : (
                            <span className="grid size-20 shrink-0 place-items-center rounded-control bg-[var(--surface-2)] text-[var(--muted)]">
                                <WrenchIcon size={28} />
                            </span>
                        )}
                        <div className="flex flex-wrap gap-1.5">
                            {service.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                            {service.is_online_visible && <Badge tone="info">En la tienda</Badge>}
                            {service.is_featured && <Badge tone="warn">Destacado</Badge>}
                            <Badge>{service.components_count > 0 ? service.pricing_label : 'Solo valor'}</Badge>
                        </div>
                    </div>
                    {service.description && <p className="mt-3 text-[0.8125rem] text-[var(--muted)]">{service.description}</p>}
                    {service.barcode && <p className="mt-2 font-mono text-[0.75rem] text-[var(--muted)]">Código de barras {service.barcode}</p>}
                </Card>

                <Card>
                    <CardHeader title="Precio por canal" description={`IVA ${service.tax_rate} % incluido`} />
                    {service.open_price ? (
                        <p className="text-[0.84375rem]">Precio variable: el cajero lo digita en cada venta. No se publica en línea.</p>
                    ) : (
                        <dl className="flex flex-col gap-2 text-[0.8125rem]">
                            <div className="flex justify-between">
                                <dt className="text-[var(--muted)]">En caja</dt>
                                <dd className="font-mono text-[1.125rem] font-semibold text-[var(--accent)] tabular">{money(service.price_pos)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-[var(--muted)]">En línea</dt>
                                <dd className="font-mono font-semibold tabular">{service.price_online === null ? '—' : money(service.price_online)}</dd>
                            </div>
                            {service.pricing_mode === 'itemized' && service.components_count > 0 && (
                                <p className="text-[0.75rem] text-[var(--muted)]">
                                    Servicio {money(service.value_pos)} más los artículos{' '}
                                    {components.some((line) => line.fixed_price) ? 'al precio fijado en el servicio o, si no tienen, al vigente' : 'a su precio vigente'}; quitar uno en caja baja
                                    el total.
                                </p>
                            )}
                        </dl>
                    )}
                </Card>

                <Card>
                    <CardHeader title="Ventas" />
                    <dl className="flex flex-col gap-2 text-[0.8125rem]">
                        <div className="flex justify-between">
                            <dt className="text-[var(--muted)]">Veces vendido</dt>
                            <dd className="font-mono tabular">{number(sales.count)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-[var(--muted)]">Con artículos ajustados</dt>
                            <dd className="font-mono tabular">{number(sales.adjusted)}</dd>
                        </div>
                        {canViewCost && (
                            <>
                                <div className="flex justify-between">
                                    <dt className="text-[var(--muted)]">Costo total</dt>
                                    <dd className="font-mono tabular">{money(service.cost_total ?? 0)}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-[var(--muted)]">Margen</dt>
                                    <dd className="font-mono tabular">{service.margin === null || service.margin === undefined ? '—' : percent(service.margin)}</dd>
                                </div>
                            </>
                        )}
                    </dl>
                </Card>
            </div>

            {components.length > 0 && (
                <Card padded={false} className="mb-4">
                    <div className="px-5 pt-5">
                        <CardHeader title="Artículos que consume" description="Por una unidad de servicio." />
                    </div>
                    <Table className="rounded-none border-x-0 border-b-0">
                        <THead>
                            <TR>
                                <TH>Artículo</TH>
                                <TH align="right">Cantidad</TH>
                                <TH>Si se devuelve</TH>
                                {service.pricing_mode === 'itemized' && <TH align="right">Precio en caja</TH>}
                                {canViewCost && <TH align="right">Costo</TH>}
                                {warehouses.shared ? (
                                    <TH align="right">Disponible en {warehouses.pos}</TH>
                                ) : (
                                    <>
                                        <TH align="right">Caja · {warehouses.pos ?? '—'}</TH>
                                        <TH align="right">Tienda · {warehouses.online ?? '—'}</TH>
                                    </>
                                )}
                            </TR>
                        </THead>
                        <TBody>
                            {components.map((line) => (
                                <TR key={line.id}>
                                    <TD>
                                        {can('products.show.view') && !line.is_deleted ? (
                                            <Link href={route('products.show', line.product_id)} className="text-[0.8125rem] font-medium hover:text-[var(--accent)]">
                                                {line.name}
                                            </Link>
                                        ) : (
                                            <p className="text-[0.8125rem] font-medium">{line.name}</p>
                                        )}
                                        <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{line.sku}</p>
                                        {(line.is_deleted || !line.is_active) && (
                                            <p className="text-[0.6875rem] font-medium text-[var(--danger)]">{line.is_deleted ? 'Eliminado' : 'Inactivo'}</p>
                                        )}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(line.quantity)} {UNIT_SHORT[line.unit ?? 'unit'] ?? ''}
                                    </TD>
                                    <TD className="text-[0.78125rem]">{line.returnable_by_default ? 'Vuelve al inventario' : 'No vuelve'}</TD>
                                    {service.pricing_mode === 'itemized' && (
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {money(Math.round(line.quantity * line.unit_price))}
                                            {line.fixed_price && <span className="block font-sans text-[0.6875rem] text-[var(--muted)]">Fijo en el servicio · {money(line.unit_price)} c/u</span>}
                                        </TD>
                                    )}
                                    {canViewCost && (
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {money(Math.round(line.quantity * (line.unit_cost ?? 0)))}
                                        </TD>
                                    )}
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular" style={{ color: line.stock_pos !== null && line.stock_pos < line.quantity ? 'var(--danger)' : undefined }}>
                                        {line.stock_pos === null ? '—' : number(line.stock_pos)}
                                    </TD>
                                    {!warehouses.shared && (
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular" style={{ color: line.stock_online !== null && line.stock_online < line.quantity ? 'var(--warn)' : undefined }}>
                                            {line.stock_online === null ? '—' : number(line.stock_online)}
                                        </TD>
                                    )}
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}

            <Card padded={false}>
                <div className="px-5 pt-5">
                    <CardHeader title="Últimas ventas" />
                </div>
                {sales.recent.length === 0 ? (
                    <p className="px-5 pb-5 text-[0.8125rem] text-[var(--muted)]">Todavía no se ha vendido.</p>
                ) : (
                    <Table className="rounded-none border-x-0 border-b-0">
                        <THead>
                            <TR>
                                <TH>Venta</TH>
                                <TH>Fecha</TH>
                                <TH align="right">Cantidad</TH>
                                <TH align="right">Valor</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {sales.recent.map((line) => (
                                <TR key={line.id}>
                                    <TD className="font-mono text-[0.8125rem]">
                                        {can('sales.index.view') ? (
                                            <Link href={route('sales.show', line.sale_id)} className="hover:text-[var(--accent)]">
                                                {line.number}
                                            </Link>
                                        ) : (
                                            line.number
                                        )}
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{line.sold_at ? dateTime(line.sold_at) : '—'}</TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(line.quantity)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {money(line.line_total)}
                                    </TD>
                                    <TD>{line.adjusted && <Badge tone="warn">Ajustado</Badge>}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>
        </AppLayout>
    );
}
