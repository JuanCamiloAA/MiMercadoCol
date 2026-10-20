import { useMemo, useState } from 'react';
import { cn } from '@/lib/cn';

/** Lo que necesita el filtro de una plantilla del catálogo. */
interface Grouped {
    audience_group: string | null;
}

/**
 * El filtro por familia de comercio de la galería de plantillas («Mercado», «Moda»…), el mismo
 * en Plataforma › Plantillas de tienda y en el selector del editor. Solo ofrece las familias
 * que tienen alguna plantilla; con una sola no hay nada que filtrar.
 */
export function useGroupFilter<T extends Grouped>(items: T[], order: string[] = []) {
    const [group, setGroup] = useState<string>('all');

    const groups = useMemo(() => {
        const present = Array.from(new Set(items.map((item) => item.audience_group).filter((value): value is string => !!value)));

        return present.sort((a, b) => (order.indexOf(a) === -1 ? 99 : order.indexOf(a)) - (order.indexOf(b) === -1 ? 99 : order.indexOf(b)));
    }, [items, order]);

    const active = group !== 'all' && groups.includes(group) ? group : 'all';
    const visible = active === 'all' ? items : items.filter((item) => item.audience_group === active);

    return { group: active, setGroup, groups, visible };
}

export function GroupChips<T extends Grouped>({ items, groups, value, onChange }: { items: T[]; groups: string[]; value: string; onChange: (group: string) => void }) {
    if (groups.length < 2) {
        return null;
    }

    const options = [{ value: 'all', label: 'Todas', count: items.length }, ...groups.map((group) => ({ value: group, label: group, count: items.filter((item) => item.audience_group === group).length }))];

    return (
        <div role="group" aria-label="Filtrar por tipo de comercio" className="flex flex-wrap gap-1.5">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'inline-flex min-h-[2.25rem] items-center gap-1.5 rounded-full border px-3.5 text-[0.8125rem] font-semibold transition-colors',
                        value === option.value ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] text-[var(--muted)] hover:border-[var(--line-2)] hover:text-[var(--text)]',
                    )}
                >
                    {option.label}
                    <span className="tabular text-[0.75rem] opacity-70">{option.count}</span>
                </button>
            ))}
        </div>
    );
}
