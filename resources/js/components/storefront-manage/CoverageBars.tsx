import { Link } from '@inertiajs/react';
import { number } from '@/lib/format';
import type { CategoryCoverage } from '@/types/storefront-manage';

type Segment = 'visible' | 'out_of_stock' | 'stock_unpublished' | 'rest';

const SEGMENTS: { key: Segment; label: string; color: string }[] = [
    { key: 'visible', label: 'Visible', color: 'var(--accent)' },
    { key: 'out_of_stock', label: 'Publicado sin stock', color: 'var(--warn)' },
    { key: 'stock_unpublished', label: 'Con stock sin publicar', color: '#1D6FA5' },
    { key: 'rest', label: 'Sin stock ni publicar', color: 'var(--surface-3)' },
];

interface CoverageBarsProps {
    coverage: CategoryCoverage[];
    storeId: number;
    /** La versión completa (Sugerencias): cuatro colores, visibles y pill de sugerencias. */
    detailed?: boolean;
}

/**
 * Cobertura por categoría raíz: cuántos de sus artículos activos ve el cliente, cuántos están
 * publicados pero ocultos y cuántos tienen stock sin publicar. Cada fila filtra el catálogo.
 */
export function CoverageBars({ coverage, storeId, detailed = false }: CoverageBarsProps) {
    const legend = detailed ? SEGMENTS : SEGMENTS.slice(0, 3);

    if (coverage.length === 0) {
        return <p className="text-[0.8125rem] text-[var(--muted)]">Todavía no hay artículos activos con categoría.</p>;
    }

    return (
        <div>
            <ul className="mb-3 flex flex-wrap gap-x-3 gap-y-1" aria-label="Convenciones">
                {legend.map((segment) => (
                    <li key={segment.key} className="flex items-center gap-1.5 text-[0.71875rem] text-[var(--muted)]">
                        <span className="size-2.5 rounded-chip" style={{ backgroundColor: segment.color }} aria-hidden="true" />
                        {segment.label}
                    </li>
                ))}
            </ul>

            <ul className="flex flex-col gap-1">
                {coverage.map((category) => {
                    const total = Math.max(1, category.active);
                    const summary = SEGMENTS.map((segment) => `${segment.label}: ${category[segment.key]}`).join(', ');

                    return (
                        <li key={category.id}>
                            <Link
                                href={route('storefront.catalog.index', { store: storeId, category: category.id })}
                                className="block rounded-control px-2 py-2 transition-colors hover:bg-[var(--surface-2)] focus-visible:outline-2 focus-visible:outline-[var(--accent)]"
                            >
                                <div className="mb-1.5 flex items-center justify-between gap-2">
                                    <span className="truncate text-[0.8125rem] font-medium text-[var(--text)]">{category.name}</span>
                                    <span className="flex shrink-0 items-center gap-2">
                                        <span className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">
                                            {detailed
                                                ? `${number(category.visible)} visibles / ${number(category.active)}`
                                                : `${number(category.published)} / ${number(category.active)}`}
                                        </span>
                                        {detailed && category.suggestions > 0 && (
                                            <span className="rounded-full bg-[var(--accent-soft)] px-1.5 py-0.5 text-[0.6875rem] font-medium text-[var(--accent)]">
                                                {number(category.suggestions)} {category.suggestions === 1 ? 'sugerencia' : 'sugerencias'}
                                            </span>
                                        )}
                                    </span>
                                </div>
                                <div className="flex h-2 overflow-hidden rounded-full bg-[var(--surface-3)]" role="img" aria-label={`${category.name}. ${summary}`}>
                                    {SEGMENTS.filter((segment) => segment.key !== 'rest').map((segment) =>
                                        category[segment.key] > 0 ? (
                                            <span
                                                key={segment.key}
                                                className="h-full"
                                                style={{ width: `${(category[segment.key] / total) * 100}%`, backgroundColor: segment.color }}
                                            />
                                        ) : null,
                                    )}
                                </div>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
