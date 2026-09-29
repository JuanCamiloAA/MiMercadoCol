import { Link, router } from '@inertiajs/react';
import { InfoIcon, ShoppingCartIcon, TagIcon, TrashIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useEffect, useRef } from 'react';
import { AddToCartControl } from '@/components/storefront/AddToCartControl';
import { setCartQuantity, useCart } from '@/components/storefront/cart-store';
import { FreeDeliveryProgress } from '@/components/storefront/FreeDeliveryProgress';
import { openLogin } from '@/components/storefront/login-dialog';
import { StoreIcon } from '@/components/storefront/icons';
import { useCurrentShell, useIsPreview } from '@/components/storefront/store-context';
import StoreLayout from '@/layouts/StoreLayout';
import { cn } from '@/lib/cn';
import { money, number, UNIT_SHORT } from '@/lib/format';
import { StoreBlocks } from '@/pages/Storefront/blocks/StoreBlocks';
import type { CartBrief, StoreBlock } from '@/types/storefront';

interface CartItemRow {
    id: number;
    key: string;
    kind: 'product' | 'service';
    product_id: number | null;
    service_id: number | null;
    /** Lo que trae un servicio, tal cual el maestro. */
    includes?: { name: string; label?: string; quantity: number; unit: string }[];
    name: string;
    brand: string | null;
    unit: string;
    sold_by_weight: boolean;
    image: string | null;
    href: string;
    step: number;
    price: number;
    compare_at: number | null;
    quantity: number;
    line_total: number;
    substitution_policy: string;
    available: number | null;
}

interface CartProps {
    store: { id: number; name: string };
    items: CartItemRow[];
    summary: {
        subtotal: number;
        savings: number;
        delivery_enabled: boolean;
        delivery_fee: number;
        free_delivery: CartBrief['free_delivery'];
        min_order_total: number;
        min_order_missing: number;
        delivery_note: string | null;
    };
    isLoggedIn: boolean;
    blocks: StoreBlock[];
}

/** Qué hacer si falta un artículo al alistar. Son las tres políticas que entiende el pedido. */
const POLICIES = [
    { value: 'call', label: 'Llamarme' },
    { value: 'replace', label: 'Reemplazar' },
    { value: 'remove', label: 'Quitar' },
];

export default function StorefrontCart({ store, items, summary, isLoggedIn, blocks }: CartProps) {
    const cart = useCart();
    const shell = useCurrentShell();
    const preview = useIsPreview();
    const revision = useRef(cart.revision);

    // Cada vez que el servidor confirma un cambio de cantidad, las líneas y el total se vuelven
    // a pedir: aquí no se suma nada.
    useEffect(() => {
        if (cart.revision !== revision.current && !preview) {
            revision.current = cart.revision;
            router.reload({ only: ['items', 'summary'] });
        }
    }, [cart.revision, preview]);

    const setPolicy = (item: CartItemRow, policy: string) => {
        if (preview) {
            return;
        }

        router.patch(route('storefront.cart.update', item.id), { quantity: item.quantity, substitution_policy: policy }, { preserveScroll: true, only: ['items'] });
    };

    const blocked = summary.min_order_missing > 0;
    const syncing = Object.keys(cart.pending).length > 0;

    return (
        <StoreLayout title="Carrito" current="cart">
            <h1 className="mb-4 text-[26px] md:text-[32px]">Tu carrito</h1>

            {items.length === 0 ? (
                <div className="flex flex-col items-center gap-3 rounded-[var(--store-radius)] border border-dashed border-[var(--line-2)] py-16 text-center">
                    <ShoppingCartIcon size={44} className="text-[var(--muted)]" />
                    <p className="text-[15px] text-[var(--muted)]">Todavía no has agregado nada.</p>
                    <Link
                        href={route('storefront.home')}
                        className="flex h-12 items-center rounded-full bg-[var(--accent)] px-6 text-[15px] font-bold text-[var(--accent-ink)]"
                    >
                        Ir a la tienda
                    </Link>
                </div>
            ) : (
                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                    <ul className="flex flex-col divide-y divide-[var(--line)] rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)]">
                        {items.map((item) => {
                            const short = item.available !== null && item.available < item.quantity;

                            return (
                                <li key={item.key} className="flex gap-3 p-3 md:gap-4 md:p-4">
                                    <Link href={item.href} className="grid size-20 shrink-0 place-items-center overflow-hidden rounded-[12px] border border-[var(--line)] bg-white md:size-24">
                                        {item.image ? (
                                            <img src={item.image} alt="" className="h-full w-full object-contain p-1.5" />
                                        ) : (
                                            <StoreIcon name="Package" size={30} className="text-[var(--muted)]" />
                                        )}
                                    </Link>

                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                {item.brand && <p className="text-[11px] font-bold uppercase tracking-wide text-[var(--muted)]">{item.brand}</p>}
                                                <Link href={item.href} className="line-clamp-2 text-[14.5px] font-semibold leading-snug hover:underline">
                                                    {item.name}
                                                </Link>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setCartQuantity({ kind: item.kind, id: (item.product_id ?? item.service_id) as number, key: item.key, name: item.name }, 0)
                                                }
                                                aria-label={`Quitar ${item.name} del carrito`}
                                                className="grid size-10 shrink-0 place-items-center rounded-full text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                            >
                                                <TrashIcon size={18} />
                                            </button>
                                        </div>

                                        <p className="mt-0.5 text-[12.5px] text-[var(--muted)] tabular-nums">
                                            {money(item.price)} {item.kind === 'service' ? 'por servicio' : `por ${UNIT_SHORT[item.unit] ?? 'und'}`}
                                            {item.compare_at && item.compare_at > item.price && <span className="ml-1.5 line-through">{money(item.compare_at)}</span>}
                                        </p>
                                        {short && (
                                            <p className="mt-0.5 flex items-center gap-1 text-[12.5px] font-semibold text-[var(--warn)]">
                                                <WarningCircleIcon size={14} weight="fill" />
                                                {item.available !== null && item.available <= 0 ? 'Se agotó en esta sede' : `Solo quedan ${number(item.available)}`}
                                            </p>
                                        )}
                                        {item.kind === 'service' && (item.includes ?? []).length > 0 && (
                                            <p className="mt-0.5 text-[12px] text-[var(--muted)]">
                                                Incluye: {(item.includes ?? []).map((part) => `${number(part.quantity)} ${UNIT_SHORT[part.unit] ?? ''} ${part.label ?? part.name}`.replace(/\s+/g, ' ')).join(', ')}
                                            </p>
                                        )}

                                        <div className="mt-2.5 flex items-center gap-3">
                                            <AddToCartControl
                                                item={{ kind: item.kind, id: (item.product_id ?? item.service_id) as number, key: item.key, name: item.name, step: item.step, sold_by_weight: item.sold_by_weight }}
                                                className="h-10 w-[136px]"
                                            />
                                            <span className={cn('ml-auto text-[17px] font-extrabold tabular-nums', syncing && 'opacity-60')}>{money(item.line_total)}</span>
                                        </div>

                                        {item.kind !== 'service' && (
                                            <fieldset className="mt-2.5">
                                                <legend className="mb-1 text-[11.5px] font-semibold text-[var(--muted)]">Si no hay</legend>
                                                <div className="flex gap-1">
                                                    {POLICIES.map((policy) => (
                                                        <button
                                                            key={policy.value}
                                                            type="button"
                                                            aria-pressed={item.substitution_policy === policy.value}
                                                            onClick={() => setPolicy(item, policy.value)}
                                                            className={cn(
                                                                'h-8 flex-1 rounded-full border text-[12px] font-semibold transition-colors md:max-w-[120px]',
                                                                item.substitution_policy === policy.value
                                                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                                    : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                                                            )}
                                                        >
                                                            {policy.label}
                                                        </button>
                                                    ))}
                                                </div>
                                            </fieldset>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    <aside className="flex flex-col gap-4 rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] p-4 lg:sticky lg:top-[180px] md:p-5" aria-label="Resumen">
                        <FreeDeliveryProgress freeDelivery={summary.free_delivery} texts={shell?.settings.cart_bar.texts ?? { missing: 'Te faltan {faltan} para el domicilio gratis', reached: '¡Tu domicilio es gratis!' }} />

                        <dl className={cn('flex flex-col gap-2 text-[14px]', syncing && 'opacity-60')}>
                            <div className="flex items-center justify-between">
                                <dt className="text-[var(--muted)]">Productos</dt>
                                <dd className="font-semibold tabular-nums">{money(summary.subtotal)}</dd>
                            </div>
                            {summary.savings > 0 && (
                                <div className="flex items-center justify-between text-[var(--deal)]">
                                    <dt className="flex items-center gap-1.5">
                                        <TagIcon size={15} weight="fill" /> Ahorras
                                    </dt>
                                    <dd className="font-bold tabular-nums">−{money(summary.savings)}</dd>
                                </div>
                            )}
                            {summary.delivery_enabled && (
                                <div className="flex items-center justify-between">
                                    <dt className="text-[var(--muted)]">Domicilio</dt>
                                    <dd className="font-semibold tabular-nums">{summary.delivery_fee === 0 ? 'Gratis' : money(summary.delivery_fee)}</dd>
                                </div>
                            )}
                            <div className="flex items-baseline justify-between border-t border-[var(--line)] pt-2.5">
                                <dt className="text-[16px] font-bold">Total</dt>
                                <dd className="text-[26px] font-extrabold tabular-nums">{money(summary.subtotal + (summary.delivery_enabled ? summary.delivery_fee : 0))}</dd>
                            </div>
                        </dl>

                        {summary.delivery_enabled && <p className="-mt-2 text-[12px] text-[var(--muted)]">El domicilio puede cambiar según tu barrio; lo ves antes de confirmar.</p>}

                        {blocked ? (
                            <div className="flex flex-col gap-2">
                                <p className="flex items-start gap-2 rounded-[12px] bg-[var(--warn-soft)] px-3 py-2.5 text-[13px] font-semibold text-[var(--warn)]" role="status">
                                    <WarningCircleIcon size={18} weight="fill" className="mt-px shrink-0" />
                                    <span>
                                        El pedido mínimo en esta sede es de {money(summary.min_order_total)}. Te faltan {money(summary.min_order_missing)} para poder pagar.
                                    </span>
                                </p>
                                <button type="button" disabled aria-disabled className="h-12 w-full cursor-not-allowed rounded-full bg-[var(--surface-2)] text-[15px] font-bold text-[var(--muted)]">
                                    Continuar al pago
                                </button>
                            </div>
                        ) : isLoggedIn ? (
                            <Link
                                href={route('storefront.checkout.create')}
                                className="flex h-12 w-full items-center justify-center rounded-full bg-[var(--accent)] text-[15px] font-bold text-[var(--accent-ink)] hover:brightness-110"
                            >
                                Continuar al pago
                            </Link>
                        ) : (
                            <button
                                type="button"
                                onClick={() => !preview && openLogin({ returnTo: route('storefront.checkout.create') })}
                                aria-haspopup="dialog"
                                className="flex h-12 w-full items-center justify-center rounded-full bg-[var(--accent)] text-[15px] font-bold text-[var(--accent-ink)] hover:brightness-110"
                            >
                                Entrar para pedir
                            </button>
                        )}

                        <p className="flex items-start gap-2 text-[12.5px] text-[var(--muted)]">
                            <InfoIcon size={16} className="mt-px shrink-0" />
                            <span>
                                ¿Tienes un cupón? Lo aplicas en el pago.
                                {summary.delivery_note ? ` ${summary.delivery_note}` : ''} Te atiende la sede {store.name}.
                            </span>
                        </p>
                    </aside>
                </div>
            )}

            {blocks.length > 0 && <StoreBlocks blocks={blocks} className="mt-8" />}
        </StoreLayout>
    );
}
