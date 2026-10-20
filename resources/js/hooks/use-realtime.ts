import { router, usePage } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useCallback, useEffect, useMemo, useRef } from 'react';
import type { PageProps } from '@/types';

/** Los canales que el servidor le arma a esta persona. Nunca se componen aquí. */
export type ChannelKey = 'orders' | 'pos' | 'registers' | 'inventory' | 'catalog' | 'presence' | 'changes';

export interface RealtimeConfig {
    enabled: boolean;
    company_id: number | null;
    store_id: number | null;
    channels: Partial<Record<ChannelKey, string>>;
    /** Los temas que muestra esta pantalla (`Topics::ROUTES`); vacío, no se recarga sola. */
    topics?: string[];
}

/** Lo mínimo que trae cualquier evento del sistema. */
export interface RealtimeEventPayload {
    at: string;
    [key: string]: unknown;
}

/**
 * Cuánto se espera antes de recargar.
 *
 * Cinco ventas seguidas en el mostrador son cinco eventos en un segundo. Sin esta pausa serían
 * cinco recargas, y la pantalla parpadearía más de lo que informa.
 */
const SETTLE_MS = 400;

export function useRealtimeConfig(): RealtimeConfig {
    const { realtime } = usePage<PageProps>().props;

    return (
        realtime ?? {
            enabled: false,
            company_id: null,
            store_id: null,
            channels: {},
            topics: [],
        }
    );
}

/**
 * Estado de la conexión, para poder decirlo en pantalla.
 *
 * Sin esto, «no ha llegado nada» y «el socket está muerto» se ven exactamente igual, y quien
 * está en caja no tiene cómo saber cuál de las dos es.
 */
export function useRealtimeStatus(): { connected: boolean; label: string; enabled: boolean } {
    const { enabled } = useRealtimeConfig();
    const status = useConnectionStatus();

    if (!enabled) {
        return { connected: false, enabled: false, label: 'Tiempo real apagado' };
    }

    switch (status) {
        case 'connected':
            return { connected: true, enabled: true, label: 'Al día' };
        case 'connecting':
        case 'reconnecting':
            return { connected: false, enabled: true, label: 'Reconectando…' };
        default:
            // `disconnected` y `failed`: el servidor de sockets no está.
            return { connected: false, enabled: true, label: 'Sin conexión en vivo' };
    }
}

interface RealtimeOptions {
    /**
     * Qué props de Inertia se vuelven a pedir cuando llega un evento.
     *
     * Este es el patrón: el evento dice «algo cambió», y los datos se piden por el mismo camino
     * de siempre, donde la autorización y el filtro por empresa ya están resueltos. Mandar la
     * fila entera por el socket obligaría a repetir aquí cada regla de visibilidad.
     */
    reload?: string[];
    /** Para lo que no es recargar: un aviso, un sonido, sumarle uno a un contador. */
    onEvent?: (payload: RealtimeEventPayload, event: string) => void;
    /** Apaga la suscripción sin romper las reglas de los hooks. */
    enabled?: boolean;
}

/**
 * Escucha un canal y mantiene la pantalla al día.
 *
 * Si no hay canal —porque no hay sede activa, o el tiempo real está apagado— no se suscribe a
 * nada y la pantalla funciona igual que siempre.
 */
export function useRealtime(channel: ChannelKey, events: string | string[], options: RealtimeOptions = {}): void {
    const config = useRealtimeConfig();
    const name = config.channels[channel];

    const { reload, onEvent, enabled = true } = options;
    const listening = config.enabled && enabled && typeof name === 'string' && name !== '';

    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const reloadKeys = useMemo(() => reload ?? [], [reload]);

    // Se guardan en una referencia para que cambiar la función no vuelva a suscribir el canal.
    const handler = useRef(onEvent);
    handler.current = onEvent;

    const settle = useCallback(() => {
        if (reloadKeys.length === 0) {
            return;
        }

        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            router.reload({ only: reloadKeys });
            timer.current = null;
        }, SETTLE_MS);
    }, [reloadKeys]);

    useEffect(
        () => () => {
            if (timer.current) {
                clearTimeout(timer.current);
                timer.current = null;
            }
        },
        [],
    );

    useEcho<RealtimeEventPayload>(
        listening ? name : '',
        events,
        (payload) => {
            handler.current?.(payload, Array.isArray(events) ? '' : events);
            settle();
        },
        [listening, name, settle],
    );
}
