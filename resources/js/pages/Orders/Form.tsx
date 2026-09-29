import { router, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { type FormEvent, useMemo, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select, Textarea } from '@/components/ui/Field';
import { PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { money, number, UNIT_SHORT } from '@/lib/format';

interface ProductOption {
    value: number;
    label: string;
    sku: string;
    unit: string;
    price: number;
}

interface ServiceOption {
    value: number;
    label: string;
    price: number;
    includes: { name: string; quantity: number; unit: string }[];
}

interface CustomerOption {
    value: number;
    label: string;
    address: string;
    notes: string | null;
}

interface OrderFormProps {
    customers: CustomerOption[];
    products: ProductOption[];
    services: ServiceOption[];
    slots: { key: string; label: string; starts_at: string; ends_at: string; full: boolean }[];
    deliveryFee: number;
    store: { id: number; name: string } | null;
}

interface Line {
    /** Un servicio va tal cual el maestro: se alistan sus artículos al preparar el pedido. */
    kind: 'product' | 'service';
    key: string;
    product_id: number | null;
    service_id: number | null;
    includes?: ServiceOption['includes'];
    name: string;
    unit: string;
    price: number;
    quantity: number;
    substitution_policy: string;
}

const POLICIES = [
    { value: 'call', label: 'Llamarme antes' },
    { value: 'replace', label: 'Reemplazar por otro similar' },
    { value: 'remove', label: 'Quitar del pedido' },
];

export default function OrderForm({ customers, products, services, slots, deliveryFee, store }: OrderFormProps) {
    const [lines, setLines] = useState<Line[]>([]);
    const [search, setSearch] = useState('');

    const { data, setData, processing, errors } = useForm({
        customer_id: '' as number | string,
        delivery_address: '',
        delivery_notes: '',
        delivery_slot_start: '',
        delivery_slot_end: '',
        payment_method: 'cash_on_delivery',
        delivery_fee: deliveryFee,
        items: [] as { product_id: number | null; service_id: number | null; quantity: number; substitution_policy: string }[],
    });

    const subtotal = useMemo(() => lines.reduce((sum, line) => sum + Math.round(line.quantity * line.price), 0), [lines]);

    const filtered = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (!term) {
            return products.slice(0, 12);
        }

        return products.filter((product) => product.label.toLowerCase().includes(term) || product.sku.toLowerCase().includes(term)).slice(0, 12);
    }, [products, search]);

    const filteredServices = useMemo(() => {
        const term = search.trim().toLowerCase();

        return services.filter((service) => !term || service.label.toLowerCase().includes(term)).slice(0, 6);
    }, [services, search]);

    const addProduct = (product: ProductOption) => {
        const key = `p-${product.value}`;

        setLines((current) => {
            if (current.some((line) => line.key === key)) {
                return current.map((line) => (line.key === key ? { ...line, quantity: line.quantity + 1 } : line));
            }

            return [
                ...current,
                { kind: 'product', key, product_id: product.value, service_id: null, name: product.label, unit: product.unit, price: product.price, quantity: 1, substitution_policy: 'call' },
            ];
        });
    };

    const addService = (service: ServiceOption) => {
        const key = `s-${service.value}`;

        setLines((current) => {
            if (current.some((line) => line.key === key)) {
                return current.map((line) => (line.key === key ? { ...line, quantity: line.quantity + 1 } : line));
            }

            return [
                ...current,
                { kind: 'service', key, product_id: null, service_id: service.value, includes: service.includes, name: service.label, unit: 'unit', price: service.price, quantity: 1, substitution_policy: 'call' },
            ];
        });
    };

    const updateLine = (key: string, field: 'quantity' | 'substitution_policy', value: number | string) => {
        setLines((current) => current.map((line) => (line.key === key ? { ...line, [field]: value } : line)));
    };

    const chooseCustomer = (value: string) => {
        setData('customer_id', value);
        const found = customers.find((customer) => String(customer.value) === value);

        if (found) {
            setData((current) => ({
                ...current,
                customer_id: value,
                delivery_address: found.address || current.delivery_address,
                delivery_notes: found.notes ?? current.delivery_notes,
            }));
        }
    };

    const chooseSlot = (key: string) => {
        const slot = slots.find((item) => item.key === key);

        setData((current) => ({
            ...current,
            delivery_slot_start: slot?.starts_at ?? '',
            delivery_slot_end: slot?.ends_at ?? '',
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        router.post(
            route('orders.store'),
            {
                ...data,
                items: lines.map((line) => ({
                    product_id: line.product_id,
                    service_id: line.service_id,
                    quantity: line.quantity,
                    substitution_policy: line.substitution_policy,
                })),
            },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout title="Nuevo pedido">
            <PageHeader
                title="Nuevo pedido desde el mostrador"
                description={store ? `Se alista en la sede ${store.name}.` : undefined}
                actions={
                    <ButtonLink href={route('orders.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-[1fr_360px]">
                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader title="Articulos" description="Busca y agrega lo que pidio el cliente." />
                        <SearchInput
                            placeholder="Nombre o SKU del articulo"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            containerClassName="mb-3"
                        />

                        <div className="mb-4 flex flex-wrap gap-1.5">
                            {filteredServices.map((service) => (
                                <button
                                    key={`s-${service.value}`}
                                    type="button"
                                    onClick={() => addService(service)}
                                    className="flex items-center gap-2 rounded-control border border-dashed border-[var(--accent)]/60 bg-[var(--surface-2)] px-2.5 py-1.5 text-[0.78125rem] transition-colors hover:border-[var(--accent)]"
                                >
                                    <PlusIcon size={13} />
                                    {service.label}
                                    <span className="rounded-chip bg-[var(--accent-soft)] px-1 text-[0.6875rem] text-[var(--accent)]">Servicio</span>
                                    <span className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">{money(service.price)}</span>
                                </button>
                            ))}
                            {filtered.map((product) => (
                                <button
                                    key={product.value}
                                    type="button"
                                    onClick={() => addProduct(product)}
                                    className="flex items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-2.5 py-1.5 text-[0.78125rem] transition-colors hover:border-[var(--accent)]"
                                >
                                    <PlusIcon size={13} />
                                    {product.label}
                                    <span className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">{money(product.price)}</span>
                                </button>
                            ))}
                        </div>

                        {lines.length === 0 ? (
                            <EmptyState title="El pedido esta vacio" description="Agrega articulos desde el buscador." />
                        ) : (
                            <Table className="border-0">
                                <THead>
                                    <TR>
                                        <TH>Articulo</TH>
                                        <TH align="right">Cantidad</TH>
                                        <TH>Si falta</TH>
                                        <TH align="right">Subtotal</TH>
                                        <TH />
                                    </TR>
                                </THead>
                                <TBody>
                                    {lines.map((line) => (
                                        <TR key={line.key}>
                                            <TD>
                                                <p className="text-[0.84375rem] font-medium">{line.name}</p>
                                                <p className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">{money(line.price)} c/u</p>
                                                {line.kind === 'service' && (line.includes ?? []).length > 0 && (
                                                    <p className="text-[0.71875rem] text-[var(--muted)]">Incluye: {(line.includes ?? []).map((part) => part.name).join(', ')}</p>
                                                )}
                                            </TD>
                                            <TD align="right">
                                                <input
                                                    type="number"
                                                    min={line.kind === 'service' ? 1 : 0.001}
                                                    step={line.kind === 'service' ? '1' : '0.001'}
                                                    value={line.quantity}
                                                    onChange={(event) => updateLine(line.key, 'quantity', Number(event.target.value))}
                                                    className="h-10 w-24 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                                />
                                                <span className="ml-1 text-[0.71875rem] text-[var(--muted)]">{UNIT_SHORT[line.unit] ?? ''}</span>
                                            </TD>
                                            <TD>
                                                {line.kind === 'service' ? (
                                                    <span className="text-[0.75rem] text-[var(--muted)]">Se alista por artículo</span>
                                                ) : (
                                                <select
                                                    value={line.substitution_policy}
                                                    onChange={(event) => updateLine(line.key, 'substitution_policy', event.target.value)}
                                                    className="h-10 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.78125rem] focus:border-[var(--accent)] focus:outline-none"
                                                >
                                                    {POLICIES.map((policy) => (
                                                        <option key={policy.value} value={policy.value}>
                                                            {policy.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                )}
                                            </TD>
                                            <TD align="right" className="whitespace-nowrap font-mono text-[0.8125rem] tabular">
                                                {money(Math.round(line.quantity * line.price))}
                                            </TD>
                                            <TD align="right">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    icon={<TrashIcon size={15} />}
                                                    onClick={() => setLines((current) => current.filter((item) => item.key !== line.key))}
                                                />
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                        {errors.items && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.items}</p>}
                    </Card>
                </div>

                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader title="Cliente y entrega" />
                        <div className="flex flex-col gap-4">
                            <Select
                                label="Cliente"
                                required
                                options={customers}
                                placeholder="Elige el cliente"
                                value={data.customer_id}
                                onChange={(event) => chooseCustomer(event.target.value)}
                                error={errors.customer_id}
                            />
                            <Input
                                label="Direccion de entrega"
                                required
                                value={data.delivery_address}
                                onChange={(event) => setData('delivery_address', event.target.value)}
                                error={errors.delivery_address}
                            />
                            <Textarea
                                label="Indicaciones"
                                value={data.delivery_notes}
                                onChange={(event) => setData('delivery_notes', event.target.value)}
                                error={errors.delivery_notes}
                            />
                            <Select
                                label="Franja de entrega"
                                options={slots
                                    .filter((slot) => !slot.full)
                                    .map((slot) => ({ value: slot.key, label: slot.label }))}
                                placeholder={slots.length === 0 ? 'La sede no maneja franjas' : 'Sin franja'}
                                disabled={slots.length === 0}
                                onChange={(event) => chooseSlot(event.target.value)}
                                error={errors.delivery_slot_start}
                            />
                            <Select
                                label="Medio de pago"
                                required
                                options={[
                                    { value: 'cash_on_delivery', label: 'Contraentrega' },
                                    { value: 'card', label: 'Tarjeta' },
                                    { value: 'transfer', label: 'Transferencia' },
                                    { value: 'credit', label: 'Credito' },
                                ]}
                                value={data.payment_method}
                                onChange={(event) => setData('payment_method', event.target.value)}
                                error={errors.payment_method}
                            />
                            <Input
                                label="Costo del domicilio"
                                type="number"
                                min={0}
                                value={data.delivery_fee}
                                onChange={(event) => setData('delivery_fee', Number(event.target.value))}
                                error={errors.delivery_fee}
                                prefix="$"
                            />
                        </div>

                        <div className="mt-4 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-3">
                            <div className="flex items-center justify-between text-[0.8125rem]">
                                <span className="text-[var(--muted)]">Articulos</span>
                                <span className="font-mono tabular">{number(lines.length)}</span>
                            </div>
                            <div className="mt-1 flex items-center justify-between text-[0.8125rem]">
                                <span className="text-[var(--muted)]">Subtotal</span>
                                <span className="font-mono tabular">{money(subtotal)}</span>
                            </div>
                            <div className="mt-1 flex items-center justify-between border-t border-[var(--line)] pt-2">
                                <span className="text-[0.875rem] font-semibold">Total</span>
                                <span className="font-mono text-[1.1875rem] font-semibold text-[var(--accent)] tabular">{money(subtotal + data.delivery_fee)}</span>
                            </div>
                        </div>

                        <Button type="submit" size="lg" block className="mt-4" loading={processing} disabled={lines.length === 0}>
                            Crear el pedido
                        </Button>
                    </Card>
                </div>
            </form>
        </AppLayout>
    );
}
