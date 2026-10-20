import type { CreateMapOptions, GoogleMapConfig, MapHandle, TileMapConfig } from './types';

/**
 * Crea el mapa con el motor que pida la configuración. Cada motor es su propio paquete: quien usa
 * teselas no descarga Google y al revés, y nadie descarga nada hasta que el mapa se pinta.
 */
export async function createMap(container: HTMLElement, options: CreateMapOptions): Promise<MapHandle> {
    if (options.config.driver === 'google') {
        const { createGoogleMap } = await import('./drivers/google');

        return createGoogleMap(container, options as CreateMapOptions<GoogleMapConfig>);
    }

    const { createLeafletMap } = await import('./drivers/leaflet');

    return createLeafletMap(container, options as CreateMapOptions<TileMapConfig>);
}

/**
 * ¿Lo movió la persona? Se mira si tocó el mapa justo antes de que empezara a moverse. Leaflet y
 * Google avisan igual al terminar un movimiento hecho por código, y sin esto «Buscar en esta
 * zona» saldría solo.
 */
export function watchUserInput(container: HTMLElement): { recent: () => boolean; stop: () => void } {
    let last = 0;
    const mark = () => {
        last = Date.now();
    };
    const events = ['pointerdown', 'mousedown', 'wheel', 'touchstart', 'keydown'] as const;

    events.forEach((name) => container.addEventListener(name, mark, { passive: true, capture: true }));

    return {
        recent: () => Date.now() - last < 1500,
        stop: () => events.forEach((name) => container.removeEventListener(name, mark, { capture: true })),
    };
}
