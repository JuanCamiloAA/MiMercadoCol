import { usePage } from '@inertiajs/react';
import { useLayoutEffect } from 'react';
import { setBrandName } from '@/lib/brand';
import type { PageProps } from '@/types';

function setLinkHref(id: string, href: string | null): void {
    const link = document.getElementById(id);

    if (link instanceof HTMLLinkElement && href && link.getAttribute('href') !== href) {
        link.setAttribute('href', href);
    }
}

/**
 * Mantiene la marca al dia sin recargar.
 *
 * El blade inyecta `#brand-theme`, el favicon y las tipografias solo en la primera carga; las
 * visitas de Inertia no lo vuelven a pintar. Este componente los reescribe cuando la prop
 * `brand` cambia: quien guarda Ajustes ve el cambio en el acto y los demas en su siguiente
 * navegacion. En la tienda en linea la prop llega null y aqui solo se vacia el CSS, para que la
 * marca del panel no se cuele en las pantallas que pinta `StoreTheme`.
 */
export function BrandStyle() {
    const brand = usePage<PageProps>().props.brand ?? null;

    // Antes de que `<Head>` pida el titulo, que se arma con este nombre.
    setBrandName(brand?.display_name ?? null);

    const css = brand?.css ?? '';

    useLayoutEffect(() => {
        const style = document.getElementById('brand-theme');

        if (style && style.textContent !== css) {
            style.textContent = css;
        }
    }, [css]);

    const favicon = brand?.favicon_url ?? null;
    const touchIcon = brand?.touch_icon_url ?? null;
    const fonts = brand?.fonts_href ?? null;
    const themeColor = brand?.theme_color ?? null;

    useLayoutEffect(() => {
        // Sin marca (la tienda en linea) no se toca nada de la cabecera.
        if (!favicon) {
            return;
        }

        setLinkHref('brand-favicon', favicon);
        setLinkHref('brand-fonts', fonts);

        let touch = document.getElementById('brand-touch-icon');

        if (touchIcon && !touch) {
            touch = document.createElement('link');
            touch.id = 'brand-touch-icon';
            touch.setAttribute('rel', 'apple-touch-icon');
            document.head.appendChild(touch);
        }

        if (touch) {
            if (touchIcon) {
                touch.setAttribute('href', touchIcon);
            } else {
                touch.remove();
            }
        }

        if (themeColor) {
            document.querySelector('meta[name="theme-color"]')?.setAttribute('content', themeColor);
        }
    }, [favicon, touchIcon, fonts, themeColor]);

    return null;
}
