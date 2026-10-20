/** Lo que el pin necesita de una sede: su marca. */
export interface PinStore {
    company: string;
    accent: string;
    accent_ink: string;
    logo_url?: string | null;
}

/**
 * El pin de una sede: el mismo círculo con la inicial (o el logo) que lleva su tarjeta. Con
 * `label`, el número de su fila en la lista, para que pin y fila se reconozcan.
 */
export function storePin(store: PinStore, label?: string, title?: string): HTMLElement {
    const root = document.createElement('span');
    root.className = 'map-pin';
    root.style.setProperty('--pin-bg', store.accent);
    root.style.setProperty('--pin-ink', store.accent_ink);

    if (title) {
        root.setAttribute('aria-label', title);
    }

    const badge = document.createElement('span');
    badge.className = 'map-pin__badge';

    if (label) {
        badge.textContent = label;
    } else if (store.logo_url) {
        const image = document.createElement('img');
        image.src = store.logo_url;
        image.alt = '';
        image.loading = 'lazy';
        badge.append(image);
    } else {
        badge.textContent = store.company.slice(0, 1).toUpperCase();
    }

    const tip = document.createElement('span');
    tip.className = 'map-pin__tip';
    root.append(badge, tip);

    return root;
}

/** Varias sedes juntas: un círculo con el número. */
export function clusterBubble(count: number): HTMLElement {
    const element = document.createElement('span');
    element.className = 'map-cluster';
    element.textContent = count > 99 ? '99+' : String(count);

    return element;
}
