import { ArrowSquareOutIcon, DesktopIcon, DeviceMobileIcon } from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { SchemeSwitch, useEditorScheme } from '@/components/sections/editor-scheme';
import { Select } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import type { Option, StructureKey } from './types';

export interface PreviewView {
    page: string;
    category: string;
    product: string;
}

interface PreviewFrameProps {
    /** Enlace a la vista previa (firmado en una sede). */
    url: string;
    /** Cambia con cada guardado: es la señal para recargar el marco. */
    version: string;
    view: PreviewView;
    onView: (view: PreviewView) => void;
    pages: Option[];
    categories: { value: number; label: string }[];
    products: { value: number; label: string }[];
    activeUuid: string | null;
    activeZone: StructureKey | null;
    onSelectBlock: (uuid: string) => void;
    onSelectZone: (zone: StructureKey) => void;
    /** En la plantilla: con el catálogo de qué sede se ve. */
    stores?: { value: number; label: string }[];
    store?: number | null;
    onStore?: (store: number) => void;
}

/** Una firma corta del borrador, para recargar el marco sin mandar todo en la dirección. */
function hash(text: string): string {
    let value = 5381;

    for (let index = 0; index < text.length; index++) {
        value = ((value << 5) + value + text.charCodeAt(index)) | 0;
    }

    return (value >>> 0).toString(36);
}

/**
 * La tienda tal como va quedando.
 *
 * Va en un `iframe` y no en un `div` porque las reglas de tamaño miden el ancho de la ventana:
 * sin marco propio, el conmutador celular/computador no cambiaría nada. El marco y el editor se
 * hablan por `postMessage`: tocar un bloque o una zona (barra superior, encabezado, menú, pie…)
 * abre sus propiedades al lado. Se puede ver cualquier página: portada, una categoría, un
 * artículo, el carrito o la búsqueda.
 */
export function PreviewFrame({
    url,
    version,
    view,
    onView,
    pages,
    categories,
    products,
    activeUuid,
    activeZone,
    onSelectBlock,
    onSelectZone,
    stores,
    store,
    onStore,
}: PreviewFrameProps) {
    const [device, setDevice] = useState<'mobile' | 'desktop'>('desktop');
    // El modo que se revisa (Claro | Oscuro): el mismo del conmutador de «Diseño».
    const { scheme } = useEditorScheme();
    const [scale, setScale] = useState(1);
    const box = useRef<HTMLDivElement>(null);
    const frame = useRef<HTMLIFrameElement>(null);

    const width = device === 'mobile' ? 390 : 1280;
    const height = device === 'mobile' ? 780 : 900;

    const src = useMemo(() => {
        if (!url) {
            return '';
        }

        const target = new URL(url, window.location.origin);
        target.searchParams.set('page', view.page);
        target.searchParams.set('device', device);
        target.searchParams.set('scheme', scheme);
        target.searchParams.set('v', hash(version));

        if (view.page === 'category' && view.category) {
            target.searchParams.set('category', view.category);
        }

        if (view.page === 'product' && view.product) {
            target.searchParams.set('product', view.product);
        }

        if (store) {
            target.searchParams.set('store', String(store));
        }

        return target.toString();
    }, [url, view, device, scheme, version, store]);

    useEffect(() => {
        const fit = () => {
            const available = box.current?.clientWidth ?? width;
            setScale(Math.min(1, (available - 24) / width));
        };

        fit();
        window.addEventListener('resize', fit);

        return () => window.removeEventListener('resize', fit);
    }, [width]);

    // Lo que el editor dice: esto es lo que se está editando.
    const notify = useCallback(() => {
        frame.current?.contentWindow?.postMessage({ source: 'storefront-editor', uuid: activeUuid, zone: activeZone }, window.location.origin);
    }, [activeUuid, activeZone]);

    // Lo que el marco dice: se tocó un bloque o una zona.
    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (event.origin !== window.location.origin) {
                return;
            }

            const data = event.data as { source?: string; uuid?: string; zone?: StructureKey; ready?: boolean };

            if (data?.source !== 'storefront-preview') {
                return;
            }

            if (data.ready) {
                notify();

                return;
            }

            if (data.uuid) {
                onSelectBlock(data.uuid);
            } else if (data.zone) {
                onSelectZone(data.zone);
            }
        };

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, [notify, onSelectBlock, onSelectZone]);

    useEffect(() => {
        notify();
    }, [notify]);

    return (
        <div className="flex h-full min-h-0 flex-col rounded-[14px] border border-[var(--line)] bg-[var(--surface)]">
            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-[var(--line)] px-3 py-2">
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                    <p className="text-[12px] font-semibold uppercase tracking-wide text-[var(--muted)]">Ver</p>
                    <Select aria-label="Página que se ve" options={pages} value={view.page} onChange={(event) => onView({ ...view, page: event.target.value })} className="h-9 min-w-[130px] text-[12.5px]" />
                    {view.page === 'category' && categories.length > 0 && (
                        <Select
                            aria-label="Categoría que se ve"
                            options={categories.map((option) => ({ value: String(option.value), label: option.label }))}
                            placeholder="La primera"
                            value={view.category}
                            onChange={(event) => onView({ ...view, category: event.target.value })}
                            className="h-9 min-w-[150px] text-[12.5px]"
                        />
                    )}
                    {view.page === 'product' && products.length > 0 && (
                        <Select
                            aria-label="Artículo que se ve"
                            options={products.map((option) => ({ value: String(option.value), label: option.label }))}
                            placeholder="Un destacado"
                            value={view.product}
                            onChange={(event) => onView({ ...view, product: event.target.value })}
                            className="h-9 min-w-[170px] text-[12.5px]"
                        />
                    )}
                    {stores && stores.length > 0 && onStore && (
                        <Select
                            aria-label="Con el catálogo de"
                            options={stores.map((option) => ({ value: String(option.value), label: `Ver con: ${option.label}` }))}
                            value={String(store ?? '')}
                            onChange={(event) => onStore(Number(event.target.value))}
                            className="h-9 min-w-[200px] text-[12.5px]"
                        />
                    )}
                </div>

                <div className="flex items-center gap-1.5">
                    <SchemeSwitch compact />
                    <div className="flex items-center gap-0.5 rounded-[8px] bg-[var(--surface-2)] p-0.5">
                        <button
                            type="button"
                            onClick={() => setDevice('mobile')}
                            aria-label="Celular"
                            aria-pressed={device === 'mobile'}
                            title="Celular 390 px"
                            className={cn('grid size-8 place-items-center rounded-[6px] transition-colors', device === 'mobile' ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm' : 'text-[var(--muted)]')}
                        >
                            <DeviceMobileIcon size={15} />
                        </button>
                        <button
                            type="button"
                            onClick={() => setDevice('desktop')}
                            aria-label="Computador"
                            aria-pressed={device === 'desktop'}
                            title="Computador 1280 px"
                            className={cn('grid size-8 place-items-center rounded-[6px] transition-colors', device === 'desktop' ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm' : 'text-[var(--muted)]')}
                        >
                            <DesktopIcon size={15} />
                        </button>
                    </div>

                    {src && (
                        <a
                            href={src}
                            target="_blank"
                            rel="noreferrer"
                            title="Abrir en otra pestaña"
                            className="flex h-8 items-center gap-1.5 rounded-[8px] border border-[var(--line)] px-2.5 text-[12px] font-medium text-[var(--muted)] hover:text-[var(--text)]"
                        >
                            <ArrowSquareOutIcon size={14} />
                            Abrir
                        </a>
                    )}
                </div>
            </div>

            <div ref={box} className="min-h-0 flex-1 overflow-auto bg-[var(--surface-2)] p-3">
                {src ? (
                    <div style={{ height: height * scale, width: width * scale }} className="mx-auto">
                        <iframe
                            ref={frame}
                            key={src}
                            title="Vista previa de la tienda"
                            src={src}
                            onLoad={notify}
                            style={{
                                width,
                                height,
                                transform: `scale(${scale})`,
                                transformOrigin: 'top left',
                                border: '1px solid var(--line)',
                                borderRadius: 14,
                                background: 'white',
                            }}
                        />
                    </div>
                ) : (
                    <p className="py-10 text-center text-[13px] text-[var(--muted)]">No hay ninguna sede para ver la vista previa.</p>
                )}
            </div>
        </div>
    );
}
