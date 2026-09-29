import { useCallback, useEffect, useSyncExternalStore } from 'react';

export type Theme = 'light' | 'dark';
export type ThemePreference = Theme | 'system';

export const THEME_STORAGE_KEY = 'lacanasta.theme';

const VALUES: readonly ThemePreference[] = ['light', 'dark', 'system'];

interface ThemeState {
    /** Lo que la persona escogio, o `null` si nunca toco el interruptor. */
    preference: ThemePreference | null;
    systemDark: boolean;
}

/**
 * La preferencia vive en `localStorage`, no en la base de datos: la escoge quien mira la
 * pantalla, no la cuenta. Asi el cajero deja el POS oscuro en su equipo sin cambiarle el
 * tema a nadie mas, y la tienda en linea tambien la respeta aunque el visitante no entre.
 */
const listeners = new Set<() => void>();

let state: ThemeState = { preference: null, systemDark: false };
let started = false;

function darkMedia(): MediaQueryList | null {
    return typeof window === 'undefined' ? null : window.matchMedia('(prefers-color-scheme: dark)');
}

function readStored(): ThemePreference | null {
    try {
        const raw = window.localStorage.getItem(THEME_STORAGE_KEY);

        return VALUES.includes(raw as ThemePreference) ? (raw as ThemePreference) : null;
    } catch {
        // Ventana privada o almacenamiento bloqueado: se sigue sin preferencia guardada.
        return null;
    }
}

function publish(next: ThemeState): void {
    if (next.preference === state.preference && next.systemDark === state.systemDark) {
        return;
    }

    state = next;
    listeners.forEach((listener) => listener());
}

/** Arranca los vigilantes una sola vez: el sistema operativo y las otras pestanas abiertas. */
function start(): void {
    if (started || typeof window === 'undefined') {
        return;
    }

    started = true;
    state = { preference: readStored(), systemDark: darkMedia()?.matches ?? false };

    darkMedia()?.addEventListener('change', (event) => {
        publish({ ...state, systemDark: event.matches });
    });

    window.addEventListener('storage', (event) => {
        if (event.key === THEME_STORAGE_KEY) {
            publish({ ...state, preference: readStored() });
        }
    });
}

function subscribe(listener: () => void): () => void {
    start();
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function snapshot(): ThemeState {
    start();

    return state;
}

const SERVER_STATE: ThemeState = { preference: null, systemDark: false };

export function setThemePreference(preference: ThemePreference): void {
    try {
        window.localStorage.setItem(THEME_STORAGE_KEY, preference);
    } catch {
        // Sin almacenamiento el cambio dura lo que dure la pagina, que es mejor que nada.
    }

    publish({ ...state, preference });
}

export interface ThemeControls {
    /** La opcion marcada en el interruptor. Sin eleccion previa, la que trae la pantalla. */
    preference: ThemePreference;
    /** El tema que quedo aplicado de verdad. */
    resolved: Theme;
    setPreference: (preference: ThemePreference) => void;
}

/**
 * Aplica el tema y devuelve el control para cambiarlo.
 *
 * `fallback` es el tema propio de la pantalla mientras nadie haya escogido; la empresa lo fija
 * por superficie en Ajustes (de fabrica, la caja nace oscura y el panel claro). Cuando alguien
 * escoge, su eleccion manda en todas las pantallas.
 *
 * Con `locked` la empresa oculto el interruptor: se ignora lo guardado sin borrarlo, asi que si
 * el interruptor vuelve, la eleccion de cada persona reaparece.
 */
export function useTheme(fallback: ThemePreference, locked = false): ThemeControls {
    const { preference, systemDark } = useSyncExternalStore(subscribe, snapshot, () => SERVER_STATE);

    const effective: ThemePreference = locked ? fallback : (preference ?? fallback);
    const resolved: Theme = effective === 'system' ? (systemDark ? 'dark' : 'light') : effective;

    useEffect(() => {
        document.documentElement.setAttribute('data-theme', resolved);
    }, [resolved]);

    return {
        preference: effective,
        resolved,
        setPreference: useCallback((next: ThemePreference) => setThemePreference(next), []),
    };
}
