import { router } from '@inertiajs/react';
import { echo, useEcho } from '@laravel/echo-react';
import { useCallback, useEffect, useRef } from 'react';
import { useRealtimeConfig } from '@/hooks/use-realtime';

/** Un cambio: de qué tema y en qué sedes (null es toda la empresa). */
interface Change {
    topic: string;
    stores: (number | null)[];
}

interface ChangesPayload {
    at: string;
    by: number | null;
    changes: Change[];
}

/**
 * Cuánto se espera antes de recargar. Una venta en caja son varios guardados y, en un mostrador
 * con dos cajas, varios avisos seguidos: se juntan en una sola recarga.
 */
const SETTLE_MS = 600;

/**
 * Mantiene al día la pantalla con lo que cambia en otras pestañas, de esta persona o de otras.
 *
 * El servidor dice qué temas muestra cada pantalla (`realtime.topics`) y avisa qué cambió
 * (`ResourcesChanged`): si coinciden, la pantalla vuelve a pedir sus datos por la ruta de
 * siempre, conservando lo que se está escribiendo y el desplazamiento. Si la pestaña no está a la
 * vista, recarga al volver a ella y no antes: diez pestañas escondidas no le piden diez páginas al
 * servidor por cada venta.
 */
export function LiveRefresh() {
    const config = useRealtimeConfig();
    const channel = config.channels.changes;
    const topics = config.topics ?? [];

    if (!config.enabled || !channel || topics.length === 0) {
        return <SocketHeader enabled={config.enabled} />;
    }

    return (
        <>
            <SocketHeader enabled />
            <Listener channel={channel} topics={topics} storeId={config.store_id} />
        </>
    );
}

function Listener({ channel, topics, storeId }: { channel: string; topics: string[]; storeId: number | null }) {
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const stale = useRef(false);
    const topicKey = topics.join(',');

    const reload = useCallback(() => {
        if (document.visibilityState !== 'visible') {
            stale.current = true;

            return;
        }

        stale.current = false;
        router.reload();
    }, []);

    const schedule = useCallback(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            timer.current = null;
            reload();
        }, SETTLE_MS);
    }, [reload]);

    useEffect(() => {
        const onVisible = () => {
            if (document.visibilityState === 'visible' && stale.current) {
                reload();
            }
        };

        document.addEventListener('visibilitychange', onVisible);

        return () => {
            document.removeEventListener('visibilitychange', onVisible);

            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [reload]);

    useEcho<ChangesPayload>(
        channel,
        '.ResourcesChanged',
        (payload) => {
            const wanted = topicKey.split(',');
            const relevant = (payload.changes ?? []).some(
                (change) =>
                    wanted.includes(change.topic) &&
                    // Lo de otra sede no le interesa a quien mira la suya; lo de toda la empresa, sí.
                    (storeId === null || change.stores.some((store) => store === null || store === storeId)),
            );

            if (relevant) {
                schedule();
            }
        },
        [topicKey, storeId, schedule],
    );

    return null;
}

/**
 * Cada visita lleva el id de este socket (`X-Socket-ID`): el servidor transmite con `toOthers` y
 * así la pestaña que hizo el cambio, que ya tiene la respuesta, no se recarga a sí misma.
 */
function SocketHeader({ enabled }: { enabled: boolean }) {
    useEffect(() => {
        if (!enabled) {
            return;
        }

        return router.on('before', (event) => {
            let socketId: string | undefined;

            try {
                socketId = echo().socketId();
            } catch {
                // Echo todavía no conecta: la visita sale sin el encabezado y la pestaña se entera
                // de su propio cambio, que no hace daño.
                socketId = undefined;
            }

            if (socketId) {
                event.detail.visit.headers = { ...event.detail.visit.headers, 'X-Socket-ID': socketId };
            }
        });
    }, [enabled]);

    return null;
}
