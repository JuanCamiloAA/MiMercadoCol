import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { ArrowsOutIcon, CaretDownIcon, CaretUpIcon, CrosshairIcon, MagnifyingGlassIcon, MapPinIcon, ShoppingCartIcon, XIcon, type Icon } from '@phosphor-icons/react';
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import type { StoreClusters } from '@/components/maps/cluster';
import { MapCanvas } from '@/components/maps/MapCanvas';
import { clusterBubble, storePin } from '@/components/maps/markers';
import type { Bounds, LatLng, MapConfig, MapGestures, MapHandle, MapMarker, MapTheme, MapView } from '@/components/maps/types';
import { cn } from '@/lib/cn';
import type { LandingStore } from './LandingBlocks';
import { distanceLabel, distanceOf, StoreLogo, StoreStatus } from './store-finder-parts';

/** Pedir que el mapa vaya a una sede («Ver en el mapa»). `seq` cambia en cada pedido. */
export interface MapFocus {
    id: number;
    seq: number;
}

export interface StoreMapProps {
    config: MapConfig;
    theme: MapTheme;
    /** Lo que muestra el mapa: con los filtros aplicados, sin el de zona. */
    stores: LandingStore[];
    user: LatLng | null;
    cluster: boolean;
    hoveredId: number | null;
    selectedId: number | null;
    onSelect: (id: number | null) => void;
    focus: MapFocus | null;
    searchArea: boolean;
    onSearchArea: (bounds: Bounds) => void;
    onLocate: () => void;
    locating: boolean;
    showHours: boolean;
    showDistance: boolean;
    onBuy: (store: LandingStore) => void;
    /** El texto del botón de compra (`cta_label` del bloque). */
    ctaLabel: string;
    /** El número de cada sede en la lista: el pin lo pinta en vez de la inicial. */
    labels?: Record<number, string>;
    onExpand?: () => void;
    gestures?: MapGestures;
    className?: string;
    /** «Ampliar» y «Mi ubicación» encima del mapa. Un mapa pequeño de adorno no los lleva. */
    controls?: boolean;
    /** La ficha de la sede elegida. Sin ella cuando la lista de al lado ya la muestra. */
    card?: boolean;
    cardClassName?: string;
}

interface Located {
    store: LandingStore;
    lat: number;
    lng: number;
}

const squared = (a: LatLng, b: LatLng) => (a.lat - b.lat) ** 2 + (a.lng - b.lng) ** 2;

/**
 * Las sedes en el mapa, con el pin de la marca de cada empresa. Lo mismo en la landing y en la
 * ventana ampliada: el estado (filtros, la elegida, la ubicación) vive en el buscador.
 */
export function StoreMap({
    config,
    theme,
    stores,
    user,
    cluster,
    hoveredId,
    selectedId,
    onSelect,
    focus,
    searchArea,
    onSearchArea,
    onLocate,
    locating,
    showHours,
    showDistance,
    onBuy,
    ctaLabel,
    labels,
    onExpand,
    gestures = 'cooperative',
    className,
    controls = true,
    card = true,
    cardClassName,
}: StoreMapProps) {
    const [map, setMap] = useState<MapHandle | null>(null);
    const [view, setView] = useState<MapView | null>(null);
    const [moved, setMoved] = useState(false);
    const [clusters, setClusters] = useState<StoreClusters | null>(null);
    // Por sede y número: si un filtro cambia la numeración, el pin se rehace con su número y su título.
    const pins = useRef(new Map<string, { id: number; element: HTMLElement }>());
    const bubbles = useRef(new Map<string, HTMLElement>());

    const located = useMemo<Located[]>(
        () => stores.flatMap((store) => (store.latitude !== null && store.longitude !== null ? [{ store, lat: store.latitude, lng: store.longitude }] : [])),
        [stores],
    );
    const locatedKey = located.map((item) => item.store.id).join(',');

    // El agrupador se descarga solo si hace falta.
    useEffect(() => {
        if (!cluster || located.length < 2) {
            setClusters(null);

            return;
        }

        let cancelled = false;

        void import('@/components/maps/cluster').then(({ StoreClusters: Index }) => {
            if (cancelled) {
                return;
            }

            const index = new Index();
            index.load(located.map((item) => ({ id: item.store.id, lat: item.lat, lng: item.lng })));
            setClusters(index);
        });

        return () => {
            cancelled = true;
        };
        // `locatedKey` resume `located`: solo se rehace si cambian las sedes, no en cada render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cluster, locatedKey]);

    // Los marcadores: sedes sueltas o círculos con el número, según el acercamiento.
    useEffect(() => {
        if (!map) {
            return;
        }

        const byId = new Map(located.map((item) => [item.store.id, item]));
        const items = clusters
            ? clusters.at(view ?? map.view())
            : located.map((item) => ({ kind: 'point' as const, id: item.store.id, lat: item.lat, lng: item.lng }));

        const markers = items.flatMap((item): MapMarker[] => {
            if (item.kind === 'cluster') {
                const key = `${item.clusterId}:${item.count}`;
                let element = bubbles.current.get(key);

                if (!element) {
                    element = clusterBubble(item.count);
                    bubbles.current.set(key, element);
                }

                return [
                    {
                        key: `c${item.clusterId}`,
                        position: { lat: item.lat, lng: item.lng },
                        element,
                        title: `${item.count} tiendas aquí: acercar`,
                        zIndex: 500,
                        onClick: () => map.flyTo({ lat: item.lat, lng: item.lng }, clusters?.expansionZoom(item.clusterId)),
                    },
                ];
            }

            const entry = byId.get(item.id);

            if (!entry) {
                return [];
            }

            const label = labels?.[item.id];
            const key = `s${item.id}:${label ?? ''}`;
            const title = label ? `${label}. Sede ${entry.store.name}, ${entry.store.company}` : `${entry.store.company} — Sede ${entry.store.name}`;
            let pin = pins.current.get(key);

            if (!pin) {
                pin = { id: item.id, element: storePin(entry.store, label, title) };
                pins.current.set(key, pin);
            }

            return [
                {
                    // El número va en la llave: el motor solo pone el título al crear el marcador.
                    key,
                    position: { lat: entry.lat, lng: entry.lng },
                    element: pin.element,
                    title,
                    zIndex: item.id === selectedId ? 900 : 0,
                    onClick: () => onSelect(item.id),
                },
            ];
        });

        // Los de una numeración vieja ya no se usan.
        pins.current.forEach((pin, key) => {
            if (key !== `s${pin.id}:${labels?.[pin.id] ?? ''}`) {
                pins.current.delete(key);
            }
        });

        map.setMarkers(markers);
    }, [map, view, clusters, located, selectedId, onSelect, labels]);

    useEffect(() => {
        pins.current.forEach(({ id, element }) => {
            element.classList.toggle('is-hover', id === hoveredId);
            element.classList.toggle('is-active', id === selectedId);
        });
    }, [hoveredId, selectedId, map, view, clusters, labels]);

    useEffect(() => {
        map?.setUserLocation(user);
    }, [map, user]);

    // Encuadre: todas las sedes que pasan el filtro; con ubicación, la persona y las tres más cerca.
    const userKey = user ? `${user.lat},${user.lng}` : '';

    useEffect(() => {
        if (!map) {
            return;
        }

        const points = located.map((item) => ({ lat: item.lat, lng: item.lng }));

        if (user && points.length > 0) {
            map.fitTo([user, ...[...points].sort((a, b) => squared(user, a) - squared(user, b)).slice(0, 3)]);
        } else {
            map.fitTo(points);
        }

        setMoved(false);
        // Solo cuando cambian las sedes o la ubicación, no cuando la persona mueve el mapa.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [map, locatedKey, userKey]);

    useEffect(() => {
        if (!map || !focus) {
            return;
        }

        const target = located.find((item) => item.store.id === focus.id);

        if (target) {
            map.flyTo({ lat: target.lat, lng: target.lng }, 16);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [map, focus?.seq]);

    const selected = selectedId !== null ? stores.find((store) => store.id === selectedId) ?? null : null;

    return (
        <MapCanvas
            config={config}
            theme={theme}
            gestures={gestures}
            label="Mapa de las tiendas"
            className={className}
            onReady={setMap}
            onViewChange={(next, byUser) => {
                setView(next);

                if (byUser) {
                    setMoved(true);
                }
            }}
            onClick={() => onSelect(null)}
        >
            {controls && (
                <div className="map-overlay absolute left-3 top-3 flex flex-col items-start gap-2">
                    {onExpand && <MapButton icon={ArrowsOutIcon} label="Ampliar" onClick={onExpand} />}
                    <MapButton icon={CrosshairIcon} label={locating ? 'Buscando…' : 'Mi ubicación'} onClick={onLocate} disabled={locating} />
                </div>
            )}

            {searchArea && moved && view && (
                <button
                    type="button"
                    onClick={() => {
                        onSearchArea(view.bounds);
                        setMoved(false);
                    }}
                    // En pantallas angostas va debajo de «Ampliar» y «Mi ubicación», o los tapa.
                    className="map-overlay absolute left-1/2 top-[6.25rem] inline-flex h-9 -translate-x-1/2 items-center gap-1.5 whitespace-nowrap rounded-full bg-[var(--surface)] px-4 text-[0.8125rem] font-semibold text-[var(--text)] shadow-lg ring-1 ring-[var(--line)] sm:top-3"
                >
                    <MagnifyingGlassIcon size={15} /> Buscar en esta zona
                </button>
            )}

            {stores.length > 0 && located.length === 0 && (
                <p className="map-overlay absolute inset-x-3 bottom-8 rounded-control bg-[var(--surface)] px-3 py-2 text-center text-[0.8125rem] text-[var(--muted)] shadow-lg">
                    Estas tiendas todavía no tienen su ubicación en el mapa.
                </p>
            )}

            {/* Encima del crédito del proveedor, nunca tapándolo: la licencia pide que se vea. */}
            {selected && card && (
                <div className={cn('map-overlay absolute inset-x-3 bottom-8 rounded-card border border-[var(--line)] bg-[var(--surface)] p-3 shadow-xl', cardClassName)}>
                    <button
                        type="button"
                        onClick={() => onSelect(null)}
                        aria-label="Cerrar la ficha"
                        className="absolute right-2 top-2 grid size-8 place-items-center rounded-full text-[var(--muted)] hover:bg-[var(--surface-2)]"
                    >
                        <XIcon size={16} />
                    </button>
                    <StoreSummary store={selected} showHours={showHours} showDistance={showDistance} onBuy={onBuy} ctaLabel={ctaLabel} />
                </div>
            )}
        </MapCanvas>
    );
}

function MapButton({ icon: ButtonIcon, label, onClick, disabled }: { icon: Icon; label: string; onClick: () => void; disabled?: boolean }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className="inline-flex h-9 items-center gap-1.5 rounded-control bg-[var(--surface)] px-3 text-[0.8125rem] font-semibold text-[var(--text)] shadow-md ring-1 ring-[var(--line)] hover:bg-[var(--surface-2)] disabled:opacity-70"
        >
            <ButtonIcon size={16} /> {label}
        </button>
    );
}

/** La sede en corto: en la ficha del mapa y en la lista de la ventana ampliada. */
export function StoreSummary({
    store,
    showHours,
    showDistance,
    onBuy,
    ctaLabel,
    onLocate,
    compact = false,
}: {
    store: LandingStore;
    showHours: boolean;
    showDistance: boolean;
    onBuy: (store: LandingStore) => void;
    ctaLabel: string;
    onLocate?: () => void;
    compact?: boolean;
}) {
    const distance = showDistance ? distanceOf(store) : null;

    return (
        <div className={cn('flex gap-3', compact ? 'flex-col' : 'flex-col sm:flex-row sm:items-center')}>
            {/* `pr-8` deja libre la X de la ficha del mapa. */}
            <div className={cn('flex min-w-0 flex-1 items-start gap-3', !compact && 'pr-8')}>
                <StoreLogo store={store} className="size-10 text-[1rem]" />
                <div className="min-w-0 flex-1">
                    <p className="flex items-baseline gap-2">
                        <span className="min-w-0 flex-1 truncate text-[0.9375rem] font-semibold">Sede {store.name}</span>
                        {distance !== null && <span className="shrink-0 text-[0.875rem] font-bold tabular">{distanceLabel(distance)}</span>}
                    </p>
                    <p className="truncate text-[0.8125rem] text-[var(--muted)]">
                        {[store.company, [store.address, store.city].filter(Boolean).join(', ') || 'Sin dirección publicada'].join(' · ')}
                    </p>
                    {showHours && <StoreStatus store={store} className="mt-1 block text-[0.75rem]" />}
                </div>
            </div>
            <div className={cn('flex shrink-0 gap-2', compact && 'w-full')}>
                {onLocate && (
                    <button
                        type="button"
                        onClick={onLocate}
                        className="inline-flex h-10 flex-1 items-center justify-center gap-1.5 rounded-control border border-[var(--line-2)] px-3 text-[0.8125rem] font-semibold hover:bg-[var(--surface-2)]"
                    >
                        <MapPinIcon size={16} /> Ver en el mapa
                    </button>
                )}
                <a
                    href={store.url}
                    onClick={() => onBuy(store)}
                    className={cn('inline-flex h-10 items-center justify-center gap-2 rounded-control px-4 text-[0.875rem] font-semibold', compact && 'flex-1')}
                    style={{ background: store.accent, color: store.accent_ink }}
                >
                    <ShoppingCartIcon size={16} /> {ctaLabel}
                </a>
            </div>
        </div>
    );
}

interface StoreMapDialogProps {
    open: boolean;
    onClose: () => void;
    title: string;
    /** Los mismos filtros del buscador (búsqueda, ciudad, tipo de comercio). */
    filters: ReactNode;
    /** Aviso de «En la zona del mapa», si aplica. */
    notice: ReactNode;
    /** La lista: con el filtro de zona incluido. */
    listStores: LandingStore[];
    emptyText: string;
    onHover: (id: number | null) => void;
    map: Omit<StoreMapProps, 'onExpand' | 'gestures' | 'className'>;
}

/**
 * El mapa en grande, con la lista al lado (en el celular, en una hoja que sube). Comparte el
 * estado con el buscador: lo que se filtre aquí sigue filtrado al cerrar.
 */
export function StoreMapDialog({ open, onClose, title, filters, notice, listStores, emptyText, onHover, map }: StoreMapDialogProps) {
    const [sheetOpen, setSheetOpen] = useState(false);
    // Lo que se pide desde la lista de la ventana; gana sobre lo pedido antes desde la landing.
    const [ownFocus, setOwnFocus] = useState<MapFocus | null>(null);
    const focus = ownFocus && (!map.focus || ownFocus.seq > map.focus.seq) ? ownFocus : map.focus;
    const rows = useRef(new Map<number, HTMLLIElement>());

    useEffect(() => {
        if (map.selectedId !== null) {
            rows.current.get(map.selectedId)?.scrollIntoView({ block: 'nearest' });
        }
    }, [map.selectedId]);

    const choose = (store: LandingStore) => {
        map.onSelect(store.id);
        setOwnFocus({ id: store.id, seq: Date.now() });
        setSheetOpen(false);
    };

    const list =
        listStores.length === 0 ? (
            <p className="px-2 py-8 text-center text-[0.875rem] text-[var(--muted)]">{emptyText}</p>
        ) : (
            <ul className="flex flex-col gap-2">
                {listStores.map((store) => (
                    <li
                        key={store.id}
                        ref={(element) => {
                            if (element) {
                                rows.current.set(store.id, element);
                            } else {
                                rows.current.delete(store.id);
                            }
                        }}
                        onMouseEnter={() => onHover(store.id)}
                        onMouseLeave={() => onHover(null)}
                        className={cn(
                            'rounded-card border bg-[var(--surface)] p-3 transition-colors',
                            map.selectedId === store.id ? 'border-[var(--accent)] ring-1 ring-[var(--accent)]' : 'border-[var(--line)]',
                        )}
                    >
                        <StoreSummary
                            store={store}
                            showHours={map.showHours}
                            showDistance={map.showDistance}
                            onBuy={map.onBuy}
                            ctaLabel={map.ctaLabel}
                            onLocate={() => choose(store)}
                            compact
                        />
                    </li>
                ))}
            </ul>
        );

    return (
        <Dialog open={open} onClose={onClose} className="relative z-50">
            <div className="fixed inset-0 bg-black/50" aria-hidden="true" />
            <div className="fixed inset-0 md:p-6">
                <DialogPanel className="flex h-full w-full flex-col overflow-hidden bg-[var(--bg)] text-[var(--text)] shadow-2xl md:rounded-sheet md:border md:border-[var(--line)]">
                    <header className="flex items-center gap-3 border-b border-[var(--line)] bg-[var(--surface)] px-4 py-3">
                        <DialogTitle className="min-w-0 flex-1 truncate text-[1.0625rem] font-semibold">{title}</DialogTitle>
                        <span className="shrink-0 font-mono text-[0.8125rem] text-[var(--muted)] tabular">
                            {listStores.length} {listStores.length === 1 ? 'tienda' : 'tiendas'}
                        </span>
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Cerrar el mapa"
                            className="grid size-9 shrink-0 place-items-center rounded-full text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                        >
                            <XIcon size={20} />
                        </button>
                    </header>

                    <div className="relative flex min-h-0 flex-1">
                        <aside className="hidden w-[24rem] shrink-0 flex-col border-r border-[var(--line)] bg-[var(--surface-2)] md:flex">
                            <div className="flex flex-col gap-3 border-b border-[var(--line)] p-3">
                                {filters}
                                {notice}
                            </div>
                            <div className="min-h-0 flex-1 overflow-y-auto p-3">{list}</div>
                        </aside>

                        {/* En el celular el mapa termina donde empieza la hoja: así el crédito sigue a la vista. */}
                        <div className="relative mb-[3.25rem] min-w-0 flex-1 md:mb-0">
                            <StoreMap {...map} focus={focus} gestures="greedy" className="absolute inset-0" />
                        </div>

                        <div className={cn('absolute inset-x-0 bottom-0 z-10 flex flex-col rounded-t-sheet border-t border-[var(--line)] bg-[var(--surface)] shadow-[0_-0.5rem_1.5rem_rgb(0_0_0/0.18)] md:hidden', sheetOpen && 'h-[70%]')}>
                            <button type="button" onClick={() => setSheetOpen((value) => !value)} aria-expanded={sheetOpen} className="flex h-[3.25rem] shrink-0 flex-col items-center justify-center gap-1.5 px-4">
                                <span className="h-1 w-10 rounded-full bg-[var(--line-2)]" aria-hidden="true" />
                                <span className="inline-flex items-center gap-1.5 text-[0.875rem] font-semibold">
                                    {sheetOpen ? <CaretDownIcon size={16} /> : <CaretUpIcon size={16} />}
                                    {sheetOpen ? 'Ver el mapa' : `Ver la lista (${listStores.length})`}
                                </span>
                            </button>
                            {sheetOpen && (
                                <div className="flex min-h-0 flex-1 flex-col">
                                    <div className="flex flex-col gap-3 border-y border-[var(--line)] p-3">
                                        {filters}
                                        {notice}
                                    </div>
                                    <div className="min-h-0 flex-1 overflow-y-auto p-3">{list}</div>
                                </div>
                            )}
                        </div>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    );
}
