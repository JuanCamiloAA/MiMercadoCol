/** Lo que arma `App\Support\Maps\MapConfig`: el navegador nunca arma una dirección de teselas. */
interface MapConfigBase {
    provider: string;
    /** Donde arranca un mapa sin nada que mostrar: [latitud, longitud]. */
    center: [number, number];
    zoom: number;
}

export interface TileMapConfig extends MapConfigBase {
    driver: 'leaflet';
    tiles: {
        url: string;
        dark_url: string | null;
        /** Sin estilo oscuro propio: se oscurece el claro con un filtro. */
        dark_filter: boolean;
        /** HTML armado en el servidor con textos fijos; el crédito libre llega escapado. */
        attribution: string;
        max_zoom: number;
        tile_size: number;
        zoom_offset: number;
    };
}

export interface GoogleMapConfig extends MapConfigBase {
    driver: 'google';
    google: { key: string; map_id: string | null; map_type: 'roadmap' | 'terrain' | 'hybrid' | 'satellite' };
}

export type MapConfig = TileMapConfig | GoogleMapConfig;

export type MapTheme = 'light' | 'dark';

/** `cooperative`: la página sigue bajando con la rueda o un dedo; `greedy`: el mapa se lo queda todo. */
export type MapGestures = 'cooperative' | 'greedy';

export interface LatLng {
    lat: number;
    lng: number;
}

export interface Bounds {
    north: number;
    south: number;
    east: number;
    west: number;
}

export interface MapView {
    bounds: Bounds;
    zoom: number;
}

export interface MapMarker {
    key: string;
    position: LatLng;
    /** El mismo elemento entre llamadas: así el resaltado no se pierde al moverse el mapa. */
    element: HTMLElement;
    title?: string;
    zIndex?: number;
    onClick?: () => void;
}

export type MapErrorReason = 'load' | 'auth' | 'tiles' | 'other-key';

/** El motor no pudo arrancar: el script no cargó, o Google ya está cargado con otra llave. */
export class MapLoadError extends Error {
    readonly reason: MapErrorReason;

    constructor(reason: MapErrorReason) {
        super(reason);
        this.reason = reason;
    }
}

export interface MapHandle {
    /** Reemplaza los marcadores: los que siguen se mueven, los que sobran se quitan. */
    setMarkers(markers: MapMarker[]): void;
    fitTo(points: LatLng[], options?: { maxZoom?: number; padding?: number }): void;
    flyTo(point: LatLng, zoom?: number): void;
    view(): MapView;
    setUserLocation(point: LatLng | null): void;
    /** El pin arrastrable del formulario de sedes. */
    setPin(point: LatLng | null, onMove?: (point: LatLng) => void): void;
    resize(): void;
    destroy(): void;
}

export interface CreateMapOptions<C extends MapConfig = MapConfig> {
    config: C;
    theme: MapTheme;
    gestures: MapGestures;
    center?: LatLng;
    zoom?: number;
    /** `byUser`: lo movió la persona (arrastre, rueda, botones), no el código. */
    onViewChange?: (view: MapView, byUser: boolean) => void;
    onClick?: (point: LatLng) => void;
    onError?: (reason: MapErrorReason) => void;
    /** Pista de gestos en modo cooperativo («usa dos dedos…»). */
    onHint?: (hint: string) => void;
}

export const inBounds = (point: LatLng, bounds: Bounds): boolean =>
    point.lat <= bounds.north && point.lat >= bounds.south && point.lng <= bounds.east && point.lng >= bounds.west;
