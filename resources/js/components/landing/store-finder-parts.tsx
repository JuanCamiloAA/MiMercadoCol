import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import type { LandingStore, LandingStoreDelivery } from './LandingBlocks';

/**
 * Lo que comparten los tres diseños del buscador, el mapa y la ventana ampliada: la misma frase
 * de horario, la misma distancia y el mismo domicilio en todas partes.
 */

/** «1,2 km». */
export const distanceLabel = (km: number): string => `${km.toLocaleString('es-CO', { maximumFractionDigits: 1 })} km`;

/** La distancia, si la hay: solo llega después de «Usar mi ubicación». */
export const distanceOf = (store: LandingStore): number | null => (typeof store.distance_km === 'number' ? store.distance_km : null);

/** «Domicilio desde $ 4.000», «Domicilio gratis» o «Sin domicilio». El valor exacto lo da el checkout. */
export function deliveryLabel(delivery: LandingStoreDelivery | undefined): string | null {
    if (!delivery) {
        return null;
    }

    if (!delivery.enabled) {
        return 'Sin domicilio';
    }

    if (delivery.fee_from === 0) {
        return 'Domicilio gratis';
    }

    return delivery.fee_from !== null ? `Domicilio desde ${money(delivery.fee_from)}` : 'Con domicilio';
}

/**
 * «Abierta · cierra 9:00 p. m.» o «Cerrada · abre 7:00 a. m.». La palabra va siempre: el estado no
 * se dice solo con el color. La frase la arma el servidor (`hours_label`).
 */
export function StoreStatus({ store, className }: { store: LandingStore; className?: string }) {
    if (!store.opens_at || !store.closes_at) {
        return null;
    }

    return (
        <span className={className}>
            <span className={cn('font-semibold', store.is_open ? 'text-[var(--accent)]' : 'text-[var(--muted)]')}>{store.is_open ? 'Abierta' : 'Cerrada'}</span>
            {store.hours_label && <span className="text-[var(--muted)]"> · {store.hours_label}</span>}
        </span>
    );
}

/** El logo de la tienda o, sin logo, su inicial sobre el color de la sede. */
export function StoreLogo({ store, className, inverted = false }: { store: LandingStore; className?: string; inverted?: boolean }) {
    if (store.logo_url) {
        return <img src={store.logo_url} alt="" className={cn('shrink-0 rounded-control bg-white object-contain', className)} />;
    }

    return (
        <span
            aria-hidden="true"
            className={cn('grid shrink-0 place-items-center rounded-control font-bold', className)}
            style={inverted ? { background: store.accent_ink, color: store.accent } : { background: store.accent, color: store.accent_ink }}
        >
            {store.company.slice(0, 1).toUpperCase()}
        </span>
    );
}

/** Varios datos en una línea, separados por «·». Los vacíos no dejan separadores colgando. */
export function DotList({ items, className }: { items: ReactNode[]; className?: string }) {
    const shown = items.filter((item) => item !== null && item !== false && item !== '');

    if (shown.length === 0) {
        return null;
    }

    return (
        <span className={className}>
            {shown.map((item, index) => (
                <span key={index}>
                    {index > 0 && <span className="text-[var(--muted)]"> · </span>}
                    {item}
                </span>
            ))}
        </span>
    );
}
