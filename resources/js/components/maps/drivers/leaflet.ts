import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { watchUserInput } from '../create-map';
import type { CreateMapOptions, LatLng, MapHandle, MapMarker, MapView, TileMapConfig } from '../types';

/**
 * Motor de teselas: OpenStreetMap, MapTiler, Mapbox o uno propio. Solo cambia la dirección que
 * llega del servidor.
 */
export function createLeafletMap(container: HTMLElement, options: CreateMapOptions<TileMapConfig>): MapHandle {
    const { config, theme, gestures } = options;
    const { tiles } = config;
    const cooperative = gestures === 'cooperative';
    const touch = window.matchMedia?.('(pointer: coarse)').matches ?? false;
    const start = options.center ?? { lat: config.center[0], lng: config.center[1] };
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    const map = L.map(container, {
        center: [start.lat, start.lng],
        zoom: options.zoom ?? config.zoom,
        maxZoom: tiles.max_zoom,
        zoomControl: false,
        // En modo cooperativo, en pantalla táctil un dedo baja la página y dos mueven el mapa.
        dragging: !(cooperative && touch),
        worldCopyJump: true,
    });

    map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>');
    L.control.zoom({ position: 'topright', zoomInTitle: 'Acercar', zoomOutTitle: 'Alejar' }).addTo(map);

    const dark = theme === 'dark';
    const layer = L.tileLayer(dark && tiles.dark_url ? tiles.dark_url : tiles.url, {
        attribution: tiles.attribution,
        maxZoom: tiles.max_zoom,
        tileSize: tiles.tile_size,
        zoomOffset: tiles.zoom_offset,
    }).addTo(map);
    container.classList.toggle('map-dark-filter', dark && !tiles.dark_url && tiles.dark_filter);

    // Una llave mala o un estilo que no existe no lanza nada: solo fallan las teselas.
    let loaded = 0;
    let failed = 0;
    layer.on('tileload', () => {
        loaded += 1;
    });
    layer.on('tileerror', () => {
        failed += 1;

        if (loaded === 0 && failed === 4) {
            options.onError?.('tiles');
        }
    });

    // La rueda sin Ctrl no le llega a Leaflet: la página sigue bajando.
    const host = container.parentElement ?? container;
    const onWheel = (event: WheelEvent) => {
        if (cooperative && !event.ctrlKey && !event.metaKey) {
            event.stopPropagation();
            options.onHint?.('Para acercar el mapa usa Ctrl + rueda');
        }
    };
    const onTouchMove = (event: TouchEvent) => {
        if (cooperative && touch && event.touches.length === 1) {
            options.onHint?.('Para mover el mapa usa dos dedos');
        }
    };
    host.addEventListener('wheel', onWheel, { capture: true });
    host.addEventListener('touchmove', onTouchMove, { passive: true });

    const input = watchUserInput(container);
    const view = (): MapView => {
        const bounds = map.getBounds();

        return { bounds: { north: bounds.getNorth(), south: bounds.getSouth(), east: bounds.getEast(), west: bounds.getWest() }, zoom: map.getZoom() };
    };

    // Se decide al empezar: con la inercia, el arrastre termina bastante después del último toque.
    let byUser = false;
    map.on('movestart', () => {
        byUser = input.recent();
    });
    map.on('moveend', () => options.onViewChange?.(view(), byUser));
    map.on('click', (event: L.LeafletMouseEvent) => options.onClick?.({ lat: event.latlng.lat, lng: event.latlng.lng }));

    // El icono mide cero y el elemento se ancla solo con CSS (`.map-pin`, `.map-cluster`).
    const icon = (element: HTMLElement) => L.divIcon({ html: element, className: 'map-marker', iconSize: [0, 0] });
    const markers = new Map<string, { marker: L.Marker; element: HTMLElement }>();
    let user: L.Marker | null = null;
    let pin: L.Marker | null = null;

    const setMarkers = (list: MapMarker[]) => {
        const keep = new Set(list.map((item) => item.key));

        markers.forEach((entry, key) => {
            if (!keep.has(key)) {
                entry.marker.remove();
                markers.delete(key);
            }
        });

        list.forEach((item) => {
            const existing = markers.get(item.key);

            if (existing) {
                existing.marker.setLatLng([item.position.lat, item.position.lng]);

                if (existing.element !== item.element) {
                    existing.marker.setIcon(icon(item.element));
                    existing.element = item.element;
                }

                existing.marker.setZIndexOffset(item.zIndex ?? 0);
                existing.marker.off('click').on('click', () => item.onClick?.());

                return;
            }

            const marker = L.marker([item.position.lat, item.position.lng], {
                icon: icon(item.element),
                title: item.title,
                alt: item.title,
                zIndexOffset: item.zIndex ?? 0,
                riseOnHover: true,
            }).addTo(map);

            marker.on('click', () => item.onClick?.());
            markers.set(item.key, { marker, element: item.element });
        });
    };

    return {
        setMarkers,
        fitTo(points, { maxZoom = 15, padding = 48 } = {}) {
            if (points.length === 0) {
                return;
            }

            if (points.length === 1) {
                map.setView([points[0].lat, points[0].lng], Math.min(maxZoom, tiles.max_zoom));

                return;
            }

            map.fitBounds(L.latLngBounds(points.map((point) => [point.lat, point.lng] as L.LatLngTuple)), { padding: [padding, padding], maxZoom });
        },
        flyTo(point, zoom) {
            const target = zoom ?? Math.max(map.getZoom(), 15);

            if (reducedMotion) {
                map.setView([point.lat, point.lng], target);
            } else {
                map.flyTo([point.lat, point.lng], target, { duration: 0.6 });
            }
        },
        view,
        setUserLocation(point) {
            if (!point) {
                user?.remove();
                user = null;

                return;
            }

            if (user) {
                user.setLatLng([point.lat, point.lng]);

                return;
            }

            const element = document.createElement('span');
            element.className = 'map-user';
            user = L.marker([point.lat, point.lng], { icon: icon(element), interactive: false, keyboard: false, zIndexOffset: -500, title: 'Estás aquí' }).addTo(map);
        },
        setPin(point, onMove) {
            if (!point) {
                pin?.remove();
                pin = null;

                return;
            }

            if (pin) {
                pin.setLatLng([point.lat, point.lng]);
            } else {
                const element = document.createElement('span');
                element.className = 'map-picker-pin';
                pin = L.marker([point.lat, point.lng], { icon: icon(element), draggable: true, autoPan: true, title: 'Ubicación de la sede', zIndexOffset: 1000 }).addTo(map);
            }

            const current = pin;
            current.off('dragend');

            if (onMove) {
                current.on('dragend', () => {
                    const position: LatLng = { lat: current.getLatLng().lat, lng: current.getLatLng().lng };
                    onMove(position);
                });
            }
        },
        resize() {
            map.invalidateSize({ pan: false });
        },
        destroy() {
            input.stop();
            host.removeEventListener('wheel', onWheel, { capture: true });
            host.removeEventListener('touchmove', onTouchMove);
            map.remove();
            container.classList.remove('map-dark-filter');
        },
    };
}
