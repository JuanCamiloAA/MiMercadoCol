import { usePage } from '@inertiajs/react';
import { useCallback, useSyncExternalStore } from 'react';

export type TableView = 'table' | 'cards';

export const TABLE_VIEW_STORAGE_KEY = 'lacanasta.table-views';

/**
 * Como ve cada quien cada listado: en tabla o en tarjetas.
 *
 * Igual que el tema, vive en `localStorage` y no en la base: es de quien mira la pantalla, no de
 * la cuenta ni de la empresa. Se guarda por pantalla (el componente de Inertia) y, si una pantalla
 * separa sus listados, por su nombre. Solo se guardan las tarjetas: la tabla es lo de siempre.
 */
const listeners = new Set<() => void>();

let views: Record<string, TableView> | null = null;
let started = false;

function load(): Record<string, TableView> {
    try {
        const raw: unknown = JSON.parse(window.localStorage.getItem(TABLE_VIEW_STORAGE_KEY) ?? '{}');

        if (raw === null || typeof raw !== 'object') {
            return {};
        }

        return Object.fromEntries(Object.entries(raw).filter(([, value]) => value === 'cards')) as Record<string, TableView>;
    } catch {
        // Ventana privada, almacenamiento bloqueado o un valor dañado: todo en tabla.
        return {};
    }
}

function current(): Record<string, TableView> {
    views ??= load();

    return views;
}

function subscribe(listener: () => void): () => void {
    if (!started && typeof window !== 'undefined') {
        started = true;

        // Otra pestaña cambio la vista: esta la sigue.
        window.addEventListener('storage', (event) => {
            if (event.key === TABLE_VIEW_STORAGE_KEY) {
                views = load();
                listeners.forEach((notify) => notify());
            }
        });
    }

    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function write(key: string, view: TableView): void {
    const next = { ...current() };

    if (view === 'cards') {
        next[key] = view;
    } else {
        delete next[key];
    }

    views = next;

    try {
        window.localStorage.setItem(TABLE_VIEW_STORAGE_KEY, JSON.stringify(next));
    } catch {
        // Sin almacenamiento el cambio dura lo que dure la pagina.
    }

    listeners.forEach((notify) => notify());
}

/** La vista de un listado y como cambiarla. Dos listados con la misma llave se mueven juntos. */
export function useTableView(name?: string): [TableView, (view: TableView) => void] {
    const { component } = usePage();
    const key = name ? `${component}#${name}` : component;

    const view = useSyncExternalStore(
        subscribe,
        () => current()[key] ?? 'table',
        () => 'table' as const,
    );

    const setView = useCallback((next: TableView) => write(key, next), [key]);

    return [view, setView];
}
