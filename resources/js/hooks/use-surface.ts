import { useLayoutEffect } from 'react';

export type Surface = 'panel' | 'pos' | 'store';

/**
 * Fija `data-surface` en `<html>`, igual que `useTheme` fija `data-theme`.
 *
 * La escala de la pantalla y la densidad cuelgan de ese atributo. El blade lo pone en la
 * primera carga, pero las visitas de Inertia no vuelven a pintar el blade: sin esto, pasar del
 * panel a la caja dejaria la caja con la escala del panel. Va en `useLayoutEffect` para que el
 * cambio ocurra antes de pintar y no se vea el salto.
 */
export function useSurface(surface: Surface): void {
    useLayoutEffect(() => {
        document.documentElement.setAttribute('data-surface', surface);
    }, [surface]);
}
