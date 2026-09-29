import { useSyncExternalStore } from 'react';
import { toast } from 'sonner';
import { csrfHeaders, errorMessage } from '@/lib/http';
import { number } from '@/lib/format';
import type { CartBrief } from '@/types/storefront';

/**
 * El carrito en el navegador.
 *
 * Es optimista: el contador cambia al instante, y los toques seguidos se agrupan (350 ms) en
 * una sola petición con la cantidad final —absoluta, no sumada—. El total nunca se calcula
 * aquí: se muestra el último que dijo el servidor, que es el mismo del checkout. Si el servidor
 * no puede (no hay existencias), se vuelve a lo que él diga y se avisa cuánto hay.
 */

export interface CartItemRef {
    kind: 'product' | 'service';
    id: number;
    key: string;
    name: string;
    unit?: string;
    sold_by_weight?: boolean;
    /** En un servicio: la presentación elegida de cada artículo completo (familia => subartículo). */
    choices?: Record<number, number>;
}

interface CartState {
    brief: CartBrief | null;
    /** Cantidades que el cliente ya pidió y el servidor todavía no confirma. */
    pending: Record<string, number>;
    /** Lo último que se dijo, para los lectores de pantalla. */
    announcement: string;
    /** Cambia cada vez que el servidor confirma algo: la página del carrito recarga sus líneas. */
    revision: number;
}

const DEBOUNCE_MS = 350;

let state: CartState = { brief: null, pending: {}, announcement: '', revision: 0 };
const listeners = new Set<() => void>();
const timers = new Map<string, ReturnType<typeof setTimeout>>();
const tickets = new Map<string, number>();
let sequence = 0;

function emit(next: Partial<CartState>): void {
    state = { ...state, ...next };
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/** El resumen que trajo la última visita. Lo que el cliente tiene pendiente no se pisa. */
export function seedCart(brief: CartBrief | null | undefined): void {
    if (!brief || JSON.stringify(brief) === JSON.stringify(state.brief)) {
        return;
    }

    emit({ brief });
}

export function useCart(): CartState {
    return useSyncExternalStore(subscribe, () => state, () => state);
}

export function quantityOf(cart: CartState, key: string): number {
    return cart.pending[key] ?? cart.brief?.lines.find((line) => line.key === key)?.quantity ?? 0;
}

/** Cuántas unidades hay, contando lo que el cliente acaba de tocar. Contar no es cobrar. */
export function countOf(cart: CartState): number {
    const lines = new Map((cart.brief?.lines ?? []).map((line) => [line.key, line.quantity]));

    Object.entries(cart.pending).forEach(([key, quantity]) => lines.set(key, quantity));

    return Array.from(lines.values()).reduce((sum, quantity) => sum + quantity, 0);
}

export function isSyncing(cart: CartState): boolean {
    return Object.keys(cart.pending).length > 0;
}

function describe(item: CartItemRef, quantity: number): string {
    if (quantity <= 0) {
        return `${item.name}: quitado del carrito.`;
    }

    return `${item.name}: ${number(quantity)}${item.sold_by_weight ? ' kg' : ''} en el carrito.`;
}

export function setCartQuantity(item: CartItemRef, quantity: number): void {
    const next = Math.max(0, Math.round(quantity * 1000) / 1000);

    emit({ pending: { ...state.pending, [item.key]: next }, announcement: describe(item, next) });

    const timer = timers.get(item.key);

    if (timer) {
        clearTimeout(timer);
    }

    timers.set(
        item.key,
        setTimeout(() => {
            timers.delete(item.key);
            void send(item, next);
        }, DEBOUNCE_MS),
    );
}

function settle(key: string, next: Partial<CartState>): void {
    const pending = { ...state.pending };
    delete pending[key];
    emit({ ...next, pending, revision: state.revision + 1 });
}

async function send(item: CartItemRef, quantity: number): Promise<void> {
    const ticket = ++sequence;
    tickets.set(item.key, ticket);

    try {
        const response = await fetch(route('storefront.cart.quantity'), {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { ...csrfHeaders(), 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(
                item.kind === 'service' ? { service_id: item.id, quantity, ...(item.choices ? { choices: item.choices } : {}) } : { product_id: item.id, quantity },
            ),
        });

        // Otro toque más nuevo ya salió: esta respuesta no manda.
        if (tickets.get(item.key) !== ticket || timers.has(item.key)) {
            return;
        }

        if (response.ok) {
            const body = (await response.json()) as { cart: CartBrief };
            settle(item.key, { brief: body.cart });

            return;
        }

        if (response.status === 422) {
            const body = (await response.json().catch(() => null)) as { error?: string; cart?: CartBrief; message?: string } | null;
            const message = body?.error ?? body?.message ?? 'No pudimos cambiar la cantidad.';

            settle(item.key, { brief: body?.cart ?? state.brief, announcement: message });
            toast.error(message);

            return;
        }

        const message = await errorMessage(response, 'No pudimos actualizar tu carrito.');
        settle(item.key, { announcement: message });
        toast.error(message);
    } catch {
        if (tickets.get(item.key) === ticket) {
            settle(item.key, { announcement: 'No pudimos actualizar tu carrito.' });
            toast.error('No pudimos actualizar tu carrito. Revisa tu conexión.');
        }
    }
}
