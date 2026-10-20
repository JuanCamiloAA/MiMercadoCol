import { ArrowDownIcon, ArrowUpIcon, LockSimpleIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/Button';
import { Input, Select, Textarea, Toggle } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import { ChipPicker, IconField, TargetField } from './BlockFields';
import { saveSetting, useAutosave } from './editor-api';
import type { Endpoints, Option, SettingsKey, SettingsRow, StructureOptions, TargetValue } from './types';

/** Lo que una sección de la estructura sabe hacer: mostrar su valor y guardarlo solo. */
interface SectionProps<K extends SettingsKey> {
    value: SettingsRow[K];
    onChange: (value: SettingsRow[K]) => void;
    readOnly: boolean;
    options: StructureOptions;
    /** Con la plantilla: el diseño no se toca, lo de la sede (contacto, redes, legal) sí. */
    designLocked?: boolean;
}

export const STRUCTURE_LABELS: Record<string, { title: string; description: string }> = {
    topbar: { title: 'Barra superior', description: 'La franja delgada de arriba: domicilio, horario, la próxima franja.' },
    header: { title: 'Encabezado', description: 'Marca, «Recibir en», el buscador grande, la cuenta y el carrito.' },
    nav: { title: 'Menú de categorías', description: 'El chip de ofertas en rojo y las categorías con su ícono.' },
    cart_bar: { title: 'Barra del carrito', description: 'La barra flotante con el total y lo que falta para el domicilio gratis.' },
    mobile_nav: { title: 'Navegación del celular', description: 'Los botones de abajo en el celular: de 3 a 5.' },
    footer: { title: 'Pie de página', description: 'Enlaces, contacto, redes, medios de pago y textos legales.' },
    floating: { title: 'Botón de WhatsApp', description: 'Un botón flotante que abre el chat con la sede.' },
    card: { title: 'Tarjeta de producto', description: 'Cómo se ve cada artículo en toda la tienda.' },
    catalog: { title: 'Catálogo en línea', description: 'Qué artículos se muestran y con qué datos.' },
    category_page: { title: 'Página de categoría', description: 'Filtros, orden y paginación de categorías, ofertas y búsqueda.' },
    product_page: { title: 'Página de producto', description: 'Lo que acompaña la ficha de cada artículo.' },
    search: { title: 'Búsqueda', description: 'Sugerencias mientras se escribe y búsquedas populares.' },
};

function Group({ title, children }: { title: string; children: ReactNode }) {
    return (
        <fieldset className="flex flex-col gap-3 border-t border-[var(--line)] pt-3 first:border-t-0 first:pt-0">
            <legend className="mb-1 text-[11.5px] font-semibold uppercase tracking-wide text-[var(--muted)]">{title}</legend>
            {children}
        </fieldset>
    );
}

function Choice({ label, value, options, onChange, disabled, hint }: { label: string; value: string | number; options: Option[]; onChange: (value: string) => void; disabled: boolean; hint?: string }) {
    return <Select label={label} hint={hint} options={options} value={String(value)} disabled={disabled} onChange={(event) => onChange(event.target.value)} />;
}

function Mover({ index, total, onMove, onRemove, disabled }: { index: number; total: number; onMove: (to: number) => void; onRemove: () => void; disabled: boolean }) {
    if (disabled) {
        return null;
    }

    return (
        <span className="flex items-center gap-0.5">
            <button type="button" disabled={index === 0} onClick={() => onMove(index - 1)} aria-label="Subir" className="grid size-8 place-items-center rounded-[6px] text-[var(--muted)] hover:text-[var(--text)] disabled:opacity-30">
                <ArrowUpIcon size={14} />
            </button>
            <button type="button" disabled={index === total - 1} onClick={() => onMove(index + 1)} aria-label="Bajar" className="grid size-8 place-items-center rounded-[6px] text-[var(--muted)] hover:text-[var(--text)] disabled:opacity-30">
                <ArrowDownIcon size={14} />
            </button>
            <button type="button" onClick={onRemove} aria-label="Quitar" className="grid size-8 place-items-center rounded-[6px] text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]">
                <TrashIcon size={13} />
            </button>
        </span>
    );
}

function move<T>(list: T[], from: number, to: number): T[] {
    const next = [...list];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);

    return next;
}

function TopbarForm({ value, onChange, readOnly, options }: SectionProps<'topbar'>) {
    const set = (patch: Partial<SettingsRow['topbar']>) => onChange({ ...value, ...patch });
    const setMessage = (index: number, patch: Partial<SettingsRow['topbar']['messages'][number]>) =>
        set({ messages: value.messages.map((message, position) => (position === index ? { ...message, ...patch } : message)) });

    return (
        <div className="flex flex-col gap-4">
            <Toggle label="Mostrar la barra superior" checked={value.enabled} disabled={readOnly} onChange={(enabled) => set({ enabled })} />
            <div className="grid gap-3 sm:grid-cols-2">
                <Choice label="Color" value={value.tone} options={options.topbar_tones} disabled={readOnly} onChange={(tone) => set({ tone })} />
                <Choice
                    label="Mensajes"
                    value={value.rotate_seconds}
                    options={options.choices.rotate_seconds ?? []}
                    disabled={readOnly}
                    onChange={(seconds) => set({ rotate_seconds: Number(seconds) })}
                    hint="En celular rotan; en computador caben todos."
                />
            </div>

            <div className="rounded-[10px] bg-[var(--surface-2)] px-3 py-2 text-[12px] text-[var(--muted)]">
                Puedes usar {options.topbar_variables.map((variable) => `{${variable}}`).join(', ')}: se llenan con lo publicado de la sede. Si una
                no tiene valor (por ejemplo, sin domicilio gratis), su mensaje no se muestra.
            </div>

            <div className="flex flex-col gap-2">
                {value.messages.map((message, index) => (
                    <div key={index} className="flex flex-col gap-2.5 rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)] p-2.5">
                        <div className="flex items-center justify-between">
                            <span className="text-[11.5px] font-semibold uppercase tracking-wide text-[var(--muted)]">Mensaje {index + 1}</span>
                            <Mover
                                index={index}
                                total={value.messages.length}
                                disabled={readOnly}
                                onMove={(to) => set({ messages: move(value.messages, index, to) })}
                                onRemove={() => set({ messages: value.messages.filter((_, position) => position !== index) })}
                            />
                        </div>
                        <Input label="Texto" maxLength={90} disabled={readOnly} value={message.text} onChange={(event) => setMessage(index, { text: event.target.value })} />
                        <div className="flex flex-wrap gap-1">
                            {options.topbar_variables.map((variable) => (
                                <button
                                    key={variable}
                                    type="button"
                                    disabled={readOnly}
                                    onClick={() => setMessage(index, { text: `${message.text}${message.text.endsWith(' ') || message.text === '' ? '' : ' '}{${variable}}` })}
                                    className="rounded-[7px] border border-[var(--line)] bg-[var(--surface)] px-2 py-1 font-mono text-[11px] text-[var(--muted)] hover:text-[var(--text)]"
                                >
                                    {`{${variable}}`}
                                </button>
                            ))}
                        </div>
                        <IconField label="Ícono" value={message.icon ?? ''} readOnly={readOnly} allowEmpty onChange={(icon) => setMessage(index, { icon })} />
                        <TargetField label="A dónde lleva" value={message.target} readOnly={readOnly} onChange={(target) => setMessage(index, { target })} />
                    </div>
                ))}
            </div>

            {!readOnly && value.messages.length < 6 && (
                <Button variant="secondary" size="sm" icon={<PlusIcon size={14} weight="bold" />} onClick={() => set({ messages: [...value.messages, { icon: 'Truck', text: '', target: null }] })}>
                    Agregar mensaje
                </Button>
            )}
        </div>
    );
}

function HeaderForm({ value, onChange, readOnly, options }: SectionProps<'header'>) {
    const set = (patch: Partial<SettingsRow['header']>) => onChange({ ...value, ...patch });

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <Choice label="Estilo" value={value.style} options={options.header_styles} disabled={readOnly} onChange={(style) => set({ style })} />
                <Choice label="Marca" value={value.logo_mode} options={options.logo_modes} disabled={readOnly} onChange={(logo_mode) => set({ logo_mode })} hint="El logo y el isotipo se suben en Aspecto." />
                <Choice label="Distribución" value={value.layout ?? 'search'} options={options.choices.header_layout ?? []} disabled={readOnly} onChange={(layout) => set({ layout })} />
                <Choice label="Buscador" value={value.search_style ?? 'pill'} options={options.choices.search_style ?? []} disabled={readOnly} onChange={(search_style) => set({ search_style })} />
            </div>
            <Input label="Texto del buscador" maxLength={60} disabled={readOnly} value={value.search_placeholder} onChange={(event) => set({ search_placeholder: event.target.value })} />
            <Group title="Sobre la portada y al bajar">
                <Toggle
                    label="Transparente sobre la portada"
                    hint="Solo si el primer bloque de la portada es un banner principal de borde a borde (Diseño › Marco). En las demás páginas va sólido."
                    checked={value.overlay ?? false}
                    disabled={readOnly}
                    onChange={(overlay) => set({ overlay })}
                />
                <Choice label="Al bajar" value={value.sticky_style ?? 'solid'} options={options.choices.sticky_style ?? []} disabled={readOnly || !value.sticky} onChange={(sticky_style) => set({ sticky_style })} hint="Cómo se ve el encabezado fijo cuando ya se bajó." />
            </Group>
            <Group title="Qué lleva">
                <Toggle label="Fijo arriba al desplazar" checked={value.sticky} disabled={readOnly} onChange={(sticky) => set({ sticky })} />
                <Toggle label="Mostrar el lema" checked={value.show_tagline} disabled={readOnly} onChange={(show_tagline) => set({ show_tagline })} />
                <Toggle label="«Recibir en»: sede y modo de entrega" checked={value.show_location} disabled={readOnly} onChange={(show_location) => set({ show_location })} />
                <Toggle label="Entrar / Mi cuenta" checked={value.show_account} disabled={readOnly} onChange={(show_account) => set({ show_account })} />
                <Toggle label="El carrito muestra el total" checked={value.cart_shows_total} disabled={readOnly} onChange={(cart_shows_total) => set({ cart_shows_total })} />
            </Group>
        </div>
    );
}

function NavForm({ value, onChange, readOnly, options }: SectionProps<'nav'>) {
    const set = (patch: Partial<SettingsRow['nav']>) => onChange({ ...value, ...patch });
    const setItem = (index: number, patch: Partial<SettingsRow['nav']['items'][number]>) =>
        set({ items: value.items.map((item, position) => (position === index ? { ...item, ...patch } : item)) });

    return (
        <div className="flex flex-col gap-4">
            <Toggle label="Mostrar el menú de categorías" checked={value.enabled} disabled={readOnly} onChange={(enabled) => set({ enabled })} />
            <div className="grid gap-3 sm:grid-cols-2">
                <Choice label="Estilo" value={value.style ?? 'bar'} options={options.choices.nav_style ?? []} disabled={readOnly} onChange={(style) => set({ style })} hint="En texto va dentro del encabezado si la distribución es «en una fila»." />
                <Choice label="La página actual" value={value.active_style ?? 'none'} options={options.choices.nav_active_style ?? []} disabled={readOnly} onChange={(active_style) => set({ active_style })} />
            </div>
            <Group title="Ofertas y categorías">
                <Toggle label="Chip de ofertas en rojo" checked={value.show_deals_chip} disabled={readOnly} onChange={(show_deals_chip) => set({ show_deals_chip })} />
                {value.show_deals_chip && <Input label="Texto del chip" maxLength={24} disabled={readOnly} value={value.deals_label} onChange={(event) => set({ deals_label: event.target.value })} />}
                <Toggle label="Botón «Todas las categorías»" checked={value.show_all_categories} disabled={readOnly} onChange={(show_all_categories) => set({ show_all_categories })} />
                <Toggle label="Menú grande con subcategorías (computador)" checked={value.mega_menu} disabled={readOnly} onChange={(mega_menu) => set({ mega_menu })} />
            </Group>

            <Group title="Enlaces del menú">
                <p className="text-[12px] text-[var(--muted)]">
                    Sin enlaces, el menú muestra solas las categorías principales, en su orden. Una categoría apagada no aparece.
                </p>
                {value.items.map((item, index) => (
                    <div key={index} className="flex flex-col gap-2.5 rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)] p-2.5">
                        <div className="flex items-center justify-between">
                            <span className="text-[11.5px] font-semibold uppercase tracking-wide text-[var(--muted)]">Enlace {index + 1}</span>
                            <Mover
                                index={index}
                                total={value.items.length}
                                disabled={readOnly}
                                onMove={(to) => set({ items: move(value.items, index, to) })}
                                onRemove={() => set({ items: value.items.filter((_, position) => position !== index) })}
                            />
                        </div>
                        <TargetField label="A dónde lleva" optional={false} value={item.target} readOnly={readOnly} onChange={(target) => setItem(index, { target })} kinds={['category', 'page', 'search', 'url']} />
                        <Input label="Texto" hint="Vacío: el nombre de la categoría." maxLength={30} disabled={readOnly} value={item.label} onChange={(event) => setItem(index, { label: event.target.value })} />
                        <IconField label="Ícono" value={item.icon ?? ''} allowEmpty readOnly={readOnly} onChange={(icon) => setItem(index, { icon })} />
                        <Toggle label="Resaltado" checked={item.highlight} disabled={readOnly} onChange={(highlight) => setItem(index, { highlight })} />
                        <Toggle
                            label="Abre el menú de categorías"
                            hint="En computador, en vez de ir a la página abre el menú grande («Categorías ⌄»)."
                            checked={item.opens_menu ?? false}
                            disabled={readOnly}
                            onChange={(opens_menu) => setItem(index, { opens_menu })}
                        />
                    </div>
                ))}
                {!readOnly && value.items.length < 14 && (
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={<PlusIcon size={14} weight="bold" />}
                        onClick={() => set({ items: [...value.items, { label: '', icon: null, target: { kind: 'page', value: '/tienda/ofertas' }, highlight: false }] })}
                    >
                        Agregar enlace
                    </Button>
                )}
            </Group>
        </div>
    );
}

function MobileNavForm({ value, onChange, readOnly, options }: SectionProps<'mobile_nav'>) {
    const label = (key: string) => options.mobile_nav_items.find((option) => option.value === key)?.label ?? key;
    const available = options.mobile_nav_items.filter((option) => !value.items.includes(option.value));

    const enabled = value.enabled ?? true;

    return (
        <div className="flex flex-col gap-3">
            <Toggle
                label="Mostrar la navegación del celular"
                hint="Apagada, el celular usa solo el encabezado y el menú."
                checked={enabled}
                disabled={readOnly}
                onChange={(next) => onChange({ ...value, enabled: next, items: next && value.items.length < 3 ? ['home', 'categories', 'search'] : value.items })}
            />
            {enabled && (
            <ul className="flex flex-col gap-1.5">
                {value.items.map((item, index) => (
                    <li key={item} className="flex items-center justify-between rounded-[10px] border border-[var(--line)] bg-[var(--surface-2)] px-3 py-1.5">
                        <span className="text-[13.5px] font-medium">
                            {index + 1}. {label(item)}
                        </span>
                        <Mover
                            index={index}
                            total={value.items.length}
                            disabled={readOnly}
                            onMove={(to) => onChange({ ...value, items: move(value.items, index, to) })}
                            onRemove={() => value.items.length > 3 && onChange({ ...value, items: value.items.filter((entry) => entry !== item) })}
                        />
                    </li>
                ))}
            </ul>
            )}
            {enabled && !readOnly && value.items.length < 5 && available.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {available.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() => onChange({ ...value, items: [...value.items, option.value] })}
                            className="flex min-h-[36px] items-center gap-1 rounded-[9px] border border-dashed border-[var(--line-2)] px-2.5 text-[12.5px] font-medium text-[var(--muted)] hover:text-[var(--text)]"
                        >
                            <PlusIcon size={12} weight="bold" />
                            {option.label}
                        </button>
                    ))}
                </div>
            )}
            <p className="text-[12px] text-[var(--muted)]">Van de 3 a 5. Con menos de 3 no se puede quitar ninguno.</p>
        </div>
    );
}

function CartBarForm({ value, onChange, readOnly, options }: SectionProps<'cart_bar'>) {
    const set = (patch: Partial<SettingsRow['cart_bar']>) => onChange({ ...value, ...patch });

    return (
        <div className="flex flex-col gap-4">
            <Toggle label="Mostrar la barra del carrito" hint="Aparece con el primer artículo." checked={value.enabled} disabled={readOnly} onChange={(enabled) => set({ enabled })} />
            <Toggle label="Avance hacia el domicilio gratis" checked={value.show_free_delivery_progress} disabled={readOnly} onChange={(show_free_delivery_progress) => set({ show_free_delivery_progress })} />
            <Input
                label="Cuando falta"
                hint="Lleva {faltan}, donde va la cifra."
                maxLength={80}
                disabled={readOnly}
                value={value.texts.missing}
                onChange={(event) => set({ texts: { ...value.texts, missing: event.target.value } })}
            />
            <Input label="Cuando ya es gratis" maxLength={80} disabled={readOnly} value={value.texts.reached} onChange={(event) => set({ texts: { ...value.texts, reached: event.target.value } })} />
            <Choice label="«Ver carrito» en computador" value={value.desktop_behavior} options={options.choices.desktop_behavior ?? []} disabled={readOnly} onChange={(desktop_behavior) => set({ desktop_behavior })} />
            <p className="text-[12px] text-[var(--muted)]">Si la sede no tiene domicilio gratis (gratis desde $ 0), el avance no aparece.</p>
        </div>
    );
}

function FooterForm({ value, onChange, readOnly, options, designLocked = false }: SectionProps<'footer'>) {
    const set = (patch: Partial<SettingsRow['footer']>) => onChange({ ...value, ...patch });
    const design = readOnly || designLocked;
    const setColumn = (index: number, patch: Partial<SettingsRow['footer']['columns'][number]>) =>
        set({ columns: value.columns.map((column, position) => (position === index ? { ...column, ...patch } : column)) });

    return (
        <div className="flex flex-col gap-4">
            <Group title="Aspecto">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Choice label="Forma" value={value.style ?? 'full'} options={options.choices.footer_style ?? []} disabled={design} onChange={(style) => set({ style })} />
                    <Choice label="Color" value={value.tone ?? 'light'} options={options.choices.footer_tone ?? []} disabled={design} onChange={(tone) => set({ tone })} />
                </div>
                <Toggle
                    label="Formulario para recibir novedades por correo"
                    hint="Opcional. Los correos quedan en Tienda en línea › Suscriptores, con su autorización de datos."
                    checked={value.newsletter ?? false}
                    disabled={design}
                    onChange={(newsletter) => set({ newsletter })}
                />
            </Group>

            <Group title="Columnas de enlaces">
                {value.columns.map((column, index) => (
                    <div key={index} className="flex flex-col gap-2.5 rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)] p-2.5">
                        <div className="flex items-center justify-between">
                            <span className="text-[11.5px] font-semibold uppercase tracking-wide text-[var(--muted)]">Columna {index + 1}</span>
                            <Mover
                                index={index}
                                total={value.columns.length}
                                disabled={design}
                                onMove={(to) => set({ columns: move(value.columns, index, to) })}
                                onRemove={() => set({ columns: value.columns.filter((_, position) => position !== index) })}
                            />
                        </div>
                        <Input label="Título" maxLength={30} disabled={design} value={column.title} onChange={(event) => setColumn(index, { title: event.target.value })} />
                        {column.links.map((link, linkIndex) => (
                            <div key={linkIndex} className="flex flex-col gap-2 rounded-[9px] border border-[var(--line)] bg-[var(--surface)] p-2">
                                <div className="flex items-center justify-between gap-2">
                                    <Input
                                        className="flex-1"
                                        placeholder="Texto del enlace"
                                        maxLength={40}
                                        disabled={design}
                                        value={link.label}
                                        onChange={(event) => setColumn(index, { links: column.links.map((entry, position) => (position === linkIndex ? { ...entry, label: event.target.value } : entry)) })}
                                    />
                                    <Mover
                                        index={linkIndex}
                                        total={column.links.length}
                                        disabled={design}
                                        onMove={(to) => setColumn(index, { links: move(column.links, linkIndex, to) })}
                                        onRemove={() => setColumn(index, { links: column.links.filter((_, position) => position !== linkIndex) })}
                                    />
                                </div>
                                <TargetField
                                    label="A dónde lleva"
                                    optional={false}
                                    value={link.target}
                                    readOnly={design}
                                    onChange={(target) => setColumn(index, { links: column.links.map((entry, position) => (position === linkIndex ? { ...entry, target } : entry)) })}
                                />
                            </div>
                        ))}
                        {!design && column.links.length < 8 && (
                            <Button variant="ghost" size="sm" icon={<PlusIcon size={13} weight="bold" />} onClick={() => setColumn(index, { links: [...column.links, { label: '', target: { kind: 'page', value: '/tienda' } }] })}>
                                Agregar enlace
                            </Button>
                        )}
                    </div>
                ))}
                {!design && value.columns.length < 4 && (
                    <Button variant="secondary" size="sm" icon={<PlusIcon size={14} weight="bold" />} onClick={() => set({ columns: [...value.columns, { title: '', links: [] }] })}>
                        Agregar columna
                    </Button>
                )}
            </Group>

            <Group title="Contacto (de la sede)">
                <p className="text-[12px] text-[var(--muted)]">Vacío: se usan el teléfono y la dirección de la ficha de la sede y el correo de la empresa.</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Input label="Teléfono" maxLength={30} disabled={readOnly} value={value.contact.phone ?? ''} onChange={(event) => set({ contact: { ...value.contact, phone: event.target.value || null } })} />
                    <Input label="WhatsApp" maxLength={20} placeholder="3001234567" disabled={readOnly} value={value.contact.whatsapp ?? ''} onChange={(event) => set({ contact: { ...value.contact, whatsapp: event.target.value || null } })} />
                    <Input label="Correo" type="email" maxLength={120} disabled={readOnly} value={value.contact.email ?? ''} onChange={(event) => set({ contact: { ...value.contact, email: event.target.value || null } })} />
                    <Input label="Dirección" maxLength={160} disabled={readOnly} value={value.contact.address ?? ''} onChange={(event) => set({ contact: { ...value.contact, address: event.target.value || null } })} />
                </div>
            </Group>

            <Group title="Redes (de la sede)">
                {value.social.map((row, index) => (
                    <div key={index} className="grid grid-cols-[140px_minmax(0,1fr)_auto] items-end gap-2">
                        <Select
                            options={options.social}
                            disabled={readOnly}
                            value={row.network}
                            onChange={(event) => set({ social: value.social.map((entry, position) => (position === index ? { ...entry, network: event.target.value } : entry)) })}
                        />
                        <Input
                            placeholder="https://instagram.com/…"
                            disabled={readOnly}
                            value={row.url}
                            onChange={(event) => set({ social: value.social.map((entry, position) => (position === index ? { ...entry, url: event.target.value } : entry)) })}
                        />
                        {!readOnly && (
                            <button type="button" aria-label="Quitar" onClick={() => set({ social: value.social.filter((_, position) => position !== index) })} className="grid size-11 place-items-center rounded-[8px] text-[var(--muted)] hover:text-[var(--danger)]">
                                <TrashIcon size={15} />
                            </button>
                        )}
                    </div>
                ))}
                {!readOnly && value.social.length < options.social.length && (
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={<PlusIcon size={14} weight="bold" />}
                        onClick={() => set({ social: [...value.social, { network: options.social.find((option) => !value.social.some((row) => row.network === option.value))?.value ?? 'instagram', url: '' }] })}
                    >
                        Agregar red
                    </Button>
                )}
            </Group>

            <Group title="Medios de pago">
                <ChipPicker
                    label="Logos que se muestran"
                    help="Sin elegir ninguno se muestran los medios que acepta la sede."
                    options={options.payment_logos.map((option, position) => ({ value: String(position + 1), label: option.label }))}
                    value={value.payment_logos.map((key) => options.payment_logos.findIndex((option) => option.value === key) + 1).filter((id) => id > 0)}
                    readOnly={design}
                    onChange={(ids) => set({ payment_logos: ids.map((id) => options.payment_logos[id - 1]?.value).filter(Boolean) as string[] })}
                />
            </Group>

            <Group title="Textos legales (de la sede)">
                <Textarea label="Texto al pie" rows={3} maxLength={600} disabled={readOnly} value={value.legal_text ?? ''} onChange={(event) => set({ legal_text: event.target.value || null })} />
                <TargetField label="Términos y condiciones" value={value.terms_target} readOnly={readOnly} onChange={(terms_target) => set({ terms_target })} />
                <TargetField label="Tratamiento de datos" help="Obligatorio: sin este enlace no se puede publicar." optional={false} value={value.privacy_target} readOnly={readOnly} onChange={(privacy_target: TargetValue | null) => set({ privacy_target })} />
                <TargetField label="PQRS" help="Vacío: el correo de la empresa." value={value.pqrs_target} readOnly={readOnly} onChange={(pqrs_target) => set({ pqrs_target })} />
                <p className="flex items-start gap-2 rounded-[10px] bg-[var(--surface-2)] px-3 py-2 text-[12px] text-[var(--muted)]">
                    <LockSimpleIcon size={15} className="mt-px shrink-0" />
                    La franja legal de abajo (razón social, NIT, dirección, teléfono y correo) sale de los datos de la empresa y siempre se muestra.
                </p>
            </Group>
        </div>
    );
}

function CardForm({ value, onChange, readOnly, options }: SectionProps<'card'>) {
    const set = (patch: Partial<SettingsRow['card']>) => onChange({ ...value, ...patch });
    const choices = options.choices;

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <Choice label="Estilo" value={value.style ?? 'boxed'} options={choices.card_style ?? []} disabled={readOnly} onChange={(style) => set({ style })} />
                <Choice label="Proporción de la foto" value={value.image_ratio ?? '1:1'} options={choices.image_ratio ?? []} disabled={readOnly} onChange={(image_ratio) => set({ image_ratio })} />
                <Choice label="Texto" value={value.text_align ?? 'left'} options={choices.text_align ?? []} disabled={readOnly} onChange={(text_align) => set({ text_align })} />
                <Choice label="Al pasar el mouse" value={value.image_hover ?? 'none'} options={choices.image_hover ?? []} disabled={readOnly} onChange={(image_hover) => set({ image_hover })} />
                <Choice label="La foto" value={value.image_fit} options={choices.image_fit ?? []} disabled={readOnly} onChange={(image_fit) => set({ image_fit })} />
                <Choice label="Fondo de la foto" value={value.image_bg} options={choices.image_bg ?? []} disabled={readOnly} onChange={(image_bg) => set({ image_bg })} />
                <Choice label="Etiqueta de oferta" value={value.deal_badge} options={choices.deal_badge ?? []} disabled={readOnly} onChange={(deal_badge) => set({ deal_badge })} />
                <Choice label="Botón «Agregar»" value={value.add_button} options={choices.add_button ?? []} disabled={readOnly} onChange={(add_button) => set({ add_button })} />
                <Choice label="Tamaño del precio" value={value.price_size} options={choices.price_size ?? []} disabled={readOnly} onChange={(price_size) => set({ price_size })} />
                <Choice label="Existencias" value={value.stock_display} options={choices.stock_display ?? []} disabled={readOnly} onChange={(stock_display) => set({ stock_display })} />
            </div>
            {value.stock_display === 'low_only' && (
                <Input
                    label="«Últimas unidades» desde"
                    type="number"
                    min={1}
                    max={20}
                    suffix="und"
                    disabled={readOnly}
                    value={String(value.low_stock_threshold)}
                    onChange={(event) => set({ low_stock_threshold: Number(event.target.value) })}
                />
            )}
            <Group title="Qué muestra">
                <Toggle label="La marca" checked={value.show_brand} disabled={readOnly} onChange={(show_brand) => set({ show_brand })} />
                <Toggle label="La presentación (900 ml)" checked={value.show_content} disabled={readOnly} onChange={(show_content) => set({ show_content })} />
                <Toggle label="El precio por unidad de medida ($ 12.778 x L)" checked={value.show_unit_price} disabled={readOnly} onChange={(show_unit_price) => set({ show_unit_price })} />
                <Toggle label="Una línea de descripción" hint="La que ya tenga la ficha; si está vacía, no se pinta nada." checked={value.show_description ?? false} disabled={readOnly} onChange={(show_description) => set({ show_description })} />
                <Toggle label="Los colores del artículo" hint="Hasta 4 muestras, en los artículos con subartículos por color." checked={value.show_swatches ?? false} disabled={readOnly} onChange={(show_swatches) => set({ show_swatches })} />
            </Group>
        </div>
    );
}

function FloatingForm({ value, onChange, readOnly, options }: SectionProps<'floating'>) {
    return (
        <div className="flex flex-col gap-3">
            <Toggle
                label="Mostrar el botón de WhatsApp"
                hint="El número es el WhatsApp del contacto de la sede (Pie de página). Sin número, no aparece."
                checked={value.whatsapp}
                disabled={readOnly}
                onChange={(whatsapp) => onChange({ ...value, whatsapp })}
            />
            <Choice label="Dónde" value={value.position} options={options.choices.floating_position ?? []} disabled={readOnly || !value.whatsapp} onChange={(position) => onChange({ ...value, position })} />
        </div>
    );
}

function CatalogForm({ value, onChange, readOnly }: SectionProps<'catalog'>) {
    return (
        <div className="flex flex-col gap-3">
            <Toggle label="Mostrar el precio por unidad de medida" hint="Por ejemplo «$ 4.200 x L», para comparar presentaciones." checked={value.price_per_unit} disabled={readOnly} onChange={(price_per_unit) => onChange({ ...value, price_per_unit })} />
            <p className="rounded-[10px] bg-[var(--surface-2)] px-3 py-2 text-[12px] text-[var(--muted)]">
                Un artículo con precio en cero nunca se muestra: sería un artículo que la tienda regala. Tampoco uno sin disponible en la bodega de la tienda: sigue publicado y vuelve solo cuando entra mercancía.
            </p>
        </div>
    );
}

function CategoryPageForm({ value, onChange, readOnly, options }: SectionProps<'category_page'>) {
    const set = (patch: Partial<SettingsRow['category_page']>) => onChange({ ...value, ...patch });
    const toggle = (list: string[], item: string) => (list.includes(item) ? list.filter((entry) => entry !== item) : [...list, item]);
    const sortOptions = options.sorts.filter((option) => value.sorts.includes(option.value));

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <Choice label="Filtros" value={value.layout} options={options.choices.category_layout ?? []} disabled={readOnly} onChange={(layout) => set({ layout })} hint="En celular siempre salen en una hoja." />
                <Choice label="Más resultados" value={value.pagination} options={options.choices.pagination ?? []} disabled={readOnly} onChange={(pagination) => set({ pagination })} />
                <Input label="Artículos por página" type="number" min={12} max={60} disabled={readOnly} value={String(value.page_size)} onChange={(event) => set({ page_size: Number(event.target.value) })} />
                <Choice label="Orden por defecto" value={value.default_sort} options={sortOptions} disabled={readOnly} onChange={(default_sort) => set({ default_sort })} />
            </div>
            <Group title="Filtros que se ofrecen">
                <div className="flex flex-wrap gap-1.5">
                    {options.filters.map((option) => (
                        <ToggleChip key={option.value} on={value.filters.includes(option.value)} disabled={readOnly} onClick={() => set({ filters: toggle(value.filters, option.value) })}>
                            {option.label}
                        </ToggleChip>
                    ))}
                </div>
            </Group>
            <Group title="Órdenes que se ofrecen">
                <div className="flex flex-wrap gap-1.5">
                    {options.sorts.map((option) => (
                        <ToggleChip
                            key={option.value}
                            on={value.sorts.includes(option.value)}
                            disabled={readOnly || (value.sorts.length === 1 && value.sorts.includes(option.value))}
                            onClick={() => {
                                const sorts = toggle(value.sorts, option.value);
                                set({ sorts, default_sort: sorts.includes(value.default_sort) ? value.default_sort : sorts[0] });
                            }}
                        >
                            {option.label}
                        </ToggleChip>
                    ))}
                </div>
            </Group>
            <Toggle label="Subcategorías en chips arriba de los resultados" checked={value.show_subcategory_chips} disabled={readOnly} onChange={(show_subcategory_chips) => set({ show_subcategory_chips })} />
        </div>
    );
}

function ProductPageForm({ value, onChange, readOnly, options }: SectionProps<'product_page'>) {
    const set = (patch: Partial<SettingsRow['product_page']>) => onChange({ ...value, ...patch });

    return (
        <div className="flex flex-col gap-3">
            <Toggle label="Precio por unidad de medida" checked={value.show_unit_price} disabled={readOnly} onChange={(show_unit_price) => set({ show_unit_price })} />
            <Toggle label="Descripción del artículo" checked={value.show_description} disabled={readOnly} onChange={(show_description) => set({ show_description })} />
            <div className="grid gap-3 sm:grid-cols-2">
                <Choice label="«También te puede interesar»" value={value.related} options={options.choices.related ?? []} disabled={readOnly} onChange={(related) => set({ related })} />
                <Input label="Cuántos" type="number" min={2} max={16} disabled={readOnly || value.related === 'none'} value={String(value.related_limit)} onChange={(event) => set({ related_limit: Number(event.target.value) })} />
            </div>
            <p className="text-[12px] text-[var(--muted)]">Los bloques de la página de producto (Contenido › Producto) salen debajo de la ficha.</p>
        </div>
    );
}

function SearchForm({ value, onChange, readOnly }: SectionProps<'search'>) {
    const set = (patch: Partial<SettingsRow['search']>) => onChange({ ...value, ...patch });
    const [term, setTerm] = useState('');

    return (
        <div className="flex flex-col gap-4">
            <Toggle label="Sugerencias mientras se escribe" hint="Sin tildes y con lo que suena igual: «arros» encuentra «Arroz»." checked={value.suggestions} disabled={readOnly} onChange={(suggestions) => set({ suggestions })} />
            <Group title="Búsquedas populares">
                <div className="flex flex-wrap gap-1.5">
                    {value.popular_terms.map((popular) => (
                        <span key={popular} className="flex min-h-[32px] items-center gap-1.5 rounded-[8px] border border-[var(--line)] bg-[var(--surface-2)] px-2 text-[12.5px]">
                            {popular}
                            {!readOnly && (
                                <button type="button" aria-label={`Quitar ${popular}`} onClick={() => set({ popular_terms: value.popular_terms.filter((entry) => entry !== popular) })} className="text-[var(--muted)] hover:text-[var(--danger)]">
                                    ×
                                </button>
                            )}
                        </span>
                    ))}
                </div>
                {!readOnly && value.popular_terms.length < 8 && (
                    <form
                        className="flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (term.trim() !== '' && !value.popular_terms.includes(term.trim())) {
                                set({ popular_terms: [...value.popular_terms, term.trim()] });
                            }

                            setTerm('');
                        }}
                    >
                        <Input className="flex-1" placeholder="Arroz" maxLength={30} value={term} onChange={(event) => setTerm(event.target.value)} />
                        <Button type="submit" variant="secondary">
                            Agregar
                        </Button>
                    </form>
                )}
            </Group>
            <Group title="Sin resultados">
                <Input label="Título" maxLength={60} disabled={readOnly} value={value.empty_title} onChange={(event) => set({ empty_title: event.target.value })} />
                <Textarea label="Texto" rows={2} maxLength={160} disabled={readOnly} value={value.empty_text ?? ''} onChange={(event) => set({ empty_text: event.target.value || null })} />
            </Group>
        </div>
    );
}

function ToggleChip({ on, disabled, onClick, children }: { on: boolean; disabled: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            disabled={disabled}
            aria-pressed={on}
            onClick={onClick}
            className={cn(
                'min-h-[36px] rounded-[9px] border px-2.5 text-[12.5px] font-medium transition-colors disabled:opacity-60',
                on ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
            )}
        >
            {children}
        </button>
    );
}

const FORMS: { [K in SettingsKey]?: (props: SectionProps<K>) => ReactNode } = {
    topbar: TopbarForm,
    header: HeaderForm,
    nav: NavForm,
    mobile_nav: MobileNavForm,
    cart_bar: CartBarForm,
    footer: FooterForm,
    floating: FloatingForm,
    card: CardForm,
    catalog: CatalogForm,
    category_page: CategoryPageForm,
    product_page: ProductPageForm,
    search: SearchForm,
};

/**
 * Una pieza de la estructura global con guardado automático.
 *
 * Cada cambio baja al borrador con su espera, validado en el servidor por el request de su
 * clave. «Publicar» la publica junto con los bloques.
 */
export function StructureSection<K extends SettingsKey>({
    settingKey,
    initial,
    readOnly,
    designLocked = false,
    options,
    endpoints,
    onSaved,
    bare = false,
}: {
    settingKey: K;
    initial: SettingsRow[K];
    readOnly: boolean;
    designLocked?: boolean;
    options: StructureOptions;
    endpoints: Endpoints;
    onSaved: () => void;
    /** Sin el marco de tarjeta: cuando ya va dentro de un panel. */
    bare?: boolean;
}) {
    const [value, setValue] = useState<SettingsRow[K]>(initial);
    const Form = FORMS[settingKey] as ((props: SectionProps<K>) => ReactNode) | undefined;

    useAutosave(value, (next) => saveSetting(endpoints, settingKey, next, onSaved));

    if (!Form) {
        return null;
    }

    const form = <Form value={value} onChange={setValue} readOnly={readOnly || (designLocked && settingKey !== 'footer')} designLocked={designLocked} options={options} />;

    if (bare) {
        return form;
    }

    const label = STRUCTURE_LABELS[settingKey];

    return (
        <section className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-4">
            <h2 className="text-[15px] font-semibold">{label?.title}</h2>
            <p className="mb-3 text-[12px] text-[var(--muted)]">{label?.description}</p>
            {form}
        </section>
    );
}
