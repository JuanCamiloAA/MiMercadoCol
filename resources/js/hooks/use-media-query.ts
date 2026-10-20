import { useEffect, useState } from 'react';

/**
 * Si la pantalla cumple una consulta de medios (`(max-width: 767px)`), al día con los cambios.
 * Lo usan los videos, que no tienen `<picture>` para escoger la versión de celular.
 */
export function useMediaQuery(query: string): boolean {
    const read = () => typeof window !== 'undefined' && window.matchMedia(query).matches;
    const [matches, setMatches] = useState<boolean>(read);

    useEffect(() => {
        const list = window.matchMedia(query);
        const onChange = () => setMatches(list.matches);
        onChange();
        list.addEventListener('change', onChange);

        return () => list.removeEventListener('change', onChange);
    }, [query]);

    return matches;
}

/** El mismo corte de celular que usa toda la tienda (`md:` de Tailwind). */
export const MOBILE_QUERY = '(max-width: 767px)';
