import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { Endpoints, SettingsKey } from './types';

/** Pone el id del bloque (o de la versión) en una ruta que trae `__ID__`. */
export function withId(url: string, id: number | string): string {
    return url.replace('__ID__', encodeURIComponent(String(id)));
}

/**
 * Por qué ruta se guarda cada ajuste: lo operativo va por Funciones, la estructura global por
 * Contenido y la tarjeta por Aspecto. Cada una pide el permiso de su pestaña.
 */
const GROUP: Record<SettingsKey, 'settings' | 'structure' | 'look'> = {
    delivery: 'settings',
    coverage: 'settings',
    slots: 'settings',
    payments: 'settings',
    catalog: 'settings',
    texts: 'settings',
    seo: 'settings',
    category_page: 'settings',
    product_page: 'settings',
    search: 'settings',
    topbar: 'structure',
    header: 'structure',
    nav: 'structure',
    mobile_nav: 'structure',
    cart_bar: 'structure',
    footer: 'structure',
    card: 'look',
};

export function settingUrl(endpoints: Endpoints, key: SettingsKey): string {
    return `${endpoints[GROUP[key]]}/${key}`;
}

/** Guarda un ajuste en el borrador. Si el servidor lo rechaza, dice por qué. */
export function saveSetting(endpoints: Endpoints, key: SettingsKey, value: unknown, onSaved: () => void): void {
    router.patch(settingUrl(endpoints, key), { value } as unknown as Record<string, string>, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: onSaved,
        onError: (errors) => toast.error(Object.values(errors)[0] ?? 'No se pudo guardar.'),
    });
}

/**
 * Guardado automático: cada cambio se manda con una espera, sin botón de guardar. El primer
 * valor (el que llegó del servidor) no se guarda.
 */
export function useAutosave<T>(value: T, save: (value: T) => void, delay = 600): void {
    const first = useRef(true);
    const latest = useRef(save);
    latest.current = save;

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const timer = setTimeout(() => latest.current(value), delay);

        return () => clearTimeout(timer);
    }, [value, delay]);
}
