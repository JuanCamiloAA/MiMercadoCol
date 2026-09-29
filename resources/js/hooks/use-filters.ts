import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Filtros que consultan al servidor.
 *
 * Nada se filtra en el navegador: la tabla siempre pide la pagina que corresponde, de modo
 * que el listado se comporta igual con 60 articulos que con 6.000.
 */
export function useFilters<T extends Record<string, string>>(
    routeName: string,
    initial: T,
    routeParams?: Record<string, string | number>,
    delay = 350,
) {
    const [filters, setFilters] = useState<T>(initial);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null));

            router.get(route(routeName, routeParams), query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, delay);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters, routeName, delay]);

    const update = useCallback((key: keyof T, value: string) => {
        setFilters((current) => ({ ...current, [key]: value }));
    }, []);

    const reset = useCallback(() => {
        setFilters(Object.fromEntries(Object.keys(initial).map((key) => [key, ''])) as T);
    }, [initial]);

    const hasActive = Object.values(filters).some((value) => value !== '' && value !== null);

    return { filters, update, reset, hasActive };
}
