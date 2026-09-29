/**
 * Cabeceras para las peticiones que no pasan por Inertia.
 *
 * El `fetch` a pelo no lleva el token que Inertia pone solo, asi que hay que armarlo. Se
 * prefiere la cookie `XSRF-TOKEN` sobre el `<meta>`: el servidor la refresca en cada
 * respuesta, mientras que el meta se pinta una sola vez al cargar la pagina y se queda viejo
 * si la sesion se renueva mientras se navega dentro de la aplicacion.
 */
export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie) };
    }

    return { 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' };
}

/** Lo que se le dice a la persona cuando el servidor rechaza la peticion. */
export const SESSION_EXPIRED = 'Tu sesión caducó. Recarga la página y vuelve a intentarlo.';

/**
 * Traduce una respuesta fallida a algo que se pueda leer en pantalla.
 *
 * El 419 de Laravel llega como «CSRF token mismatch», en ingles y sin decir qué hacer. Pasa
 * cuando la sesión caduca o cuando el servidor se reinició y se perdieron las sesiones, y lo
 * único que hay que hacer es recargar.
 */
export async function errorMessage(response: Response, fallback: string): Promise<string> {
    if (response.status === 419) {
        return SESSION_EXPIRED;
    }

    if (response.status === 401 || response.status === 403) {
        return 'No tienes permiso para hacer eso.';
    }

    try {
        const body = await response.json();

        if (typeof body?.message === 'string' && body.message !== '') {
            return body.message;
        }
    } catch {
        // Una respuesta que no es JSON —una pantalla de error del servidor— no dice nada util.
    }

    return fallback;
}
