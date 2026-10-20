import { Link, usePage } from '@inertiajs/react';
import { createContext, useContext, useEffect, useSyncExternalStore, type ReactNode } from 'react';
import type { CartBrief, ShellZone, StoreShell } from '@/types/storefront';

/**
 * Lo que comparten las piezas de la tienda: el cascarón publicado (o el borrador, en la vista
 * previa) y si se está viendo en el editor.
 */
export const StoreShellContext = createContext<StoreShell | null>(null);

export interface PreviewMode {
    /** En la vista previa nada navega ni toca el carrito. */
    interactive: boolean;
    activeZone?: ShellZone | null;
    onSelectZone?: (zone: ShellZone) => void;
}

export const StorePreviewContext = createContext<PreviewMode>({ interactive: true });

export function useStoreShell(): StoreShell | null {
    return useContext(StoreShellContext);
}

/**
 * La vista previa del editor envuelve las páginas reales de la tienda con el borrador: el
* cascarón, un carrito de muestra y el modo sin navegación. Así lo que se ve al editar es la
* misma página que ve el cliente, no una copia.
 */
export interface PreviewRoot {
    shell: StoreShell;
    preview: PreviewMode;
    cart?: CartBrief | null;
    /** El modo que el editor está revisando (su conmutador Claro | Oscuro). Manda sobre el de la tienda. */
    scheme?: 'light' | 'dark' | null;
}

export const PreviewRootContext = createContext<PreviewRoot | null>(null);

/** El bloque que se edita y cómo avisar que se tocó otro: solo en la vista previa. */
export const BlockSelectionContext = createContext<{ activeUuid: string | null; onSelect: (uuid: string) => void } | null>(null);

/** Si la página se está pintando dentro de la vista previa del editor. */
export function useIsPreview(): boolean {
    return useContext(PreviewRootContext) !== null;
}

/** El cascarón para las páginas: el del borrador en la vista previa, el publicado en la tienda. */
export function useCurrentShell(): StoreShell | null {
    const root = useContext(PreviewRootContext);
    const page = usePage<{ storefront?: StoreShell | null }>();

    return root?.shell ?? page.props.storefront ?? null;
}

export function usePreviewMode(): PreviewMode {
    return useContext(StorePreviewContext);
}

/** Un destino externo sale en otra pestaña; uno de la tienda navega con Inertia. */
function isExternal(href: string): boolean {
    return /^(https?:)?\/\//i.test(href) || href.startsWith('mailto:') || href.startsWith('tel:');
}

/**
 * Enlace de la tienda. En la vista previa es un `span`: tocar un banner en el editor selecciona
 * el bloque, no se va a otra página.
 */
export function StoreLink({
    href,
    className,
    children,
    ariaLabel,
    onClick,
    heroFill = false,
}: {
    href: string | null | undefined;
    className?: string;
    children: ReactNode;
    ariaLabel?: string;
    onClick?: () => void;
    /** El banner principal que llena el alto de su sección (`[data-hero-fill]` en `sections.css`). */
    heroFill?: boolean;
}) {
    const { interactive } = usePreviewMode();
    const fill = heroFill ? { 'data-hero-fill': '' } : {};

    if (!href || !interactive) {
        return (
            <span className={className} aria-label={ariaLabel} {...fill}>
                {children}
            </span>
        );
    }

    if (isExternal(href)) {
        return (
            <a href={href} className={className} aria-label={ariaLabel} target="_blank" rel="noopener noreferrer" onClick={onClick} {...fill}>
                {children}
            </a>
        );
    }

    return (
        <Link href={href} className={className} aria-label={ariaLabel} onClick={onClick} {...fill}>
            {children}
        </Link>
    );
}

/*
 * Las fotos del catálogo externo (Open Food Facts) llevan su atribución en el pie, pero solo
 * cuando alguna está en pantalla. Cada tarjeta con foto externa se anota al montarse y se borra
 * al desmontarse.
 */
let externalImages = 0;
const externalListeners = new Set<() => void>();

function notifyExternal() {
    externalListeners.forEach((listener) => listener());
}

export function useExternalImage(external: boolean): void {
    useEffect(() => {
        if (!external) {
            return;
        }

        externalImages++;
        notifyExternal();

        return () => {
            externalImages--;
            notifyExternal();
        };
    }, [external]);
}

export function useHasExternalImages(): boolean {
    return useSyncExternalStore(
        (listener) => {
            externalListeners.add(listener);

            return () => externalListeners.delete(listener);
        },
        () => externalImages > 0,
        () => false,
    );
}

/** Marca una zona del cascarón para poder tocarla en la vista previa y editarla. */
export function ZoneFrame({ zone, children, className }: { zone: ShellZone; children: ReactNode; className?: string }) {
    const { onSelectZone, activeZone } = usePreviewMode();

    if (!onSelectZone) {
        return <>{children}</>;
    }

    return (
        <div
            data-zone={zone}
            className={`relative cursor-pointer ${activeZone === zone ? 'outline outline-2 -outline-offset-2 outline-[var(--accent)]' : 'hover:outline hover:outline-1 hover:-outline-offset-1 hover:outline-[var(--accent)]'} ${className ?? ''}`}
            onClickCapture={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onSelectZone(zone);
            }}
        >
            {children}
        </div>
    );
}
