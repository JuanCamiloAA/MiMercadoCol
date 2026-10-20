import { useForm } from '@inertiajs/react';
import { PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { type FormEvent, type ReactNode, useEffect, useMemo } from 'react';
import { paymentIcon } from '@/components/payments/payment-icons';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input, Select, Textarea, Toggle } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { cn } from '@/lib/cn';

export interface KindOption {
    value: string;
    label: string;
    channels: string[];
    gateway: boolean;
    available: boolean;
}

export interface AccountOption {
    id: number;
    label: string;
    provider: string;
    is_enabled: boolean;
    capabilities: string[];
}

export interface StoreOption {
    id: number;
    name: string;
    registers: { id: number; name: string; code: string | null }[];
}

export interface Availability {
    channels?: string[];
    stores?: number[];
    channel_stores?: { online?: number[] };
    registers?: number[];
    schedule?: { days: number[]; from: string; to: string }[];
    valid_from?: string;
    valid_until?: string;
    min_amount?: number;
    max_amount?: number;
    delivery_modes?: string[];
    customer?: { registered?: boolean; credit_line?: boolean; store_credit?: boolean };
    combinable?: boolean;
}

export interface MethodRow {
    id: number;
    name: string;
    kind: string;
    kind_label: string;
    kind_channels: string[];
    /** Donde lo agrupa el checkout de la tienda. */
    online_group: string | null;
    system_key: string | null;
    is_system: boolean;
    gateway_account_id: number | null;
    gateway_label: string | null;
    icon: string;
    display: { description_pos: string; description_online: string; instructions: string; highlight: boolean };
    requires_reference: boolean;
    reference_label: string;
    fee_percent: number;
    fee_fixed: number;
    is_active: boolean;
    availability: Availability;
    used: number;
    warning: string | null;
}

interface Props {
    open: boolean;
    onClose: () => void;
    method: MethodRow | null;
    kinds: KindOption[];
    accounts: AccountOption[];
    stores: StoreOption[];
    icons: string[];
}

/** Lunes primero, como se lee un horario en Colombia. El valor es el de Carbon (0 = domingo). */
const DAYS = [
    { value: 1, label: 'Lu' },
    { value: 2, label: 'Ma' },
    { value: 3, label: 'Mi' },
    { value: 4, label: 'Ju' },
    { value: 5, label: 'Vi' },
    { value: 6, label: 'Sá' },
    { value: 0, label: 'Do' },
];

const CAPABILITY_FOR: Record<string, string[]> = {
    gateway_checkout: ['checkout'],
    gateway_link: ['link', 'qr'],
    gateway_terminal: ['terminal'],
};

const DEFAULT_ICON: Record<string, string> = {
    cash: 'money',
    card: 'credit-card',
    transfer: 'arrows-split',
    credit: 'lock-key',
    store_credit: 'wallet',
    gateway_checkout: 'globe',
    gateway_link: 'qr-code',
    gateway_terminal: 'credit-card',
    other: 'hand-coins',
};

function emptyAvailability(): Required<Pick<Availability, 'channels' | 'stores' | 'registers' | 'schedule' | 'delivery_modes'>> & {
    channel_stores: { online: number[] };
    valid_from: string;
    valid_until: string;
    min_amount: number | '';
    max_amount: number | '';
    customer: { registered: boolean; credit_line: boolean; store_credit: boolean };
    combinable: boolean;
} {
    return {
        channels: [],
        stores: [],
        channel_stores: { online: [] },
        registers: [],
        schedule: [],
        valid_from: '',
        valid_until: '',
        min_amount: '',
        max_amount: '',
        delivery_modes: [],
        customer: { registered: false, credit_line: false, store_credit: false },
        combinable: true,
    };
}

function initialData(method: MethodRow | null, kinds: KindOption[]) {
    const availability = method?.availability ?? {};
    const kind = method?.kind ?? kinds.find((option) => option.available)?.value ?? 'transfer';

    return {
        name: method?.name ?? '',
        kind,
        gateway_account_id: (method?.gateway_account_id ?? '') as number | '',
        icon: method?.icon ?? DEFAULT_ICON[kind] ?? 'hand-coins',
        display: {
            description_pos: method?.display.description_pos ?? '',
            description_online: method?.display.description_online ?? '',
            instructions: method?.display.instructions ?? '',
            highlight: method?.display.highlight ?? false,
        },
        requires_reference: method?.requires_reference ?? false,
        reference_label: method?.reference_label ?? '',
        fee_percent: method?.fee_percent ?? 0,
        fee_fixed: (method?.fee_fixed ?? '') as number | '',
        is_active: method?.is_active ?? true,
        availability: {
            ...emptyAvailability(),
            channels: availability.channels ?? [],
            stores: availability.stores ?? [],
            channel_stores: { online: availability.channel_stores?.online ?? [] },
            registers: availability.registers ?? [],
            schedule: availability.schedule ?? [],
            valid_from: availability.valid_from ?? '',
            valid_until: availability.valid_until ?? '',
            min_amount: (availability.min_amount ?? '') as number | '',
            max_amount: (availability.max_amount ?? '') as number | '',
            delivery_modes: availability.delivery_modes ?? [],
            customer: {
                registered: availability.customer?.registered ?? false,
                credit_line: availability.customer?.credit_line ?? false,
                store_credit: availability.customer?.store_credit ?? false,
            },
            combinable: availability.combinable ?? true,
        },
    };
}

/**
 * Crear o editar un medio de pago, con su bloque «Cuándo aparece». Vacío es «sin restricción»:
 * lo que no se marca no limita.
 */
export default function MethodFormModal({ open, onClose, method, kinds, accounts, stores, icons }: Props) {
    const form = useForm(initialData(method, kinds));
    const { data, setData, errors, processing } = form;

    useEffect(() => {
        if (open) {
            form.setDefaults(initialData(method, kinds));
            form.setData(initialData(method, kinds));
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, method?.id]);

    const kind = kinds.find((option) => option.value === data.kind);
    // Uno del sistema (Efectivo, Saldo a favor) no cambia de tipo; sus canales salen de la fila.
    const channelsForKind = kind?.channels ?? method?.kind_channels ?? [];
    const isGateway = kind?.gateway ?? false;
    const usesPos = channelsForKind.includes('pos') && (data.availability.channels.length === 0 || data.availability.channels.includes('pos'));
    const usesOnline = channelsForKind.includes('online') && (data.availability.channels.length === 0 || data.availability.channels.includes('online'));
    const compatibleAccounts = useMemo(
        () => accounts.filter((account) => (CAPABILITY_FOR[data.kind] ?? []).some((capability) => account.capabilities.includes(capability))),
        [accounts, data.kind],
    );
    const error = (key: string) => (errors as Record<string, string | undefined>)[key];

    const setAvailability = <K extends keyof typeof data.availability>(key: K, value: (typeof data.availability)[K]) => {
        setData('availability', { ...data.availability, [key]: value });
    };

    const toggleIn = <T,>(list: T[], value: T): T[] => (list.includes(value) ? list.filter((item) => item !== value) : [...list, value]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (method) {
            form.put(route('payment_methods.update', method.id), options);
        } else {
            form.post(route('payment_methods.store'), options);
        }
    };

    const kindOptions = method?.is_system
        ? [{ value: method.kind, label: method.kind_label }]
        : kinds.filter((option) => option.available || option.value === method?.kind).map((option) => ({ value: option.value, label: option.label }));

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={method ? `Editar «${method.name}»` : 'Nuevo medio de pago'}
            description="El tipo decide cómo se comporta (cambio, cupo, pasarela); el nombre es lo que lee el cajero o el cliente."
            size="xl"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" form="payment-method-form" loading={processing}>
                        {method ? 'Guardar' : 'Crear el medio'}
                    </Button>
                </>
            }
        >
            <form id="payment-method-form" onSubmit={submit} className="flex flex-col gap-5">
                <section className="grid gap-3 sm:grid-cols-2">
                    <Input label="Nombre" required value={data.name} maxLength={80} onChange={(event) => setData('name', event.target.value)} error={errors.name} placeholder="Nequi, Daviplata, Bono Sodexo…" />
                    <Select
                        label="Tipo"
                        required
                        options={kindOptions}
                        value={data.kind}
                        disabled={method?.is_system}
                        onChange={(event) => {
                            const next = event.target.value;
                            setData((current) => ({
                                ...current,
                                kind: next,
                                icon: current.icon === (DEFAULT_ICON[current.kind] ?? '') ? (DEFAULT_ICON[next] ?? current.icon) : current.icon,
                                availability: { ...current.availability, channels: current.availability.channels.filter((channel) => (kinds.find((option) => option.value === next)?.channels ?? []).includes(channel)) },
                            }));
                        }}
                        error={errors.kind}
                        hint={method?.is_system ? 'Es del sistema: no cambia de tipo.' : undefined}
                    />

                    {isGateway && (
                        <Select
                            label="Cuenta de pasarela"
                            required
                            options={compatibleAccounts.map((account) => ({ value: account.id, label: `${account.label}${account.is_enabled ? '' : ' (apagada)'}` }))}
                            placeholder={compatibleAccounts.length === 0 ? 'No hay una cuenta que ofrezca este cobro' : 'Elige la cuenta'}
                            value={data.gateway_account_id}
                            onChange={(event) => setData('gateway_account_id', event.target.value === '' ? '' : Number(event.target.value))}
                            error={errors.gateway_account_id}
                            hint="Las cuentas se conectan en la pestaña Pasarelas."
                        />
                    )}
                </section>

                <section>
                    <p className="mb-2 text-[0.8125rem] font-medium">Ícono</p>
                    <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Ícono">
                        {icons.map((name) => {
                            const IconComponent = paymentIcon(name);

                            return (
                                <button
                                    key={name}
                                    type="button"
                                    role="radio"
                                    aria-checked={data.icon === name}
                                    aria-label={name}
                                    onClick={() => setData('icon', name)}
                                    className={cn(
                                        'grid size-10 place-items-center rounded-control border transition-colors',
                                        data.icon === name ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] bg-[var(--surface-2)] text-[var(--muted)] hover:text-[var(--text)]',
                                    )}
                                >
                                    <IconComponent size={18} />
                                </button>
                            );
                        })}
                    </div>
                </section>

                <section className="grid gap-3 sm:grid-cols-2">
                    {channelsForKind.includes('pos') && (
                        <Input
                            label="Texto en la caja"
                            maxLength={80}
                            value={data.display.description_pos}
                            onChange={(event) => setData('display', { ...data.display, description_pos: event.target.value })}
                            error={error('display.description_pos')}
                            placeholder="Cobra con el celular del cliente"
                        />
                    )}
                    {channelsForKind.includes('online') && (
                        <Input
                            label="Texto en la tienda"
                            maxLength={120}
                            value={data.display.description_online}
                            onChange={(event) => setData('display', { ...data.display, description_online: event.target.value })}
                            error={error('display.description_online')}
                            placeholder="Paga al recibir con tu app"
                        />
                    )}
                    <div className="sm:col-span-2">
                        <Textarea
                            label="Instrucciones"
                            maxLength={500}
                            rows={3}
                            value={data.display.instructions}
                            onChange={(event) => setData('display', { ...data.display, instructions: event.target.value })}
                            error={error('display.instructions')}
                            hint="Se muestran al elegirlo: el número de cuenta, a quién transferir, qué mostrar al domiciliario."
                        />
                    </div>
                    <div className="sm:col-span-2">
                        <Toggle checked={data.display.highlight} onChange={(checked) => setData('display', { ...data.display, highlight: checked })} label="Destacarlo" hint="Sale resaltado en la caja y en la tienda." />
                    </div>
                </section>

                <section className="grid gap-3 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <Toggle
                            checked={data.requires_reference}
                            onChange={(checked) => setData('requires_reference', checked)}
                            label="Pedir una referencia al cobrar"
                            hint="El cajero no puede confirmar sin anotarla (voucher, número de aprobación)."
                        />
                    </div>
                    {data.requires_reference && (
                        <Input label="Qué se anota" required maxLength={60} value={data.reference_label} onChange={(event) => setData('reference_label', event.target.value)} error={errors.reference_label} placeholder="Número de aprobación" />
                    )}
                    <Input
                        label="Comisión (%)"
                        type="number"
                        min={0}
                        max={20}
                        step="0.01"
                        value={data.fee_percent}
                        onChange={(event) => setData('fee_percent', Number(event.target.value))}
                        error={errors.fee_percent}
                        hint="Estimada, para los informes. La real la dice la pasarela o el banco."
                    />
                    <Input
                        label="Comisión fija"
                        type="number"
                        min={0}
                        prefix="$"
                        value={data.fee_fixed}
                        onChange={(event) => setData('fee_fixed', event.target.value === '' ? '' : Number(event.target.value))}
                        error={errors.fee_fixed}
                    />
                </section>

                <section className="rounded-card border border-[var(--line)] bg-[var(--surface-2)] p-4">
                    <h3 className="text-[0.9375rem] font-semibold">Cuándo aparece</h3>
                    <p className="mb-4 text-[0.75rem] text-[var(--muted)]">Lo que dejes sin marcar no limita. El servidor vuelve a revisar todo al cobrar.</p>

                    <div className="flex flex-col gap-4">
                        {channelsForKind.length > 1 && (
                            <Group label="Dónde" error={error('availability.channels')}>
                                <Checkbox label="En la caja" checked={data.availability.channels.length === 0 || data.availability.channels.includes('pos')} onChange={() => setAvailability('channels', toggleChannel(data.availability.channels, 'pos', channelsForKind))} />
                                <Checkbox
                                    label="En la tienda en línea"
                                    checked={data.availability.channels.length === 0 || data.availability.channels.includes('online')}
                                    onChange={() => setAvailability('channels', toggleChannel(data.availability.channels, 'online', channelsForKind))}
                                />
                            </Group>
                        )}

                        {stores.length > 1 && (
                            <Group label="Sedes" hint={data.availability.stores.length === 0 ? 'Todas las sedes.' : undefined}>
                                {stores.map((store) => (
                                    <Checkbox key={store.id} label={store.name} checked={data.availability.stores.includes(store.id)} onChange={() => setAvailability('stores', toggleIn(data.availability.stores, store.id))} />
                                ))}
                            </Group>
                        )}

                        {usesOnline && usesPos && stores.length > 1 && (
                            <Group label="En la tienda en línea, solo en estas sedes" hint={data.availability.channel_stores.online.length === 0 ? 'Donde valga el medio.' : 'La contraentrega de una sola sede, por ejemplo.'}>
                                {stores.map((store) => (
                                    <Checkbox
                                        key={store.id}
                                        label={store.name}
                                        checked={data.availability.channel_stores.online.includes(store.id)}
                                        onChange={() => setAvailability('channel_stores', { online: toggleIn(data.availability.channel_stores.online, store.id) })}
                                    />
                                ))}
                            </Group>
                        )}

                        {usesPos && stores.some((store) => store.registers.length > 0) && (
                            <Group label="Cajas" hint={data.availability.registers.length === 0 ? 'Todas las cajas.' : undefined}>
                                {stores.flatMap((store) =>
                                    store.registers.map((register) => (
                                        <Checkbox
                                            key={register.id}
                                            label={`${register.name}${stores.length > 1 ? ` · ${store.name}` : ''}`}
                                            checked={data.availability.registers.includes(register.id)}
                                            onChange={() => setAvailability('registers', toggleIn(data.availability.registers, register.id))}
                                        />
                                    )),
                                )}
                            </Group>
                        )}

                        <div>
                            <div className="mb-2 flex items-center justify-between gap-2">
                                <p className="text-[0.8125rem] font-medium">
                                    Horario <span className="font-normal text-[var(--muted)]">{data.availability.schedule.length === 0 ? '· siempre' : '· hora de Colombia; el fin no cuenta'}</span>
                                </p>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    icon={<PlusIcon size={14} />}
                                    onClick={() => setAvailability('schedule', [...data.availability.schedule, { days: [1, 2, 3, 4, 5], from: '07:00', to: '21:00' }])}
                                    disabled={data.availability.schedule.length >= 21}
                                >
                                    Agregar franja
                                </Button>
                            </div>
                            <div className="flex flex-col gap-2">
                                {data.availability.schedule.map((slot, index) => (
                                    <div key={index} className="flex flex-wrap items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface)] p-2">
                                        <div className="flex gap-1">
                                            {DAYS.map((day) => (
                                                <button
                                                    key={day.value}
                                                    type="button"
                                                    aria-pressed={slot.days.includes(day.value)}
                                                    onClick={() => setAvailability('schedule', data.availability.schedule.map((item, position) => (position === index ? { ...item, days: toggleIn(item.days, day.value) } : item)))}
                                                    className={cn(
                                                        'h-8 w-9 rounded-chip border text-[0.75rem] font-medium',
                                                        slot.days.includes(day.value) ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] text-[var(--muted)]',
                                                    )}
                                                >
                                                    {day.label}
                                                </button>
                                            ))}
                                        </div>
                                        <input
                                            type="time"
                                            value={slot.from}
                                            aria-label="Desde"
                                            onChange={(event) => setAvailability('schedule', data.availability.schedule.map((item, position) => (position === index ? { ...item, from: event.target.value } : item)))}
                                            className="h-9 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-2 font-mono text-[0.8125rem]"
                                        />
                                        <span className="text-[0.8125rem] text-[var(--muted)]">a</span>
                                        <input
                                            type="time"
                                            value={slot.to === '24:00' ? '23:59' : slot.to}
                                            aria-label="Hasta"
                                            onChange={(event) => setAvailability('schedule', data.availability.schedule.map((item, position) => (position === index ? { ...item, to: event.target.value } : item)))}
                                            className="h-9 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-2 font-mono text-[0.8125rem]"
                                        />
                                        <button
                                            type="button"
                                            aria-label="Quitar la franja"
                                            onClick={() => setAvailability('schedule', data.availability.schedule.filter((_, position) => position !== index))}
                                            className="ml-auto grid size-8 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                        >
                                            <TrashIcon size={15} />
                                        </button>
                                        {(error(`availability.schedule.${index}.days`) ?? error(`availability.schedule.${index}.from`) ?? error(`availability.schedule.${index}.to`)) && (
                                            <p className="w-full text-[0.75rem] text-[var(--danger)]">
                                                {error(`availability.schedule.${index}.days`) ?? error(`availability.schedule.${index}.from`) ?? error(`availability.schedule.${index}.to`)}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Input label="Vigente desde" type="date" value={data.availability.valid_from} onChange={(event) => setAvailability('valid_from', event.target.value)} error={error('availability.valid_from')} />
                            <Input label="Vigente hasta" type="date" value={data.availability.valid_until} onChange={(event) => setAvailability('valid_until', event.target.value)} error={error('availability.valid_until')} />
                            <Input
                                label="Monto mínimo"
                                type="number"
                                min={0}
                                prefix="$"
                                value={data.availability.min_amount}
                                onChange={(event) => setAvailability('min_amount', event.target.value === '' ? '' : Number(event.target.value))}
                                error={error('availability.min_amount')}
                                hint="Lo que se cobra con este medio, no el total."
                            />
                            <Input
                                label="Monto máximo"
                                type="number"
                                min={0}
                                prefix="$"
                                value={data.availability.max_amount}
                                onChange={(event) => setAvailability('max_amount', event.target.value === '' ? '' : Number(event.target.value))}
                                error={error('availability.max_amount')}
                            />
                        </div>

                        {usesOnline && (
                            <Group label="Entrega en la tienda" hint={data.availability.delivery_modes.length === 0 ? 'Domicilio y recoger.' : undefined}>
                                <Checkbox label="Domicilio" checked={data.availability.delivery_modes.includes('delivery')} onChange={() => setAvailability('delivery_modes', toggleIn(data.availability.delivery_modes, 'delivery'))} />
                                <Checkbox label="Recoger en la tienda" checked={data.availability.delivery_modes.includes('pickup')} onChange={() => setAvailability('delivery_modes', toggleIn(data.availability.delivery_modes, 'pickup'))} />
                            </Group>
                        )}

                        <Group label="Cliente">
                            <Checkbox label="Pide un cliente registrado" checked={data.availability.customer.registered} onChange={(event) => setAvailability('customer', { ...data.availability.customer, registered: event.target.checked })} />
                            <Checkbox label="Pide cupo de crédito" checked={data.availability.customer.credit_line} onChange={(event) => setAvailability('customer', { ...data.availability.customer, credit_line: event.target.checked })} />
                            <Checkbox label="Pide saldo a favor" checked={data.availability.customer.store_credit} onChange={(event) => setAvailability('customer', { ...data.availability.customer, store_credit: event.target.checked })} />
                        </Group>

                        <Toggle
                            checked={data.availability.combinable}
                            onChange={(checked) => setAvailability('combinable', checked)}
                            label="Se combina con otros medios"
                            hint="Apagado, el cobro con este medio tiene que ser completo."
                        />
                    </div>
                </section>

                <Toggle checked={data.is_active} onChange={(checked) => setData('is_active', checked)} label="Encendido" hint="Apagado no aparece en ningún lado; lo ya cobrado no cambia." />
            </form>
        </Modal>
    );
}

/**
 * Marcar o desmarcar un canal. Vacío significa «todos los del tipo»: desmarcar uno deja solo los
 * otros, y marcarlos todos vuelve a vacío.
 */
function toggleChannel(current: string[], channel: string, allowed: string[]): string[] {
    const effective = current.length === 0 ? allowed : current;
    const next = effective.includes(channel) ? effective.filter((item) => item !== channel) : [...effective, channel];

    if (next.length === 0) {
        return current;
    }

    return next.length === allowed.length ? [] : next;
}

function Group({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
    return (
        <div>
            <p className="mb-2 text-[0.8125rem] font-medium">
                {label} {hint && <span className="font-normal text-[var(--muted)]">· {hint}</span>}
            </p>
            <div className="flex flex-wrap gap-x-5 gap-y-2">{children}</div>
            {error && <p className="mt-1.5 text-[0.75rem] text-[var(--danger)]">{error}</p>}
        </div>
    );
}
