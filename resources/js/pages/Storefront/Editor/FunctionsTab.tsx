import { Link, router } from '@inertiajs/react';
import { CheckCircleIcon, LockSimpleIcon, PlusIcon, TrashIcon, WarningCircleIcon, XCircleIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select, Textarea, Toggle } from '@/components/ui/Field';
import { Badge } from '@/components/ui/Misc';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { saveSetting } from './editor-api';
import { StructureSection } from './StructureForms';
import type { EditorContext, Endpoints, Issue, SettingsRow, SlotRow, StructureOptions, ZoneRow } from './types';

type Section = 'delivery' | 'coverage' | 'slots' | 'payments' | 'catalog' | 'category_page' | 'product_page' | 'search' | 'texts' | 'seo';

/** Lo de diseño (lo pone la plantilla si la sede la sigue) y lo operativo (siempre de la sede). */
const DESIGN: Section[] = ['catalog', 'category_page', 'product_page', 'search'];

const SECTIONS: { key: Section; label: string }[] = [
    { key: 'delivery', label: 'Domicilio' },
    { key: 'coverage', label: 'Cobertura' },
    { key: 'slots', label: 'Horarios y franjas' },
    { key: 'payments', label: 'Medios de pago' },
    { key: 'catalog', label: 'Catálogo en línea' },
    { key: 'category_page', label: 'Página de categoría' },
    { key: 'product_page', label: 'Página de producto' },
    { key: 'search', label: 'Búsqueda' },
    { key: 'texts', label: 'Textos y avisos' },
    { key: 'seo', label: 'Dominio y compartir' },
];

interface FunctionsTabProps {
    context: EditorContext;
    endpoints: Endpoints;
    structureOptions: StructureOptions;
    /** Sigue la plantilla: catálogo, páginas y búsqueda son de la plantilla. */
    locked: boolean;
    onPersonalize: () => void;
    settings: SettingsRow;
    zones: ZoneRow[];
    slots: SlotRow[];
    checks: { blocking: Issue[]; warnings: Issue[] };
    readOnly: boolean;
    canSeeCoupons: boolean;
    storeName: string;
    slug: string;
    onSaved: () => void;
}

export function FunctionsTab({
    context,
    endpoints,
    structureOptions,
    locked,
    onPersonalize,
    settings,
    zones,
    slots,
    checks,
    readOnly,
    canSeeCoupons,
    storeName,
    slug,
    onSaved,
}: FunctionsTabProps) {
    // En la plantilla solo hay diseño: el domicilio, los pagos y lo demás es de cada sede.
    const sections = context === 'template' ? SECTIONS.filter((item) => DESIGN.includes(item.key)) : SECTIONS;
    const [section, setSection] = useState<Section>(sections[0].key);

    const save = (key: Section, value: Record<string, unknown>) => saveSetting(endpoints, key, value, onSaved);

    return (
        <div className={cn('grid gap-4', context === 'store' ? 'lg:grid-cols-[212px_minmax(0,1fr)_300px]' : 'lg:grid-cols-[212px_minmax(0,1fr)]')}>
            <nav className="h-fit rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-1.5">
                <ul className="flex flex-col gap-0.5">
                    {sections.map((item) => (
                        <li key={item.key}>
                            <button
                                type="button"
                                onClick={() => setSection(item.key)}
                                className={cn(
                                    'flex min-h-[40px] w-full items-center rounded-[9px] px-3 text-left text-[13px] font-medium transition-colors',
                                    section === item.key
                                        ? 'bg-[var(--accent-soft)] text-[var(--accent)]'
                                        : 'text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]',
                                )}
                            >
                                {item.label}
                            </button>
                        </li>
                    ))}
                    {canSeeCoupons && context === 'store' && (
                        <li>
                            <Link
                                href={route('coupons.index')}
                                className="flex min-h-[40px] w-full items-center rounded-[9px] px-3 text-left text-[13px] font-medium text-[var(--muted)] transition-colors hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                            >
                                Cupones
                            </Link>
                        </li>
                    )}
                </ul>
            </nav>

            <div className="min-w-0">
                {section === 'delivery' && <DeliverySection settings={settings} readOnly={readOnly} onSave={save} />}
                {section === 'coverage' && <CoverageSection settings={settings} zones={zones} readOnly={readOnly} onSave={save} onSaved={onSaved} />}
                {section === 'slots' && <SlotsSection settings={settings} slots={slots} readOnly={readOnly} onSave={save} onSaved={onSaved} />}
                {section === 'payments' && <PaymentsSection settings={settings} readOnly={readOnly} onSave={save} />}
                {DESIGN.includes(section) && (
                    <div className="flex flex-col gap-2">
                        {locked && !readOnly && (
                            <p className="flex items-start gap-2 rounded-[12px] border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2.5 text-[12.5px] text-[var(--muted)]">
                                <LockSimpleIcon size={16} className="mt-px shrink-0" />
                                <span className="flex-1">Esto lo pone la plantilla. Para cambiarlo, personaliza la tienda.</span>
                                <button type="button" onClick={onPersonalize} className="shrink-0 font-semibold text-[var(--accent)] hover:underline">
                                    Personalizar
                                </button>
                            </p>
                        )}
                        <StructureSection
                            key={section}
                            settingKey={section as 'catalog' | 'category_page' | 'product_page' | 'search'}
                            initial={settings[section as 'catalog']}
                            readOnly={readOnly}
                            designLocked={locked}
                            options={structureOptions}
                            endpoints={endpoints}
                            onSaved={onSaved}
                        />
                    </div>
                )}
                {section === 'texts' && <TextsSection settings={settings} readOnly={readOnly} onSave={save} />}
                {section === 'seo' && <SeoSection settings={settings} slug={slug} readOnly={readOnly} onSave={save} />}
            </div>

            {context === 'store' && (
            <div className="flex h-fit flex-col gap-4">
                <Card>
                    <CardHeader title="Resumen de la sede" />
                    <dl className="flex flex-col gap-1.5 text-[12.5px]">
                        <Row label="Sede" value={storeName} />
                        <Row label="Domicilio" value={settings.delivery.enabled ? money(settings.delivery.fee) : 'Apagado'} />
                        <Row label="Pedido mínimo" value={settings.delivery.min_order_total > 0 ? money(settings.delivery.min_order_total) : 'Sin mínimo'} />
                        <Row label="Gratis desde" value={settings.delivery.free_from > 0 ? money(settings.delivery.free_from) : 'Nunca'} />
                        <Row label="Zonas activas" value={String(zones.filter((zone) => zone.is_active).length)} />
                        <Row label="Franjas activas" value={settings.slots.enabled ? String(slots.filter((slot) => slot.is_active).length) : 'Apagadas'} />
                    </dl>
                </Card>

                <Checklist checks={checks} />
            </div>
            )}
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-[var(--muted)]">{label}</dt>
            <dd className="font-mono tabular text-[var(--text)]">{value}</dd>
        </div>
    );
}

export function Checklist({ checks }: { checks: { blocking: Issue[]; warnings: Issue[] } }) {
    const ready = checks.blocking.length === 0;

    return (
        <Card>
            <CardHeader title="Antes de publicar" />

            {ready && checks.warnings.length === 0 ? (
                <p className="flex items-center gap-2 text-[13px] text-[var(--accent)]">
                    <CheckCircleIcon size={17} weight="fill" />
                    Todo en orden.
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {checks.blocking.map((issue, index) => (
                        <li key={`b-${index}`} className="flex items-start gap-2 text-[12.5px] text-[var(--danger)]">
                            <XCircleIcon size={15} weight="fill" className="mt-px shrink-0" />
                            <span>
                                {issue.message}
                                {issue.hint && <span className="block text-[11px] text-[var(--muted)]">{issue.hint}</span>}
                            </span>
                        </li>
                    ))}
                    {checks.warnings.map((issue, index) => (
                        <li key={`w-${index}`} className="flex items-start gap-2 text-[12.5px] text-[var(--warn)]">
                            <WarningCircleIcon size={15} weight="fill" className="mt-px shrink-0" />
                            <span>
                                {issue.message}
                                {issue.hint && <span className="block text-[11px] text-[var(--muted)]">{issue.hint}</span>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {!ready && (
                <p className="mt-3 border-t border-[var(--line)] pt-2 text-[11.5px] text-[var(--muted)]">
                    Lo marcado en rojo impide publicar. Lo amarillo solo conviene saberlo.
                </p>
            )}
        </Card>
    );
}

interface SectionProps {
    settings: SettingsRow;
    readOnly: boolean;
    onSave: (key: Section, value: Record<string, unknown>) => void;
}

function DeliverySection({ settings, readOnly, onSave }: SectionProps) {
    const [value, setValue] = useState(settings.delivery);

    const commit = (next: typeof value) => {
        setValue(next);
        onSave('delivery', next);
    };

    return (
        <Card>
            <CardHeader title="Domicilio" description="Lo que se cobra por llevar el pedido y desde cuánto sale gratis." />

            <div className="flex flex-col gap-3">
                <Toggle
                    label="Hacemos domicilios"
                    checked={value.enabled}
                    disabled={readOnly}
                    onChange={(next) => commit({ ...value, enabled: next })}
                />

                <div className="grid gap-3 sm:grid-cols-2">
                    <Input
                        label="Tarifa"
                        type="number"
                        min={0}
                        prefix="$"
                        disabled={readOnly || !value.enabled}
                        value={String(value.fee)}
                        onChange={(event) => setValue({ ...value, fee: Number(event.target.value) })}
                        onBlur={() => commit(value)}
                    />
                    <Input
                        label="Pedido mínimo"
                        type="number"
                        min={0}
                        prefix="$"
                        disabled={readOnly || !value.enabled}
                        value={String(value.min_order_total)}
                        onChange={(event) => setValue({ ...value, min_order_total: Number(event.target.value) })}
                        onBlur={() => commit(value)}
                        hint="0 = sin mínimo."
                    />
                    <Input
                        label="Gratis desde"
                        type="number"
                        min={0}
                        prefix="$"
                        disabled={readOnly || !value.enabled}
                        value={String(value.free_from)}
                        onChange={(event) => setValue({ ...value, free_from: Number(event.target.value) })}
                        onBlur={() => commit(value)}
                        hint="0 = nunca es gratis."
                    />
                    <Input
                        label="Tiempo estimado"
                        type="number"
                        min={5}
                        max={240}
                        suffix="min"
                        disabled={readOnly || !value.enabled}
                        value={String(value.eta_minutes)}
                        onChange={(event) => setValue({ ...value, eta_minutes: Number(event.target.value) })}
                        onBlur={() => commit(value)}
                    />
                </div>

                <Toggle
                    label="El cliente puede recoger en la tienda"
                    checked={value.pickup}
                    disabled={readOnly}
                    onChange={(next) => commit({ ...value, pickup: next })}
                />
                <Toggle
                    label="Ofrecer propina al domiciliario"
                    checked={value.tip}
                    disabled={readOnly}
                    onChange={(next) => commit({ ...value, tip: next })}
                />
            </div>
        </Card>
    );
}

function CoverageSection({
    settings,
    zones,
    readOnly,
    onSave,
    onSaved,
}: SectionProps & { zones: ZoneRow[]; onSaved: () => void }) {
    const [value, setValue] = useState(settings.coverage);
    const [form, setForm] = useState({ name: '', fee: '0', eta_minutes: '40' });

    return (
        <Card>
            <CardHeader title="Cobertura" description="Hasta dónde llega el domicilio y cuánto cuesta en cada zona." />

            <Select
                label="Cómo se define"
                options={[
                    { value: 'zones', label: 'Por barrios, cada uno con su tarifa' },
                    { value: 'radius', label: 'Por distancia desde la tienda' },
                ]}
                disabled={readOnly}
                value={value.mode}
                onChange={(event) => {
                    const next = { ...value, mode: event.target.value as 'zones' | 'radius' };
                    setValue(next);
                    onSave('coverage', next);
                }}
            />

            {value.mode === 'radius' ? (
                <Input
                    label="Radio"
                    type="number"
                    min={0.5}
                    max={50}
                    step={0.5}
                    suffix="km"
                    className="mt-3"
                    disabled={readOnly}
                    value={String(value.radius_km)}
                    onChange={(event) => setValue({ ...value, radius_km: Number(event.target.value) })}
                    onBlur={() => onSave('coverage', value)}
                    hint="Se mide en línea recta desde la dirección de la sede."
                />
            ) : (
                <div className="mt-4">
                    <p className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-[var(--muted)]">Barrios</p>

                    {zones.length === 0 ? (
                        <p className="rounded-[10px] border border-dashed border-[var(--line-2)] px-3 py-4 text-center text-[12.5px] text-[var(--muted)]">
                            Sin zonas no se puede publicar con el domicilio encendido.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-1.5">
                            {zones.map((zone) => (
                                <li
                                    key={zone.id}
                                    className="flex items-center gap-2 rounded-[10px] border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2"
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13px] font-medium">{zone.name}</span>
                                        <span className="block text-[11.5px] text-[var(--muted)]">
                                            {money(zone.fee)} · {zone.eta_minutes} min
                                        </span>
                                    </span>

                                    {!readOnly && (
                                        <>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.patch(
                                                        route('storefront.editor.zones.update', zone.id),
                                                        { ...zone, is_active: !zone.is_active },
                                                        { preserveScroll: true, onSuccess: onSaved },
                                                    )
                                                }
                                                className="min-h-[34px] rounded-[7px] px-2 text-[11.5px] font-medium text-[var(--muted)] hover:text-[var(--text)]"
                                            >
                                                {zone.is_active ? 'Activa' : 'Apagada'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.delete(route('storefront.editor.zones.destroy', zone.id), {
                                                        preserveScroll: true,
                                                        onSuccess: onSaved,
                                                    })
                                                }
                                                aria-label="Eliminar"
                                                className="grid size-9 place-items-center rounded-[7px] text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                            >
                                                <TrashIcon size={14} />
                                            </button>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {!readOnly && (
                        <div className="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_110px_110px_auto]">
                            <Input
                                placeholder="Barrio"
                                value={form.name}
                                onChange={(event) => setForm({ ...form, name: event.target.value })}
                            />
                            <Input
                                type="number"
                                min={0}
                                prefix="$"
                                value={form.fee}
                                onChange={(event) => setForm({ ...form, fee: event.target.value })}
                            />
                            <Input
                                type="number"
                                min={5}
                                suffix="min"
                                value={form.eta_minutes}
                                onChange={(event) => setForm({ ...form, eta_minutes: event.target.value })}
                            />
                            <Button
                                icon={<PlusIcon size={15} weight="bold" />}
                                disabled={form.name.trim() === ''}
                                onClick={() =>
                                    router.post(
                                        route('storefront.editor.zones.store'),
                                        {
                                            name: form.name,
                                            fee: Number(form.fee),
                                            eta_minutes: Number(form.eta_minutes),
                                            is_active: true,
                                        },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setForm({ name: '', fee: '0', eta_minutes: '40' });
                                                onSaved();
                                            },
                                        },
                                    )
                                }
                            >
                                Agregar
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
}

function SlotsSection({ settings, slots, readOnly, onSave, onSaved }: SectionProps & { slots: SlotRow[]; onSaved: () => void }) {
    const [enabled, setEnabled] = useState(settings.slots.enabled);
    const [form, setForm] = useState({ weekday: '1', starts_at: '08:00', ends_at: '12:00', capacity: '10' });

    return (
        <Card>
            <CardHeader title="Horarios y franjas" description="Ventanas de entrega con su cupo. Al llenarse dejan de ofrecerse." />

            <Toggle
                label="El cliente elige franja de entrega"
                checked={enabled}
                disabled={readOnly}
                onChange={(next) => {
                    setEnabled(next);
                    onSave('slots', { enabled: next });
                }}
            />

            {enabled && (
                <div className="mt-4">
                    {slots.length === 0 ? (
                        <p className="rounded-[10px] border border-dashed border-[var(--line-2)] px-3 py-4 text-center text-[12.5px] text-[var(--muted)]">
                            Con las franjas encendidas hace falta al menos una activa para poder publicar.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-1.5">
                            {slots.map((slot) => (
                                <li
                                    key={slot.id}
                                    className="flex items-center gap-2 rounded-[10px] border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2"
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13px] font-medium">
                                            {slot.weekday_label} {slot.starts_at} a {slot.ends_at}
                                        </span>
                                        <span className="block text-[11.5px] text-[var(--muted)]">Cupo {slot.capacity} pedidos</span>
                                    </span>

                                    {!readOnly && (
                                        <>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.patch(
                                                        route('storefront.editor.slots.update', slot.id),
                                                        {
                                                            weekday: slot.weekday,
                                                            starts_at: slot.starts_at,
                                                            ends_at: slot.ends_at,
                                                            capacity: slot.capacity,
                                                            is_active: !slot.is_active,
                                                        },
                                                        { preserveScroll: true, onSuccess: onSaved },
                                                    )
                                                }
                                                className="min-h-[34px] rounded-[7px] px-2 text-[11.5px] font-medium text-[var(--muted)] hover:text-[var(--text)]"
                                            >
                                                {slot.is_active ? 'Activa' : 'Apagada'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.delete(route('storefront.editor.slots.destroy', slot.id), {
                                                        preserveScroll: true,
                                                        onSuccess: onSaved,
                                                    })
                                                }
                                                aria-label="Eliminar"
                                                className="grid size-9 place-items-center rounded-[7px] text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                            >
                                                <TrashIcon size={14} />
                                            </button>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {!readOnly && (
                        <div className="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_100px_100px_90px_auto]">
                            <Select
                                options={[
                                    { value: '1', label: 'Lunes' },
                                    { value: '2', label: 'Martes' },
                                    { value: '3', label: 'Miércoles' },
                                    { value: '4', label: 'Jueves' },
                                    { value: '5', label: 'Viernes' },
                                    { value: '6', label: 'Sábado' },
                                    { value: '0', label: 'Domingo' },
                                ]}
                                value={form.weekday}
                                onChange={(event) => setForm({ ...form, weekday: event.target.value })}
                            />
                            <Input type="time" value={form.starts_at} onChange={(event) => setForm({ ...form, starts_at: event.target.value })} />
                            <Input type="time" value={form.ends_at} onChange={(event) => setForm({ ...form, ends_at: event.target.value })} />
                            <Input
                                type="number"
                                min={1}
                                value={form.capacity}
                                onChange={(event) => setForm({ ...form, capacity: event.target.value })}
                            />
                            <Button
                                icon={<PlusIcon size={15} weight="bold" />}
                                onClick={() =>
                                    router.post(
                                        route('storefront.editor.slots.store'),
                                        {
                                            weekday: Number(form.weekday),
                                            starts_at: form.starts_at,
                                            ends_at: form.ends_at,
                                            capacity: Number(form.capacity),
                                            is_active: true,
                                        },
                                        { preserveScroll: true, onSuccess: onSaved },
                                    )
                                }
                            >
                                Agregar
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
}

function PaymentsSection({ settings, readOnly, onSave }: SectionProps) {
    const [value, setValue] = useState(settings.payments);

    const commit = (next: typeof value) => {
        setValue(next);
        onSave('payments', next);
    };

    const none = !Object.values(value).some(Boolean);

    return (
        <Card>
            <CardHeader title="Medios de pago" description="Con qué puede pagar el cliente al hacer el pedido." />

            <div className="flex flex-col gap-3">
                <Toggle
                    label="Efectivo contraentrega"
                    checked={value.cash_on_delivery}
                    disabled={readOnly}
                    onChange={(next) => commit({ ...value, cash_on_delivery: next })}
                />
                <Toggle
                    label="Tarjeta al recibir"
                    hint="El domiciliario lleva el datáfono."
                    checked={value.card_on_delivery}
                    disabled={readOnly}
                    onChange={(next) => commit({ ...value, card_on_delivery: next })}
                />
                <Toggle
                    label="Transferencia"
                    checked={value.transfer}
                    disabled={readOnly}
                    onChange={(next) => commit({ ...value, transfer: next })}
                />
                <Toggle
                    label="Crédito del cliente"
                    hint="Solo se le ofrece a quien tenga cupo y esté al día."
                    checked={value.customer_credit}
                    disabled={readOnly}
                    onChange={(next) => commit({ ...value, customer_credit: next })}
                />
            </div>

            {none && (
                <p className="mt-3 rounded-[9px] bg-[var(--danger-soft)] px-3 py-2 text-[12.5px] font-medium text-[var(--danger)]">
                    Sin ningún medio habilitado el cliente no puede terminar el pedido, y la tienda no se puede publicar.
                </p>
            )}
        </Card>
    );
}

function TextsSection({ settings, readOnly, onSave }: SectionProps) {
    const [value, setValue] = useState(settings.texts);

    return (
        <Card>
            <CardHeader title="Textos y avisos" description="Lo que se le dice al cliente en el momento de pedir." />

            <Textarea
                label="Nota del domicilio"
                rows={3}
                maxLength={200}
                disabled={readOnly}
                value={value.delivery_note ?? ''}
                onChange={(event) => setValue({ ...value, delivery_note: event.target.value })}
                onBlur={() => onSave('texts', value)}
                hint="Aparece en el carrito y en el pago."
                placeholder="Los domicilios salen cada hora en punto."
            />
        </Card>
    );
}

function SeoSection({ settings, slug, readOnly, onSave }: SectionProps & { slug: string }) {
    const [value, setValue] = useState(settings.seo);

    return (
        <Card>
            <CardHeader title="Dominio y compartir" description="Cómo se ve la tienda cuando alguien comparte el enlace." />

            <div className="mb-3 rounded-[10px] bg-[var(--surface-2)] px-3 py-2">
                <p className="text-[11.5px] uppercase tracking-wide text-[var(--muted)]">Dirección</p>
                <p className="font-mono text-[13px]">/tienda · sede {slug}</p>
            </div>

            <div className="flex flex-col gap-3">
                <Input
                    label="Título"
                    maxLength={70}
                    disabled={readOnly}
                    value={value.title ?? ''}
                    onChange={(event) => setValue({ ...value, title: event.target.value })}
                    onBlur={() => onSave('seo', value)}
                />
                <Textarea
                    label="Descripción"
                    rows={2}
                    maxLength={160}
                    disabled={readOnly}
                    value={value.description ?? ''}
                    onChange={(event) => setValue({ ...value, description: event.target.value })}
                    onBlur={() => onSave('seo', value)}
                    hint="Es el texto que aparece debajo del título al compartir."
                />
            </div>

            <p className="mt-3 flex items-center gap-1.5 text-[12px] text-[var(--muted)]">
                <Badge tone="neutral">Próximamente</Badge>
                El dominio propio se configura con el proveedor de hosting.
            </p>
        </Card>
    );
}
