import { MapTrifoldIcon } from '@phosphor-icons/react';
import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import { createMap } from './create-map';
import './maps.css';
import { type LatLng, type MapConfig, type MapErrorReason, type MapGestures, type MapHandle, MapLoadError, type MapTheme, type MapView } from './types';

interface MapCanvasProps {
    config: MapConfig;
    theme: MapTheme;
    gestures: MapGestures;
    /** Para lectores de pantalla: qué muestra el mapa. */
    label: string;
    center?: LatLng;
    zoom?: number;
    className?: string;
    /** Se llama con el mapa listo, y con `null` cuando se desarma (cambio de tema o de proveedor). */
    onReady?: (handle: MapHandle | null) => void;
    onViewChange?: (view: MapView, byUser: boolean) => void;
    onClick?: (point: LatLng) => void;
    onError?: (reason: MapErrorReason | null) => void;
    /** Qué decir si el mapa no carga; por defecto, un aviso corto para el público. */
    errorContent?: (reason: MapErrorReason) => ReactNode;
    /** Botones, fichas y avisos encima del mapa. Llevan `map-overlay` para quedar sobre los controles. */
    children?: ReactNode;
}

/**
 * Un mapa con el proveedor que diga Plataforma > Mapas. No descarga nada hasta que está cerca de
 * la pantalla, y si el proveedor falla lo dice en vez de quedar en gris.
 */
export function MapCanvas({ config, theme, gestures, label, center, zoom, className, errorContent, children, ...callbacks }: MapCanvasProps) {
    const wrapper = useRef<HTMLDivElement>(null);
    const holder = useRef<HTMLDivElement>(null);
    const latest = useRef(callbacks);
    const start = useRef({ center, zoom });
    const hintTimer = useRef<number | undefined>(undefined);
    const [near, setNear] = useState(false);
    const [ready, setReady] = useState(false);
    const [error, setError] = useState<MapErrorReason | null>(null);
    const [hint, setHint] = useState<string | null>(null);
    const configKey = JSON.stringify(config);

    latest.current = callbacks;
    start.current = { center, zoom };

    const showHint = useCallback((text: string) => {
        setHint(text);
        window.clearTimeout(hintTimer.current);
        hintTimer.current = window.setTimeout(() => setHint(null), 1500);
    }, []);

    useEffect(() => () => window.clearTimeout(hintTimer.current), []);

    useEffect(() => {
        const element = wrapper.current;

        if (!element || typeof IntersectionObserver === 'undefined') {
            setNear(true);

            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    setNear(true);
                    observer.disconnect();
                }
            },
            { rootMargin: '300px' },
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        const host = holder.current;

        if (!near || !host) {
            return;
        }

        // Un contenedor nuevo por mapa: Leaflet y Google dejan rastro en el que usaron.
        const container = document.createElement('div');
        container.style.position = 'absolute';
        container.style.inset = '0';
        host.append(container);

        let handle: MapHandle | null = null;
        let cancelled = false;
        const fail = (reason: MapErrorReason) => {
            setError(reason);
            latest.current.onError?.(reason);
        };

        setReady(false);
        setError(null);
        latest.current.onError?.(null);

        createMap(container, {
            config: JSON.parse(configKey) as MapConfig,
            theme,
            gestures,
            center: start.current.center,
            zoom: start.current.zoom,
            onViewChange: (view, byUser) => latest.current.onViewChange?.(view, byUser),
            onClick: (point) => latest.current.onClick?.(point),
            onError: fail,
            onHint: showHint,
        })
            .then((created) => {
                if (cancelled) {
                    created.destroy();

                    return;
                }

                handle = created;
                setReady(true);
                latest.current.onReady?.(created);
            })
            .catch((exception: unknown) => {
                if (!cancelled) {
                    fail(exception instanceof MapLoadError ? exception.reason : 'load');
                }
            });

        const resize = new ResizeObserver(() => handle?.resize());
        resize.observe(host);

        return () => {
            cancelled = true;
            resize.disconnect();

            if (handle) {
                latest.current.onReady?.(null);
                handle.destroy();
            }

            container.remove();
        };
    }, [near, configKey, theme, gestures, showHint]);

    return (
        <div ref={wrapper} role="region" aria-label={label} className={cn('map-canvas relative isolate overflow-hidden bg-[var(--surface-2)]', theme === 'dark' && 'map-dark', className)}>
            <div ref={holder} className="absolute inset-0" />

            {!ready && !error && (
                <div className="pointer-events-none absolute inset-0 grid place-items-center text-[var(--muted)]">
                    <MapTrifoldIcon size={32} className="animate-pulse" />
                </div>
            )}

            {!error && children}

            {hint && (
                <div className="map-overlay pointer-events-none absolute inset-x-0 top-1/2 mx-auto w-fit -translate-y-1/2 rounded-full bg-black/75 px-4 py-2 text-[0.8125rem] font-medium text-white">
                    {hint}
                </div>
            )}

            {error && (
                <div className="map-overlay absolute inset-0 grid place-items-center bg-[var(--surface-2)] p-4 text-center text-[0.875rem] text-[var(--muted)]">
                    <div className="flex max-w-[22rem] flex-col items-center gap-2">
                        <MapTrifoldIcon size={28} />
                        {errorContent ? errorContent(error) : <p>No pudimos cargar el mapa. La lista de tiendas sigue funcionando.</p>}
                    </div>
                </div>
            )}
        </div>
    );
}
