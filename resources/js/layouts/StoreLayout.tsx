import { Head, usePage } from '@inertiajs/react';
import { WhatsappLogoIcon } from '@phosphor-icons/react';
import { useContext, useEffect, useState, type ReactNode } from 'react';
import { BrandStyle } from '@/components/brand/BrandStyle';
import { CartBar } from '@/components/storefront/CartBar';
import { CartDrawer } from '@/components/storefront/CartDrawer';
import { seedCart, useCart } from '@/components/storefront/cart-store';
import { CategoryNav, CategorySheet } from '@/components/storefront/CategoryNav';
import { LoginDialog } from '@/components/storefront/LoginDialog';
import { MobileNav } from '@/components/storefront/MobileNav';
import { StoreFooter } from '@/components/storefront/StoreFooter';
import { LocationPill, StoreHeader } from '@/components/storefront/StoreHeader';
import { PreviewRootContext, StorePreviewContext, StoreShellContext, ZoneFrame, type PreviewMode } from '@/components/storefront/store-context';
import { StoreTheme } from '@/components/storefront/StoreTheme';
import { TopBar } from '@/components/storefront/TopBar';
import { useFlash } from '@/hooks/use-flash';
import { useSurface } from '@/hooks/use-surface';
import { useTheme } from '@/hooks/use-theme';
import { cn } from '@/lib/cn';
import type { PageProps } from '@/types';
import type { CartBrief, StoreShell } from '@/types/storefront';

interface StoreLayoutProps {
    title: string;
    children: ReactNode;
    /** Solo para marcar la pestaña activa; la dirección de verdad sale de la URL. */
    current?: 'home' | 'cart' | 'orders' | 'account' | 'catalog';
    /** @deprecated El carrito llega en la prop compartida `cart`. */
    cartCount?: number;
    /** Lo que va pegado debajo del encabezado en una página (poco usado). */
    header?: ReactNode;
    /** El cascarón a mano: la vista previa del editor pinta el borrador, no lo publicado. */
    shell?: StoreShell | null;
    preview?: PreviewMode;
    /** Lo que va en el buscador del encabezado (la búsqueda actual). */
    searchInitial?: string;
    /** El carrito y el pago no llevan la barra flotante del carrito. */
    hideCartBar?: boolean;
    /** Solo la portada: el encabezado va transparente sobre el banner de borde a borde. */
    headerOverlay?: boolean;
}

/** Desde cuántos píxeles de desplazamiento el encabezado se considera «abajo». */
const SCROLLED_AT = 24;

/**
 * Cascarón de la tienda en línea.
 *
 * Barra superior, encabezado de compra, menú de categorías, pie con su franja legal, barra del
 * carrito y navegación del celular: todo sale de la estructura publicada de la sede (o del
 * borrador, en la vista previa del editor). La misma tienda sirve en celular y en computador;
 * nada va encerrado en una columna de celular.
 */
export default function StoreLayout({ title, children, header, shell: given, preview, searchInitial = '', hideCartBar = false, headerOverlay = false }: StoreLayoutProps) {
    const page = usePage<PageProps>();
    const root = useContext(PreviewRootContext);
    const shell = given ?? root?.shell ?? (page.props.storefront as StoreShell | null | undefined) ?? null;
    const cartProp = root ? root.cart : (page.props.cart as CartBrief | null | undefined);
    const scheme = shell?.theme.scheme ?? 'light';

    // El esquema lo decide el editor; el cliente solo lo cambia si la sede lo permite. En la vista
    // previa manda el modo que el editor está revisando (Claro | Oscuro), sin tocar lo guardado.
    const forced = root?.scheme ?? null;
    useTheme(forced ?? (scheme === 'auto' ? 'system' : scheme), forced !== null || !(shell?.theme.allow_scheme_toggle ?? false));
    // La tienda no lleva la escala ni la marca del panel: aquí manda `StoreTheme`.
    useSurface('store');
    useFlash();

    const cart = useCart();
    const [drawer, setDrawer] = useState(false);
    const [categories, setCategories] = useState(false);
    const path = page.url.split('?')[0];

    useEffect(() => seedCart(cartProp), [cartProp]);

    // El icono de la pestaña: el de la sede, si lo subió.
    const favicon = shell?.theme.favicon?.variants?.square ?? shell?.theme.favicon?.url;

    useEffect(() => {
        if (favicon) {
            document.getElementById('brand-favicon')?.setAttribute('href', favicon);
        }
    }, [favicon]);

    const mode = preview ?? root?.preview ?? { interactive: true };
    const withMobileNav = (shell?.settings.mobile_nav.items.length ?? 0) > 0;
    const onCartPage = path.startsWith('/tienda/carrito') || path.startsWith('/tienda/pago');
    const sticky = shell?.settings.header?.sticky ?? false;
    const stickyStyle = shell?.settings.header?.sticky_style ?? 'solid';
    // Hace falta saber si ya se bajó solo cuando el encabezado cambia al bajar.
    const watchScroll = headerOverlay || (sticky && stickyStyle !== 'solid');
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        if (!watchScroll) {
            setScrolled(false);

            return;
        }

        const update = () => setScrolled(window.scrollY > SCROLLED_AT);

        update();
        window.addEventListener('scroll', update, { passive: true });

        return () => window.removeEventListener('scroll', update);
    }, [watchScroll]);

    if (!shell || !shell.settings?.header) {
        // Una sede sin publicar (o apagada): el cascarón mínimo, para que se pueda salir.
        return (
            <StoreTheme theme={page.props.storeTheme} className="flex min-h-screen flex-col bg-[var(--bg)]">
                <Head title={title} />
                <BrandStyle />
                <main className="mx-auto w-full max-w-[var(--store-max,1280px)] flex-1 px-4 py-6 lg:px-[var(--store-gutter,1.5rem)]">{children}</main>
            </StoreTheme>
        );
    }

    return (
        <StorePreviewContext.Provider value={mode}>
            <StoreShellContext.Provider value={shell}>
                <StoreTheme theme={shell.theme} className="flex min-h-screen flex-col bg-[var(--bg)] text-[var(--text)]">
                    <Head title={title} />
                    <BrandStyle />

                    <a
                        href="#contenido"
                        className="sr-only z-[80] rounded-[10px] bg-[var(--accent)] px-4 py-2 font-bold text-[var(--accent-ink)] focus:not-sr-only focus:fixed focus:left-3 focus:top-3"
                    >
                        Saltar al contenido
                    </a>

                    <ZoneFrame zone="topbar">
                        <TopBar topbar={shell.settings.topbar} />
                    </ZoneFrame>

                    {(() => {
                        const bar = (
                            <>
                                <ZoneFrame zone="header">
                                    <StoreHeader
                                        shell={shell}
                                        onOpenDrawer={() => setDrawer(true)}
                                        onOpenCategories={() => setCategories(true)}
                                        searchInitial={searchInitial}
                                        overlay={headerOverlay && !scrolled}
                                        scrolled={scrolled}
                                        currentPath={path}
                                    />
                                </ZoneFrame>
                                <div className="hidden md:block">
                                    <ZoneFrame zone="nav">
                                        <CategoryNav shell={shell} onOpenSheet={() => setCategories(true)} currentPath={path} desktop />
                                    </ZoneFrame>
                                </div>
                            </>
                        );

                        // Sobre la portada el encabezado no ocupa lugar: el banner sube hasta la barra de arriba.
                        // Al bajar se queda fijo (si la sede lo pidió) con el estilo de «Al bajar».
                        return headerOverlay ? (
                            <div className="relative z-30 h-0">
                                <div className={cn('inset-x-0 top-0 z-30', sticky && scrolled ? 'fixed' : 'absolute')}>{bar}</div>
                            </div>
                        ) : (
                            <div className={cn(sticky && 'sticky top-0 z-30')}>{bar}</div>
                        );
                    })()}

                    {/* En celular, «Recibir en» y el menú van debajo y se van al desplazar. Sobre la portada no:
                        quedarían detrás del encabezado transparente. */}
                    <div className={cn('md:hidden', headerOverlay && 'hidden')}>
                        {shell.settings.header.show_location && (
                            <div className="border-b border-[var(--line)] bg-[var(--surface)] px-4">
                                <LocationPill shell={shell} onColor={false} compact />
                            </div>
                        )}
                        <ZoneFrame zone="nav">
                            <CategoryNav shell={shell} onOpenSheet={() => setCategories(true)} currentPath={path} />
                        </ZoneFrame>
                    </div>

                    {header && <div className="mx-auto w-full max-w-[var(--store-max,1280px)] px-4 lg:px-[var(--store-gutter,1.5rem)]">{header}</div>}

                    <main
                        id="contenido"
                        // `data-header-overlay`: el banner de borde a borde arranca arriba del todo (`storefront.css`).
                        data-header-overlay={headerOverlay ? '' : undefined}
                        className={cn('mx-auto w-full max-w-[var(--store-max,1280px)] flex-1 px-4 pt-4 md:pt-6 lg:px-[var(--store-gutter,1.5rem)]', withMobileNav ? 'pb-28 md:pb-24' : 'pb-24')}
                    >
                        {children}
                    </main>

                    {/* El botón de WhatsApp de la sede, si lo pidió y tiene número. Sube por encima de la
                        navegación del celular y de la barra del carrito. */}
                    {shell.settings.floating?.whatsapp_href && (
                        <a
                            href={mode.interactive ? shell.settings.floating.whatsapp_href : undefined}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Escribir por WhatsApp"
                            className={cn(
                                'fixed z-40 grid size-14 place-items-center rounded-full bg-[#25D366] text-white shadow-xl transition-transform hover:scale-105',
                                shell.settings.floating.position === 'left' ? 'left-4' : 'right-4',
                                withMobileNav ? 'bottom-[88px] md:bottom-24' : 'bottom-24',
                            )}
                        >
                            <WhatsappLogoIcon size={30} weight="fill" />
                        </a>
                    )}

                    <ZoneFrame zone="footer" className={withMobileNav ? 'pb-[66px] md:pb-0' : ''}>
                        <StoreFooter shell={shell} />
                    </ZoneFrame>

                    {!hideCartBar && !onCartPage && (
                        <ZoneFrame zone="cart_bar">
                            <CartBar shell={shell} onOpenDrawer={() => setDrawer(true)} withMobileNav={withMobileNav} />
                        </ZoneFrame>
                    )}

                    <ZoneFrame zone="mobile_nav">
                        <MobileNav shell={shell} currentPath={path} onOpenCategories={() => setCategories(true)} />
                    </ZoneFrame>

                    {/* Lo que cambia en el carrito se le dice también a quien usa lector de pantalla. */}
                    <p className="sr-only" aria-live="polite" role="status">
                        {cart.announcement}
                    </p>

                    {mode.interactive && (
                        <>
                            <CartDrawer open={drawer} onClose={() => setDrawer(false)} shell={shell} />
                            <CategorySheet open={categories} onClose={() => setCategories(false)} shell={shell} />
                            <LoginDialog />
                        </>
                    )}
                </StoreTheme>
            </StoreShellContext.Provider>
        </StorePreviewContext.Provider>
    );
}
