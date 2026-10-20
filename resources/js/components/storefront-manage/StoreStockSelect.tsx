import { useId } from 'react';
import type { StoreOption } from '@/types/storefront-manage';

interface StoreStockSelectProps {
    stores: StoreOption[];
    value: number;
    onChange: (storeId: number) => void;
}

/**
 * «Existencias de»: la sede (y la bodega que respalda su tienda) de la que se leen existencias
 * y ventas. Con una sola sede no hay nada que elegir y se dice como texto.
 */
export function StoreStockSelect({ stores, value, onChange }: StoreStockSelectProps) {
    const id = useId();
    const current = stores.find((store) => store.value === value) ?? stores[0];
    const describe = (store: StoreOption) => `${store.label} · ${store.warehouse} (tienda)`;

    if (stores.length <= 1) {
        return current ? (
            <p className="text-[0.8125rem] text-[var(--muted)]">
                Existencias de <span className="font-medium text-[var(--text)]">{describe(current)}</span>
            </p>
        ) : null;
    }

    return (
        <div className="flex items-center gap-2">
            <label htmlFor={id} className="whitespace-nowrap text-[0.8125rem] text-[var(--muted)]">
                Existencias de
            </label>
            <select
                id={id}
                value={value}
                onChange={(event) => onChange(Number(event.target.value))}
                className="h-10 max-w-[18rem] appearance-none truncate rounded-control border border-[var(--line)] bg-[var(--surface)] py-0 pl-3 pr-8 text-sm text-[var(--text)] focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/25"
                style={{
                    backgroundImage:
                        "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23565E64' stroke-width='2.5' stroke-linecap='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E\")",
                    backgroundRepeat: 'no-repeat',
                    backgroundPosition: 'right 10px center',
                }}
            >
                {stores.map((store) => (
                    <option key={store.value} value={store.value}>
                        {describe(store)}
                    </option>
                ))}
            </select>
        </div>
    );
}
