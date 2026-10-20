import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeftIcon, CameraIcon, MinusIcon, PackageIcon, PlusIcon, WrenchIcon, XIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
import StoreLayout from '@/layouts/StoreLayout';
import { cn } from '@/lib/cn';
import { date, money, number, UNIT_SHORT } from '@/lib/format';

interface ReturnItem {
    sale_item_id: number;
    name: string;
    unit: string;
    sold_by_weight: boolean;
    is_service: boolean;
    quantity: number;
    available: number;
    unit_price: number;
    image: string | null;
    image_url?: string | null;
    includes: string[];
}

interface ReturnRequestProps {
    order: { id: number; number: string; delivered_at: string | null };
    items: ReturnItem[];
    reasons: { value: string; label: string }[];
    until: string;
    maxPhotos: number;
}

/**
 * El cliente pide la devolución de lo que no le llegó bien. La revisa el equipo de la sede: si
 * procede, se recoge (o la lleva) y se le devuelve la plata o se le abona saldo a favor.
 */
export default function StorefrontReturnRequest({ order, items, reasons, until, maxPhotos }: ReturnRequestProps) {
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [lineReasons, setLineReasons] = useState<Record<number, string>>({});
    const [note, setNote] = useState('');
    const [photos, setPhotos] = useState<File[]>([]);
    const [processing, setProcessing] = useState(false);

    const previews = useMemo(() => photos.map((file) => URL.createObjectURL(file)), [photos]);

    // Las vistas previas ocupan memoria del navegador: se sueltan al cambiar o al salir.
    useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

    const step = (item: ReturnItem) => (item.sold_by_weight ? 0.5 : 1);
    const setQuantity = (item: ReturnItem, value: number) =>
        setQuantities((current) => ({ ...current, [item.sale_item_id]: Math.min(item.available, Math.max(0, Math.round(value * 1000) / 1000)) }));

    const chosen = items.filter((item) => (quantities[item.sale_item_id] ?? 0) > 0);
    const total = chosen.reduce((sum, item) => sum + Math.round((quantities[item.sale_item_id] ?? 0) * item.unit_price), 0);
    const missingReason = chosen.some((item) => !lineReasons[item.sale_item_id]);

    const addPhotos = (files: FileList | null) => {
        if (!files) {
            return;
        }

        setPhotos((current) => [...current, ...Array.from(files)].slice(0, maxPhotos));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true);

        router.post(
            route('storefront.returns.store', order.id),
            {
                items: chosen.map((item) => ({ sale_item_id: item.sale_item_id, quantity: quantities[item.sale_item_id], reason: lineReasons[item.sale_item_id] })),
                note,
                photos,
            },
            { forceFormData: true, preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    };

    const firstError = Object.values(errors)[0];

    return (
        <StoreLayout title={`Devolver del pedido #${order.number}`} current="orders">
            <Link href={route('storefront.orders.show', order.id)} className="mb-3 inline-flex items-center gap-1.5 text-[13px] text-[var(--muted)]">
                <ArrowLeftIcon size={15} />
                Volver al pedido
            </Link>

            <h1 className="text-[20px] md:text-[24px]">Devolver productos del pedido #{order.number}</h1>
            <p className="mb-4 text-[13px] text-[var(--muted)]">
                Puedes solicitarla hasta el {date(until)}. La revisamos y te avisamos por correo.
            </p>

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-[1fr_340px] lg:items-start">
                <section className="flex flex-col gap-2">
                    {items.map((item) => {
                        const quantity = quantities[item.sale_item_id] ?? 0;
                        const disabled = item.available <= 0;

                        return (
                            <div
                                key={item.sale_item_id}
                                className={cn('rounded-[14px] border bg-[var(--surface)] p-3', quantity > 0 ? 'border-[var(--accent)]' : 'border-[var(--line)]', disabled && 'opacity-60')}
                            >
                                <div className="flex items-start gap-3">
                                    <span className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-[11px] bg-[var(--surface-2)] text-[var(--muted)]">
                                        {item.image_url ? (
                                            <img src={item.image_url} alt="" className="h-full w-full object-cover" />
                                        ) : item.is_service ? (
                                            <WrenchIcon size={22} />
                                        ) : (
                                            <PackageIcon size={22} />
                                        )}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[14px] font-medium leading-snug">{item.name}</p>
                                        <p className="text-[12px] text-[var(--muted)]">
                                            Te llegaron {number(item.quantity)} {UNIT_SHORT[item.unit] ?? ''}
                                            {item.available < item.quantity ? ` · puedes devolver ${number(item.available)}` : ''}
                                        </p>
                                        {item.includes.length > 0 && <p className="text-[12px] text-[var(--muted)]">Incluye: {item.includes.join(', ')}</p>}
                                        {disabled && <p className="text-[12px] font-medium text-[var(--muted)]">Ya está en otra devolución.</p>}
                                    </div>
                                </div>

                                {!disabled && (
                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            aria-label={`Quitar uno de ${item.name}`}
                                            onClick={() => setQuantity(item, quantity - step(item))}
                                            className="grid size-10 place-items-center rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)]"
                                        >
                                            <MinusIcon size={15} />
                                        </button>
                                        <span className="grid h-10 min-w-[64px] place-items-center rounded-[11px] border border-[var(--line)] text-[14px] tabular-nums">
                                            {number(quantity)}
                                        </span>
                                        <button
                                            type="button"
                                            aria-label={`Agregar uno de ${item.name}`}
                                            onClick={() => setQuantity(item, quantity + step(item))}
                                            className="grid size-10 place-items-center rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)]"
                                        >
                                            <PlusIcon size={15} />
                                        </button>
                                        {quantity > 0 && (
                                            <select
                                                aria-label={`Motivo para ${item.name}`}
                                                value={lineReasons[item.sale_item_id] ?? ''}
                                                onChange={(event) => setLineReasons((current) => ({ ...current, [item.sale_item_id]: event.target.value }))}
                                                className="h-10 min-w-0 flex-1 rounded-[11px] border border-[var(--line)] bg-[var(--surface)] px-2 text-[13px]"
                                            >
                                                <option value="">¿Por qué lo devuelves?</option>
                                                {reasons.map((reason) => (
                                                    <option key={reason.value} value={reason.value}>
                                                        {reason.label}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </section>

                <section className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-4 lg:sticky lg:top-24">
                    <label className="block text-[13px] font-medium" htmlFor="return-note">
                        Cuéntanos qué pasó
                    </label>
                    <textarea
                        id="return-note"
                        rows={3}
                        maxLength={2000}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        placeholder="Opcional"
                        className="mt-1 w-full rounded-[11px] border border-[var(--line)] bg-[var(--surface)] p-2.5 text-[13px]"
                    />

                    <p className="mt-3 text-[13px] font-medium">Fotos (opcional, hasta {maxPhotos})</p>
                    <div className="mt-1 flex flex-wrap gap-2">
                        {previews.map((url, index) => (
                            <span key={url} className="relative block size-16 overflow-hidden rounded-[10px] border border-[var(--line)]">
                                <img src={url} alt={`Foto ${index + 1}`} className="h-full w-full object-cover" />
                                <button
                                    type="button"
                                    aria-label="Quitar la foto"
                                    onClick={() => setPhotos((current) => current.filter((_, position) => position !== index))}
                                    className="absolute right-0.5 top-0.5 grid size-5 place-items-center rounded-full bg-black/60 text-white"
                                >
                                    <XIcon size={11} />
                                </button>
                            </span>
                        ))}
                        {photos.length < maxPhotos && (
                            <label className="grid size-16 cursor-pointer place-items-center rounded-[10px] border border-dashed border-[var(--line)] text-[var(--muted)]">
                                <CameraIcon size={20} />
                                <input type="file" accept="image/jpeg,image/png,image/webp" multiple className="hidden" onChange={(event) => addPhotos(event.target.files)} />
                            </label>
                        )}
                    </div>

                    <div className="mt-4 flex items-center justify-between border-t border-[var(--line)] pt-3">
                        <span className="text-[13px] text-[var(--muted)]">Valor a devolver</span>
                        <span className="text-[20px] font-semibold text-[var(--accent)] tabular-nums">{money(total)}</span>
                    </div>
                    <p className="text-[11.5px] text-[var(--muted)]">Te devolvemos la plata o te abonamos saldo a favor cuando recibamos los productos.</p>

                    {firstError && <p className="mt-2 text-[12.5px] font-medium text-[var(--danger)]">{firstError}</p>}

                    <button
                        type="submit"
                        disabled={processing || chosen.length === 0 || missingReason}
                        className="mt-3 flex h-12 w-full items-center justify-center rounded-[13px] bg-[var(--accent)] text-[15px] font-semibold text-[var(--accent-ink)] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {chosen.length === 0 ? 'Elige qué devolver' : missingReason ? 'Falta el motivo' : 'Solicitar devolución'}
                    </button>
                </section>
            </form>
        </StoreLayout>
    );
}
