import { useCallback, useEffect, useRef } from 'react';
import { useDocumentTheme } from '@/hooks/use-document-theme';
import { cn } from '@/lib/cn';
import { MapCanvas } from './MapCanvas';
import { inBounds, type LatLng, type MapConfig, type MapHandle } from './types';

interface LocationPickerProps {
    config: MapConfig;
    latitude: string;
    longitude: string;
    onChange: (latitude: string, longitude: string) => void;
    /** Dónde arrancar sin coordenadas: otra sede de la empresa, o el centro de la plataforma. */
    fallback?: LatLng | null;
    /** El acercamiento con un punto puesto; por defecto, el de una cuadra. */
    zoom?: number;
    hint?: string;
    className?: string;
}

const parse = (latitude: string, longitude: string): LatLng | null => {
    const lat = Number.parseFloat(latitude);
    const lng = Number.parseFloat(longitude);

    return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? { lat, lng } : null;
};

/**
 * Ubicar una sede tocando el mapa o arrastrando el pin. Los campos de latitud y longitud siguen
 * ahí: el mapa los llena y los sigue.
 */
export function LocationPicker({ config, latitude, longitude, onChange, fallback, zoom = 16, hint = 'Toca el mapa o arrastra el pin hasta la puerta de la sede.', className }: LocationPickerProps) {
    const theme = useDocumentTheme();
    const handle = useRef<MapHandle | null>(null);
    const point = parse(latitude, longitude);
    const lat = point?.lat;
    const lng = point?.lng;
    const change = useRef(onChange);
    change.current = onChange;

    const move = useCallback((next: LatLng) => change.current(next.lat.toFixed(7), next.lng.toFixed(7)), []);

    // Lo que se escribe a mano o llega de «Usar mi ubicación» mueve el pin y lo trae a la vista.
    useEffect(() => {
        const map = handle.current;

        if (!map) {
            return;
        }

        const current = lat !== undefined && lng !== undefined ? { lat, lng } : null;
        map.setPin(current, move);

        if (current && !inBounds(current, map.view().bounds)) {
            map.flyTo(current, zoom);
        }
    }, [lat, lng, move, zoom]);

    const start = point ?? fallback ?? null;

    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <MapCanvas
                config={config}
                theme={theme}
                gestures="cooperative"
                label="Mapa para elegir un punto"
                center={start ?? undefined}
                zoom={point ? zoom : fallback ? 13 : undefined}
                className="h-[16rem] rounded-control border border-[var(--line)]"
                onReady={(map) => {
                    handle.current = map;
                    map?.setPin(point, move);
                }}
                onClick={move}
                errorContent={() => <p>El mapa no cargó. Puedes escribir la latitud y la longitud a mano.</p>}
            />
            <p className="text-[0.75rem] text-[var(--muted)]">{hint}</p>
        </div>
    );
}
