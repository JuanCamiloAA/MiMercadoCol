import { cn } from '@/lib/cn';
import { number } from '@/lib/format';
import type { QuickView } from '@/types/storefront-manage';

interface QuickViewsProps {
    views: QuickView[];
    /** La vista activa; vacía es «Todos». */
    active: string;
    onChange: (view: string) => void;
}

/**
 * Las vistas rápidas: cada chip dice cuántos quedan si lo eligen. «Publicados sin stock» va
 * siempre en ámbar, para que no pase desapercibido aunque no esté elegido.
 */
export function QuickViews({ views, active, onChange }: QuickViewsProps) {
    const current = active === '' ? 'all' : active;

    return (
        <div role="group" aria-label="Vistas rápidas" className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            {views.map((view) => {
                const selected = current === view.value;
                const warn = view.tone === 'warn';

                return (
                    <button
                        key={view.value}
                        type="button"
                        aria-pressed={selected}
                        onClick={() => onChange(view.value === 'all' ? '' : view.value)}
                        className={cn(
                            'inline-flex h-9 shrink-0 items-center gap-2 rounded-chip border px-3 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)]',
                            selected && !warn && 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]',
                            selected && warn && 'border-[var(--warn)] bg-[var(--warn-soft)] text-[var(--warn)] ring-1 ring-[var(--warn)]',
                            !selected && warn && 'border-[var(--warn)]/40 bg-[var(--warn-soft)] text-[var(--warn)] hover:border-[var(--warn)]',
                            !selected && !warn && 'border-[var(--line)] bg-[var(--surface)] text-[var(--text)] hover:bg-[var(--surface-2)]',
                        )}
                    >
                        {view.label}
                        <span
                            className={cn(
                                'rounded-full px-1.5 py-0.5 font-mono text-[0.6875rem] leading-none tabular',
                                selected && !warn ? 'bg-black/15' : 'bg-[var(--surface-2)]',
                                warn && 'bg-[var(--warn)]/15',
                            )}
                        >
                            {number(view.count)}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
