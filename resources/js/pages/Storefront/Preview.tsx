import { useCallback, useEffect, useMemo, useState } from 'react';
import { BlockSelectionContext, PreviewRootContext } from '@/components/storefront/store-context';
import StorefrontCart from '@/pages/Storefront/Cart';
import StorefrontCatalog from '@/pages/Storefront/Catalog';
import StorefrontHome from '@/pages/Storefront/Home';
import StorefrontProduct from '@/pages/Storefront/Product';
import type { CartBrief, ShellZone, StoreBlock, StoreShell } from '@/types/storefront';

type CatalogProps = Parameters<typeof StorefrontCatalog>[0];
type ProductProps = Parameters<typeof StorefrontProduct>[0];
type CartProps = Parameters<typeof StorefrontCart>[0];

interface PreviewProps {
    view: 'home' | 'category' | 'product' | 'cart' | 'search';
    shell: StoreShell;
    blocks: StoreBlock[];
    cart: CartBrief | null;
    catalog?: Omit<CatalogProps, 'blocks'> | null;
    product?: Omit<ProductProps, 'blocks'> | null;
    cartPage?: Omit<CartProps, 'blocks'> | null;
}

/**
 * La tienda en borrador, dentro del `iframe` del editor.
 *
 * Pinta las páginas reales de la tienda —las mismas que ve el cliente— con el cascarón y los
 * bloques del borrador, sin navegar ni tocar el carrito. Va en un `iframe` para que el ancho
 * sea de verdad el de un celular o un computador. Le habla al editor por `postMessage`: tocar
 * un bloque o una zona (barra superior, encabezado, menú, pie...) abre su sección allá.
 */
export default function StorefrontPreview({ view, shell, blocks, cart, catalog, product, cartPage }: PreviewProps) {
    const [activeUuid, setActiveUuid] = useState<string | null>(null);
    const [activeZone, setActiveZone] = useState<ShellZone | null>(null);

    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (event.origin !== window.location.origin) {
                return;
            }

            const data = event.data as { source?: string; uuid?: string | null; zone?: ShellZone | null };

            if (data?.source === 'storefront-editor') {
                setActiveUuid(data.uuid ?? null);
                setActiveZone(data.zone ?? null);
            }
        };

        window.addEventListener('message', onMessage);
        window.parent?.postMessage({ source: 'storefront-preview', ready: true }, window.location.origin);

        return () => window.removeEventListener('message', onMessage);
    }, []);

    const selectBlock = useCallback((uuid: string) => {
        setActiveUuid(uuid);
        setActiveZone(null);
        window.parent?.postMessage({ source: 'storefront-preview', uuid }, window.location.origin);
    }, []);

    const selectZone = useCallback((zone: ShellZone) => {
        setActiveZone(zone);
        setActiveUuid(null);
        window.parent?.postMessage({ source: 'storefront-preview', zone }, window.location.origin);
    }, []);

    const root = useMemo(
        () => ({ shell, cart, preview: { interactive: false, activeZone, onSelectZone: selectZone } }),
        [shell, cart, activeZone, selectZone],
    );

    const selection = useMemo(() => ({ activeUuid, onSelect: selectBlock }), [activeUuid, selectBlock]);

    return (
        <PreviewRootContext.Provider value={root}>
            <BlockSelectionContext.Provider value={selection}>
                {view === 'home' && <StorefrontHome blocks={blocks} fallback={null} />}
                {(view === 'category' || view === 'search') && catalog && <StorefrontCatalog {...catalog} blocks={blocks} />}
                {view === 'product' && product && <StorefrontProduct {...product} blocks={blocks} />}
                {view === 'cart' && cartPage && <StorefrontCart {...cartPage} blocks={blocks} />}
                {((view === 'category' && !catalog) || (view === 'product' && !product)) && (
                    <p className="p-10 text-center text-[14px] text-[var(--muted)]">Esta sede todavía no tiene {view === 'product' ? 'artículos publicados' : 'categorías activas'} para mostrar aquí.</p>
                )}
            </BlockSelectionContext.Provider>
        </PreviewRootContext.Provider>
    );
}
