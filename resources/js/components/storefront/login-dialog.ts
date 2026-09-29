import { useSyncExternalStore } from 'react';

/**
 * Quién pidió entrar y a dónde se vuelve. Cualquier pieza de la tienda (encabezado, navegación
 * del celular, carrito) abre el mismo diálogo, que vive una sola vez en `StoreLayout`.
 */
export interface LoginRequest {
    open: boolean;
    /** La ruta de la tienda a la que se vuelve al entrar; por defecto, donde está el cliente. */
    returnTo: string | null;
    /** Documento ya escrito (el registro sabe que esa cuenta existe). */
    document: string;
}

let state: LoginRequest = { open: false, returnTo: null, document: '' };
const listeners = new Set<() => void>();

function emit(next: LoginRequest): void {
    state = next;
    listeners.forEach((listener) => listener());
}

export function openLogin(options: { returnTo?: string; document?: string } = {}): void {
    emit({
        open: true,
        returnTo: options.returnTo ?? window.location.pathname + window.location.search,
        document: options.document ?? '',
    });
}

export function closeLogin(): void {
    if (state.open) {
        emit({ ...state, open: false });
    }
}

export function useLoginDialog(): LoginRequest {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => state,
        () => state,
    );
}
