import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import { number } from '@/lib/format';
import type { ManageTab } from '@/types/storefront-manage';

interface ManageTabsProps {
    active: ManageTab | 'suggestions';
    storeId: number;
    suggestionsTotal: number;
    className?: string;
}

const TABS: { value: ManageTab; label: string }[] = [
    { value: 'products', label: 'Artículos' },
    { value: 'families', label: 'Con subartículos' },
    { value: 'services', label: 'Servicios' },
];

/**
 * Las pestañas del módulo. Son enlaces: cada una es su propia dirección y conserva la sede
 * elegida, así que un enlace guardado abre lo mismo.
 */
export function ManageTabs({ active, storeId, suggestionsTotal, className }: ManageTabsProps) {
    const item = (selected: boolean) =>
        cn(
            '-mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)]',
            selected ? 'border-[var(--accent)] text-[var(--accent)]' : 'border-transparent text-[var(--muted)] hover:text-[var(--text)]',
        );

    return (
        <nav aria-label="Secciones de Tienda en línea" className={cn('flex items-center gap-1 overflow-x-auto border-b border-[var(--line)]', className)}>
            {TABS.map((tab) => (
                <Link
                    key={tab.value}
                    href={route('storefront.catalog.index', tab.value === 'products' ? { store: storeId } : { store: storeId, tab: tab.value })}
                    aria-current={active === tab.value ? 'page' : undefined}
                    className={item(active === tab.value)}
                >
                    {tab.label}
                </Link>
            ))}
            <Link
                href={route('storefront.catalog.suggestions', { store: storeId })}
                aria-current={active === 'suggestions' ? 'page' : undefined}
                className={item(active === 'suggestions')}
            >
                Sugerencias
                <span className="rounded-full bg-[var(--accent-soft)] px-1.5 py-0.5 font-mono text-[0.6875rem] text-[var(--accent)] tabular">
                    {number(suggestionsTotal)}
                </span>
            </Link>
        </nav>
    );
}
