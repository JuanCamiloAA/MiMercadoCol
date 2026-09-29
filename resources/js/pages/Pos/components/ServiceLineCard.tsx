import { ArrowCounterClockwiseIcon, LockKeyIcon, MinusIcon, PlusIcon, TrashIcon, WarningCircleIcon, WrenchIcon, XIcon } from '@phosphor-icons/react';
import { cn } from '@/lib/cn';
import { money, number, UNIT_SHORT } from '@/lib/format';
import {
    actionFor,
    componentTotal,
    isComponentAdjusted,
    type RemovedComponent,
    type ServiceAction,
    type ServiceComponentLine,
} from '@/pages/Pos/service-lines';

export interface ServiceLineView {
    key: string;
    name: string;
    quantity: number;
    unit_price: number;
    discount: number;
    pricing_mode?: 'bundled' | 'itemized';
    open_price?: boolean;
    components?: ServiceComponentLine[];
    removed_components?: RemovedComponent[];
    quote_error?: string | null;
}

interface ServiceLineCardProps {
    line: ServiceLineView;
    /** Si el cajero puede hacer ese cambio: por su permiso o porque un supervisor lo autorizó. */
    can: (action: ServiceAction) => boolean;
    onServiceQuantity: (next: number) => void;
    onEditPrice: () => void;
    onComponentQuantity: (productId: number, next: number) => void;
    onRemoveComponent: (productId: number) => void;
    /** Cambiar la presentación de un artículo completo: no pide autorización. */
    onSwapComponent: (productId: number, optionProductId: number) => void;
    onRestoreComponent: (productId: number) => void;
    onRequestAuthorization: (action: ServiceAction, message: string) => void;
    onAddComponent: () => void;
    onResetToMaster: () => void;
    onRemoveLine: () => void;
}

const AUTHORIZE_MESSAGES: Record<ServiceAction, string> = {
    service_remove: 'Quitar artículos de un servicio necesita autorización.',
    service_reduce: 'Usar menos cantidad de un artículo del servicio necesita autorización.',
    service_increase: 'Usar más cantidad de un artículo del servicio necesita autorización.',
    service_add: 'Agregar a un servicio un artículo que no trae necesita autorización.',
};

/**
 * La línea de un servicio en el tiquete, con sus artículos debajo.
 *
 * Los controles se pintan según el permiso de cada tipo de cambio; sin él aparece «Pedir
 * autorización», que abre el mismo PIN del descuento. El servidor vuelve a revisar todo.
 */
export default function ServiceLineCard({
    line,
    can,
    onServiceQuantity,
    onEditPrice,
    onComponentQuantity,
    onRemoveComponent,
    onSwapComponent,
    onRestoreComponent,
    onRequestAuthorization,
    onAddComponent,
    onResetToMaster,
    onRemoveLine,
}: ServiceLineCardProps) {
    const itemized = line.pricing_mode === 'itemized';
    const components = line.components ?? [];
    const removed = line.removed_components ?? [];
    const gross = Math.round(line.quantity * line.unit_price);
    const total = gross - Math.min(line.discount, gross) + (itemized ? components.reduce((sum, component) => sum + componentTotal(component), 0) : 0);
    const adjusted = removed.length > 0 || components.some(isComponentAdjusted);
    const missing = (['service_remove', 'service_reduce', 'service_increase'] as ServiceAction[]).filter((action) => !can(action));

    const step = (component: ServiceComponentLine, direction: -1 | 1) => {
        const next = Math.max(0, Math.round((component.quantity + direction * (component.sold_by_weight ? 0.1 : 1)) * 1000) / 1000);
        onComponentQuantity(component.product_id, next);
    };

    return (
        <li className="px-4 py-2.5">
            <div className="flex items-start justify-between gap-2">
                <p className="flex min-w-0 flex-1 items-start gap-1.5 text-[0.84375rem] font-medium leading-snug">
                    <WrenchIcon size={15} className="mt-0.5 shrink-0 text-[var(--accent)]" />
                    <span>{line.name}</span>
                </p>
                <button
                    type="button"
                    onClick={onRemoveLine}
                    aria-label={`Quitar ${line.name}`}
                    className="shrink-0 text-[var(--muted)] transition-colors hover:text-[var(--danger)]"
                >
                    <TrashIcon size={15} />
                </button>
            </div>

            <div className="mt-1.5 flex items-center gap-2">
                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={() => onServiceQuantity(line.quantity - 1)}
                        aria-label="Un servicio menos"
                        className="grid size-11 place-items-center rounded-control border border-[var(--line)] bg-[var(--surface-2)] transition-colors hover:bg-[var(--surface-3)]"
                    >
                        <MinusIcon size={15} />
                    </button>
                    <span className="grid h-11 w-[3.5rem] place-items-center rounded-control border border-[var(--line)] bg-[var(--surface-2)] font-mono text-[0.875rem] tabular">
                        {number(line.quantity)}
                    </span>
                    <button
                        type="button"
                        onClick={() => onServiceQuantity(line.quantity + 1)}
                        aria-label="Un servicio más"
                        className="grid size-11 place-items-center rounded-control border border-[var(--line)] bg-[var(--surface-2)] transition-colors hover:bg-[var(--surface-3)]"
                    >
                        <PlusIcon size={15} />
                    </button>
                </div>

                <span className="ml-auto text-right">
                    <span className="block font-mono text-[0.9375rem] font-semibold tabular">{money(total)}</span>
                    {line.open_price ? (
                        <button type="button" onClick={onEditPrice} className="block font-mono text-[0.6875rem] text-[var(--accent)] underline-offset-2 hover:underline tabular">
                            {money(line.unit_price)} c/u · cambiar
                        </button>
                    ) : (
                        <span className="block font-mono text-[0.6875rem] text-[var(--muted)] tabular">
                            {money(line.unit_price)} c/u{line.discount > 0 ? ` · −${money(line.discount)}` : ''}
                        </span>
                    )}
                </span>
            </div>

            {line.quote_error && (
                <p className="mt-1.5 flex items-start gap-1 text-[0.71875rem] font-medium text-[var(--danger)]">
                    <WarningCircleIcon size={13} weight="fill" className="mt-px shrink-0" />
                    {line.quote_error}
                </p>
            )}

            {(components.length > 0 || removed.length > 0) && (
                <ul className="mt-2 flex flex-col gap-1.5 border-l-2 border-[var(--line)] pl-3">
                    {components.map((component) => {
                        const short = !component.allow_negative_stock && component.quantity > component.stock + 0.0005;
                        const changed = isComponentAdjusted(component);
                        const stepSize = component.sold_by_weight ? 0.1 : 1;
                        // Bajar hacia el maestro no pide permiso; bajar por debajo, si.
                        const canReduce = component.master_quantity === null || can('service_reduce') || component.quantity - stepSize >= component.master_quantity - 0.0005;
                        const nextUp = component.quantity + stepSize;
                        const canIncrease = actionFor(component, nextUp) !== 'service_increase' || can('service_increase') || component.master_quantity === null;
                        const canRemove = component.master_quantity === null || can('service_remove');

                        return (
                            <li key={component.product_id} className="text-[0.78125rem]">
                                <div className="flex items-center gap-1.5">
                                    <span className="min-w-0 flex-1">
                                        <span className={cn('block truncate', !component.is_active && 'text-[var(--danger)]')}>
                                            {number(component.quantity)} {UNIT_SHORT[component.unit] ?? ''} · {component.name}
                                        </span>
                                        <span className="flex flex-wrap items-center gap-1.5 text-[0.6875rem] text-[var(--muted)]">
                                            {itemized ? `${money(component.unit_price)} c/u · ${money(componentTotal(component))}` : 'incluido'}
                                            {changed && (
                                                <span className="rounded-chip bg-[var(--warn-soft)] px-1 font-medium text-[var(--warn)]">
                                                    {component.master_quantity === null ? 'Agregado' : 'Ajustado'}
                                                </span>
                                            )}
                                            {!component.is_active && <span className="font-medium text-[var(--danger)]">Inactivo: quítalo</span>}
                                            {short && <span className="font-medium text-[var(--danger)]">Solo hay {number(component.stock)}</span>}
                                        </span>
                                        {component.family && component.family.options.length > 1 && (
                                            <select
                                                aria-label={`Presentación de ${component.family.name ?? component.name}`}
                                                value={component.product_id}
                                                onChange={(event) => onSwapComponent(component.product_id, Number(event.target.value))}
                                                className="mt-1 h-8 max-w-full rounded-chip border border-[var(--line)] bg-[var(--surface)] px-1.5 text-[0.75rem]"
                                            >
                                                {component.family.options.map((option) => (
                                                    <option key={option.product_id} value={option.product_id} disabled={option.stock <= 0 && !option.allow_negative_stock}>
                                                        {option.label}
                                                        {itemized ? ` · ${money(option.unit_price)}` : ''}
                                                        {option.stock <= 0 && !option.allow_negative_stock ? ' · agotado' : ''}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </span>
                                    {canReduce && (
                                        <button
                                            type="button"
                                            onClick={() => step(component, -1)}
                                            aria-label={`Menos ${component.name}`}
                                            className="grid size-8 place-items-center rounded-chip border border-[var(--line)] bg-[var(--surface-2)] hover:bg-[var(--surface-3)]"
                                        >
                                            <MinusIcon size={12} />
                                        </button>
                                    )}
                                    {canIncrease && (
                                        <button
                                            type="button"
                                            onClick={() => step(component, 1)}
                                            aria-label={`Más ${component.name}`}
                                            className="grid size-8 place-items-center rounded-chip border border-[var(--line)] bg-[var(--surface-2)] hover:bg-[var(--surface-3)]"
                                        >
                                            <PlusIcon size={12} />
                                        </button>
                                    )}
                                    {canRemove && (
                                        <button
                                            type="button"
                                            onClick={() => onRemoveComponent(component.product_id)}
                                            aria-label={`Quitar ${component.name} del servicio`}
                                            className="grid size-8 place-items-center rounded-chip text-[var(--muted)] hover:text-[var(--danger)]"
                                        >
                                            <XIcon size={13} />
                                        </button>
                                    )}
                                </div>
                            </li>
                        );
                    })}

                    {removed.map((component) => (
                        <li key={`removed-${component.product_id}`} className="flex items-center gap-2 text-[0.75rem] text-[var(--muted)]">
                            <span className="min-w-0 flex-1 truncate line-through">
                                {number(component.master_quantity)} · {component.name}
                            </span>
                            <button type="button" onClick={() => onRestoreComponent(component.product_id)} className="shrink-0 font-medium text-[var(--accent)] hover:underline">
                                Restaurar
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.71875rem]">
                {can('service_add') ? (
                    <button type="button" onClick={onAddComponent} className="font-medium text-[var(--accent)] hover:underline">
                        Agregar artículo al servicio
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={() => onRequestAuthorization('service_add', AUTHORIZE_MESSAGES.service_add)}
                        className="inline-flex items-center gap-1 font-medium text-[var(--muted)] hover:text-[var(--text)]"
                    >
                        <LockKeyIcon size={12} /> Pedir autorización para agregar
                    </button>
                )}
                {missing.length > 0 && components.length > 0 && (
                    <button
                        type="button"
                        onClick={() => onRequestAuthorization(missing[0], AUTHORIZE_MESSAGES[missing[0]])}
                        className="inline-flex items-center gap-1 font-medium text-[var(--muted)] hover:text-[var(--text)]"
                    >
                        <LockKeyIcon size={12} /> Pedir autorización para ajustar
                    </button>
                )}
                {adjusted && (
                    <button type="button" onClick={onResetToMaster} className="inline-flex items-center gap-1 font-medium text-[var(--muted)] hover:text-[var(--text)]">
                        <ArrowCounterClockwiseIcon size={12} /> Volver a como está en el maestro
                    </button>
                )}
            </div>
        </li>
    );
}
