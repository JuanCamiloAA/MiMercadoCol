import { Link, router } from '@inertiajs/react';
import { ArrowCounterClockwiseIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { CoverageBars } from '@/components/storefront-manage/CoverageBars';
import { ManageTabs } from '@/components/storefront-manage/ManageTabs';
import { StoreStockSelect } from '@/components/storefront-manage/StoreStockSelect';
import { SuggestionCard } from '@/components/storefront-manage/SuggestionCard';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { PageHeader } from '@/components/ui/Misc';
import { Modal } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { dateTime, number } from '@/lib/format';
import type { Dismissal, SuggestionsProps } from '@/types/storefront-manage';

/**
 * Qué conviene publicar: artículos con disponible en la bodega de la tienda que todavía no están
 * en línea, agrupados por la razón para publicarlos. Uno puede estar en más de un grupo.
 */
export default function StorefrontSuggestions({ stores, store_id, warehouse, groups, total, top_seller_ids, coverage, dismissed, can }: SuggestionsProps) {
    const [showDismissed, setShowDismissed] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const [restoring, setRestoring] = useState<number | null>(null);

    const publishTop = () => {
        setPublishing(true);
        router.post(
            route('storefront.catalog.update'),
            { type: 'product', product_ids: top_seller_ids, field: 'is_online_visible', value: true, store_id },
            { preserveScroll: true, onFinish: () => setPublishing(false) },
        );
    };

    const restore = (dismissal: Dismissal) => {
        setRestoring(dismissal.id);
        router.delete(route('storefront.catalog.suggestions.restore'), {
            data: { store_id, product_id: dismissal.product_id, group: dismissal.group },
            preserveScroll: true,
            onFinish: () => setRestoring(null),
        });
    };

    return (
        <AppLayout title="Sugerencias para publicar">
            <PageHeader
                title="Tienda en línea"
                description="Qué ve el cliente, qué está publicado pero no se muestra y qué conviene publicar."
                actions={<StoreStockSelect stores={stores} value={store_id} onChange={(id) => router.get(route('storefront.catalog.suggestions', { store: id }))} />}
            />

            <ManageTabs active="suggestions" storeId={store_id} suggestionsTotal={total} className="mb-4" />

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 max-w-3xl">
                    <h2 className="text-[1.125rem] font-semibold">Sugerencias para publicar</h2>
                    <p className="mt-1 text-[0.8125rem] text-[var(--muted)]">
                        Artículos con disponible en la bodega de la tienda ({warehouse.name}) que todavía no están en línea, agrupados por la razón para publicarlos. Uno puede estar en más de
                        un grupo.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={() => setShowDismissed(true)}>
                        Ver descartados ({number(dismissed.length)})
                    </Button>
                    {top_seller_ids.length > 0 && (
                        <Button loading={publishing} onClick={publishTop}>
                            Publicar los {number(top_seller_ids.length)} más vendidos
                        </Button>
                    )}
                </div>
            </div>

            {total === 0 && groups.every((group) => group.count === 0) ? (
                <Card padded={false} className="mb-4">
                    <EmptyState title="No hay artículos con stock por publicar" description="Todo lo que tiene disponible en la bodega de la tienda ya está en línea, o lo descartaron." />
                </Card>
            ) : (
                <div className="mb-4 grid gap-4 min-[1000px]:grid-cols-2">
                    {groups.map((group) => (
                        <Card key={group.key} className={cn(group.key === 'expiring' && group.count > 0 && 'border-[var(--warn)]/50')}>
                            <CardHeader
                                title={group.title}
                                description={group.criteria}
                                action={
                                    <span
                                        className={cn(
                                            'rounded-full px-2 py-0.5 font-mono text-[0.75rem] font-medium tabular',
                                            group.key === 'expiring' ? 'bg-[var(--warn-soft)] text-[var(--warn)]' : 'bg-[var(--accent-soft)] text-[var(--accent)]',
                                        )}
                                    >
                                        {number(group.count)}
                                    </span>
                                }
                            />
                            {group.items.length === 0 ? (
                                <p className="text-[0.8125rem] text-[var(--muted)]">Ninguno por ahora.</p>
                            ) : (
                                <ul>
                                    {group.items.map((item) => (
                                        <SuggestionCard key={item.id} item={item} storeId={store_id} can={can} layout="row" />
                                    ))}
                                </ul>
                            )}
                            {group.count > group.items.length && (
                                <Link
                                    href={route('storefront.catalog.index', { store: store_id, ...group.view })}
                                    className="mt-3 inline-block text-[0.8125rem] font-medium text-[var(--accent)] hover:underline"
                                >
                                    Ver los {number(group.count)}
                                </Link>
                            )}
                        </Card>
                    ))}
                </div>
            )}

            <Card>
                <CardHeader title="Cobertura por categoría" description="Por categoría principal, sumando sus subcategorías. Cada fila abre el catálogo filtrado." />
                <CoverageBars coverage={coverage} storeId={store_id} detailed />
            </Card>

            <Modal
                open={showDismissed}
                onClose={() => setShowDismissed(false)}
                title="Sugerencias descartadas"
                description="Solo en esta sede. Restaurar devuelve la sugerencia a su grupo; publicar el artículo borra sus descartes."
                size="lg"
            >
                {dismissed.length === 0 ? (
                    <p className="text-[0.8125rem] text-[var(--muted)]">No hay descartes en esta sede.</p>
                ) : (
                    <ul className="flex flex-col">
                        {dismissed.map((dismissal) => (
                            <li key={dismissal.id} className="flex flex-col gap-2 border-t border-[var(--line)] py-3 first:border-t-0 first:pt-0 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <p className="truncate text-[0.875rem] font-medium">{dismissal.name}</p>
                                    <p className="text-[0.75rem] text-[var(--muted)]">
                                        {dismissal.group_label}
                                        {dismissal.dismissed_by ? ` · ${dismissal.dismissed_by}` : ''} · {dateTime(dismissal.dismissed_at)}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    loading={restoring === dismissal.id}
                                    onClick={() => restore(dismissal)}
                                    icon={<ArrowCounterClockwiseIcon size={14} />}
                                    aria-label={`Restaurar la sugerencia de ${dismissal.name}`}
                                >
                                    Restaurar
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Modal>
        </AppLayout>
    );
}
