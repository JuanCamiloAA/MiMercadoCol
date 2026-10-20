import { useEffect, useState } from 'react';
import type { Theme } from '@/hooks/use-theme';

const read = (): Theme => (typeof document !== 'undefined' && document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

/**
 * El tema que la página tiene aplicado ahora mismo, sin aplicarlo. `useTheme` es de la pantalla;
 * un componente que solo quiere seguirlo (el mapa) lee `data-theme`.
 */
export function useDocumentTheme(): Theme {
    const [theme, setTheme] = useState<Theme>(read);

    useEffect(() => {
        const observer = new MutationObserver(() => setTheme(read()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        setTheme(read());

        return () => observer.disconnect();
    }, []);

    return theme;
}
