import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';
import { configureEcho } from '@laravel/echo-react';
import { brandTitle } from '@/lib/brand';

/**
 * Conexion con el servidor de sockets.
 *
 * `authEndpoint` usa el guard `web`: la tienda en linea entra con el guard `customer` y no
 * escucha ninguno de estos canales.
 *
 * Si el servidor no esta arriba, Echo reintenta solo y la aplicacion sigue funcionando: el
 * tiempo real es una comodidad, no un requisito. El indicador de la barra superior avisa
 * cuando no hay conexion, para que nadie confunda «no ha pasado nada» con «esto no se entera».
 */
configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    // Cada canal privado se autoriza con el token CSRF del momento (la cookie XSRF-TOKEN), no con
    // el de la etiqueta <meta>: ese se leeria una vez, y al iniciar sesion Laravel cambia el token
    // sin recargar la pagina. Con el viejo, /broadcasting/auth responde 419 y la pantalla se queda
    // sin tiempo real hasta que alguien recargue.
    channelAuthorization: {
        endpoint: '/broadcasting/auth',
        transport: 'ajax',
        customHandler: ({ socketId, channelName }, callback) => {
            fetch('/broadcasting/auth', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
            })
                .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`No autorizado (${response.status})`))))
                .then((data) => callback(null, data))
                .catch((error: unknown) => callback(error instanceof Error ? error : new Error(String(error)), null));
        },
    },
});

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const appName = import.meta.env.VITE_APP_NAME || 'AgoraShop';

createInertiaApp({
    // En el panel y la caja manda el nombre de la marca (Ajustes > Marca); en la tienda, el de la aplicacion.
    title: (title) => brandTitle(title, appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        createRoot(el).render(
            <>
                <App {...props} />
                <Toaster
                    position="top-right"
                    richColors
                    closeButton
                    toastOptions={{
                        style: {
                            background: 'var(--surface)',
                            color: 'var(--text)',
                            border: '1px solid var(--line)',
                        },
                    }}
                />
            </>,
        );
    },
    progress: {
        // El color de la marca que el blade dejo en la cabecera.
        color: document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')?.content || '#0E6E4E',
        showSpinner: false,
    },
});
