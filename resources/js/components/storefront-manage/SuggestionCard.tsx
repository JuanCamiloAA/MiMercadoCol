import { Link, router } from '@inertiajs/react';
import { ImageIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { ManageCan, SuggestionItem } from '@/types/storefront-manage';

interface SuggestionCardProps {
    item: SuggestionItem;
    storeId: number;
    can: ManageCan;
    /** `card`: tarjeta suelta (columna del catálogo); `row`: renglón dentro de su grupo. */
    layout?: 'card' | 'row';
}

/**
 * Una sugerencia con su motivo y lo que se puede hacer con ella. Las acciones dependen del grupo:
 * lo que se vende en caja se publica o se descarta; un lote por vencer se publica destacado o se
 * le arma promoción; una ficha incompleta se completa antes de publicar.
 */
export function SuggestionCard({ item, storeId, can, layout = 'card' }: SuggestionCardProps) {
    const [busy, setBusy] = useState<string | null>(null);

    const publish = (featured: boolean) => {
        setBusy(featured ? 'feature' : 'publish');
        router.post(
            route('storefront.catalog.update'),
            {
                type: 'product',
                product_ids: [item.id],
                fields: featured ? { is_online_visible: true, is_featured: true } : { is_online_visible: true },
                store_id: storeId,
            },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    const dismiss = () => {
        setBusy('dismiss');
        router.post(
            route('storefront.catalog.suggestions.dismiss'),
            { store_id: storeId, product_id: item.id, group: item.group },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    const onlyPrice = item.missing.length === 1 && item.missing[0] === 'price';
    const metrics = [
        `Disp. ${number(item.available)} ${item.stock_unit}`,
        `Caja 30 d ${number(item.sold_pos_30d)}`,
        item.price > 0 ? money(item.price) : 'Sin precio',
    ].join(' · ');

    const actions = (
        <div className="flex flex-wrap gap-1.5">
            {(item.group === 'pos_sellers' || item.group === 'overstock') && (
                <>
                    <Button size="sm" loading={busy === 'publish'} disabled={busy !== null} onClick={() => publish(false)} aria-label={`Publicar ${item.name}`}>
                        Publicar
                    </Button>
                    <Button size="sm" variant="ghost" loading={busy === 'dismiss'} disabled={busy !== null} onClick={dismiss} aria-label={`Descartar la sugerencia de ${item.name}`}>
                        Descartar
                    </Button>
                </>
            )}
            {item.group === 'expiring' && (
                <>
                    <Button size="sm" loading={busy === 'feature'} disabled={busy !== null} onClick={() => publish(true)} aria-label={`Publicar y destacar ${item.name}`}>
                        Publicar y destacar
                    </Button>
                    {can.promotions_create && (
                        <Link
                            href={route('promotions.index', { create: 1, products: [item.id] })}
                            className="inline-flex h-8 items-center rounded-chip border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.8125rem] font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                        >
                            Crear promoción
                        </Link>
                    )}
                </>
            )}
            {item.group === 'incomplete' && can.products_edit && (
                <Link
                    href={route('products.edit', item.id)}
                    className="inline-flex h-8 items-center rounded-chip border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.8125rem] font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                >
                    {onlyPrice ? 'Fijar precio' : 'Completar ficha'}
                </Link>
            )}
        </div>
    );

    if (layout === 'row') {
        return (
            <li className="flex flex-col gap-2 border-t border-[var(--line)] py-3 first:border-t-0 first:pt-0 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <p className="truncate text-[0.875rem] font-medium text-[var(--text)]">{item.name}</p>
                    <p className="text-[0.75rem] text-[var(--muted)]">{item.reason}</p>
                    <p className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">{metrics}</p>
                </div>
                <div className="shrink-0">{actions}</div>
            </li>
        );
    }

    return (
        <article
            className={cn(
                'rounded-card border bg-[var(--surface)] p-3',
                item.group === 'expiring' ? 'border-[var(--warn)]/60' : 'border-[var(--line)]',
            )}
        >
            <div className="mb-2 flex items-start gap-3">
                {item.thumb_url ? (
                    <img src={item.thumb_url} alt="" className="size-10 shrink-0 rounded-control object-cover" loading="lazy" />
                ) : (
                    <span className="grid size-10 shrink-0 place-items-center rounded-control border border-dashed border-[var(--line-2)] text-[var(--muted)]" aria-hidden="true">
                        <ImageIcon size={16} />
                    </span>
                )}
                <div className="min-w-0">
                    <p className="truncate text-[0.875rem] font-medium text-[var(--text)]">{item.name}</p>
                    <p className={cn('text-[0.75rem]', item.group === 'expiring' ? 'font-medium text-[var(--warn)]' : 'text-[var(--muted)]')}>{item.reason}</p>
                    <p className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">{metrics}</p>
                </div>
            </div>
            {actions}
        </article>
    );
}
