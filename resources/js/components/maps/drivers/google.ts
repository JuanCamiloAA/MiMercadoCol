/// <reference types="google.maps" />
import { watchUserInput } from '../create-map';
import { type CreateMapOptions, type GoogleMapConfig, type LatLng, type MapHandle, MapLoadError, type MapMarker, type MapView } from '../types';

declare global {
    interface Window {
        gm_authFailure?: () => void;
        __canastaMapsReady?: () => void;
    }
}

let loading: Promise<void> | null = null;
let loadedKey: string | null = null;
const authListeners = new Set<() => void>();

/**
 * Google se carga una sola vez por página y con una sola llave. Una llave rechazada no lanza
 * error: Google llama `gm_authFailure` y pinta su aviso encima del mapa.
 */
function loadGoogle(key: string): Promise<void> {
    if (loadedKey !== null && loadedKey !== key) {
        return Promise.reject(new MapLoadError('other-key'));
    }

    if (loading) {
        return loading;
    }

    loadedKey = key;
    window.gm_authFailure = () => authListeners.forEach((listener) => listener());
    loading = new Promise<void>((resolve, reject) => {
        window.__canastaMapsReady = () => resolve();

        const params = new URLSearchParams({ key, v: 'weekly', loading: 'async', callback: '__canastaMapsReady', language: 'es', region: 'CO' });
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?${params.toString()}`;
        script.async = true;
        script.onerror = () => {
            loading = null;
            loadedKey = null;
            script.remove();
            reject(new MapLoadError('load'));
        };
        document.head.appendChild(script);
    });

    return loading;
}

const toLatLng = (position: google.maps.LatLng | google.maps.LatLngLiteral | google.maps.LatLngAltitudeLiteral | null | undefined): LatLng | null => {
    if (!position) {
        return null;
    }

    return typeof position.lat === 'function' ? { lat: (position as google.maps.LatLng).lat(), lng: (position as google.maps.LatLng).lng() } : { lat: position.lat as number, lng: position.lng as number };
};

export async function createGoogleMap(container: HTMLElement, options: CreateMapOptions<GoogleMapConfig>): Promise<MapHandle> {
    const { config, theme, gestures } = options;

    await loadGoogle(config.google.key);

    const { Map } = (await google.maps.importLibrary('maps')) as google.maps.MapsLibrary;
    const { AdvancedMarkerElement } = (await google.maps.importLibrary('marker')) as google.maps.MarkerLibrary;
    const start = options.center ?? { lat: config.center[0], lng: config.center[1] };

    const map = new Map(container, {
        center: start,
        zoom: options.zoom ?? config.zoom,
        // Los marcadores con forma propia piden un Map ID; sin uno se usa el de demostración.
        mapId: config.google.map_id ?? 'DEMO_MAP_ID',
        mapTypeId: config.google.map_type,
        colorScheme: theme === 'dark' ? 'DARK' : 'LIGHT',
        disableDefaultUI: true,
        zoomControl: true,
        clickableIcons: false,
        gestureHandling: gestures === 'cooperative' ? 'cooperative' : 'greedy',
    });

    const onAuthFailure = () => options.onError?.('auth');
    authListeners.add(onAuthFailure);

    const input = watchUserInput(container);
    const view = (): MapView => {
        const bounds = map.getBounds()?.toJSON();
        const center = toLatLng(map.getCenter()) ?? start;

        return {
            bounds: bounds ?? { north: center.lat, south: center.lat, east: center.lng, west: center.lng },
            zoom: map.getZoom() ?? config.zoom,
        };
    };

    // Se decide con el primer cambio, no al quedar quieto: la inercia alarga el movimiento.
    let byUser: boolean | null = null;
    map.addListener('bounds_changed', () => {
        byUser ??= input.recent();
    });
    map.addListener('idle', () => {
        options.onViewChange?.(view(), byUser ?? false);
        byUser = null;
    });
    map.addListener('click', (event: google.maps.MapMouseEvent) => {
        const point = toLatLng(event.latLng);

        if (point) {
            options.onClick?.(point);
        }
    });

    type Entry = { marker: google.maps.marker.AdvancedMarkerElement; element: HTMLElement; listener: google.maps.MapsEventListener };
    const markers = new globalThis.Map<string, Entry>();
    let user: google.maps.marker.AdvancedMarkerElement | null = null;
    let pin: google.maps.marker.AdvancedMarkerElement | null = null;
    let pinListener: google.maps.MapsEventListener | null = null;

    const setMarkers = (list: MapMarker[]) => {
        const keep = new Set(list.map((item) => item.key));

        markers.forEach((entry, key) => {
            if (!keep.has(key)) {
                entry.listener.remove();
                entry.marker.map = null;
                markers.delete(key);
            }
        });

        list.forEach((item) => {
            const existing = markers.get(item.key);

            if (existing) {
                existing.marker.position = item.position;
                existing.marker.zIndex = item.zIndex ?? null;

                if (existing.element !== item.element) {
                    existing.marker.content = item.element;
                    existing.element = item.element;
                }

                existing.listener.remove();
                existing.listener = existing.marker.addListener('click', () => item.onClick?.());

                return;
            }

            const marker = new AdvancedMarkerElement({ map, position: item.position, content: item.element, title: item.title ?? '', zIndex: item.zIndex ?? null, gmpClickable: true });
            markers.set(item.key, { marker, element: item.element, listener: marker.addListener('click', () => item.onClick?.()) });
        });
    };

    return {
        setMarkers,
        fitTo(points, { maxZoom = 15, padding = 48 } = {}) {
            if (points.length === 0) {
                return;
            }

            if (points.length === 1) {
                map.setCenter(points[0]);
                map.setZoom(maxZoom);

                return;
            }

            const bounds = new google.maps.LatLngBounds();
            points.forEach((point) => bounds.extend(point));
            map.fitBounds(bounds, padding);
            google.maps.event.addListenerOnce(map, 'idle', () => {
                if ((map.getZoom() ?? 0) > maxZoom) {
                    map.setZoom(maxZoom);
                }
            });
        },
        flyTo(point, zoom) {
            map.panTo(point);
            map.setZoom(zoom ?? Math.max(map.getZoom() ?? 0, 15));
        },
        view,
        setUserLocation(point) {
            if (!point) {
                if (user) {
                    user.map = null;
                }

                user = null;

                return;
            }

            if (user) {
                user.position = point;

                return;
            }

            const element = document.createElement('span');
            element.className = 'map-user';
            user = new AdvancedMarkerElement({ map, position: point, content: element, title: 'Estás aquí', zIndex: -1 });
        },
        setPin(point, onMove) {
            if (!point) {
                if (pin) {
                    pin.map = null;
                }

                pinListener?.remove();
                pin = null;

                return;
            }

            if (pin) {
                pin.position = point;
            } else {
                const element = document.createElement('span');
                element.className = 'map-picker-pin';
                pin = new AdvancedMarkerElement({ map, position: point, content: element, title: 'Ubicación de la sede', gmpDraggable: true, zIndex: 1000 });
            }

            const current = pin;
            pinListener?.remove();
            pinListener = onMove
                ? current.addListener('dragend', () => {
                      const position = toLatLng(current.position);

                      if (position) {
                          onMove(position);
                      }
                  })
                : null;
        },
        resize() {
            // Google mide su contenedor solo.
        },
        destroy() {
            authListeners.delete(onAuthFailure);
            input.stop();
            markers.forEach((entry) => {
                entry.listener.remove();
                entry.marker.map = null;
            });
            markers.clear();

            if (user) {
                user.map = null;
            }

            if (pin) {
                pin.map = null;
            }

            google.maps.event.clearInstanceListeners(map);
            container.replaceChildren();
        },
    };
}
