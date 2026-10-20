import { MoonIcon, SunIcon } from '@phosphor-icons/react';
import { createContext, useContext } from 'react';
import { cn } from '@/lib/cn';

/**
 * El modo que se está revisando en un editor (tienda, plantilla o landing): Claro | Oscuro.
 *
 * Es uno solo por editor: lo cambia el conmutador de la vista previa o el de la pestaña «Diseño»,
 * y con él cambian la vista previa (`scheme` en su dirección), los ajustes del fondo que se
 * editan y el diseño de cada lámina. No toca el tema del panel ni lo que escogió quien mira.
 */
export type EditorScheme = 'light' | 'dark';

export const EditorSchemeContext = createContext<{ scheme: EditorScheme; setScheme: (scheme: EditorScheme) => void }>({
    scheme: 'light',
    setScheme: () => undefined,
});

export function useEditorScheme() {
    return useContext(EditorSchemeContext);
}

/** El conmutador Claro | Oscuro, conectado al modo del editor. */
export function SchemeSwitch({ className, compact = false }: { className?: string; compact?: boolean }) {
    const { scheme, setScheme } = useEditorScheme();
    const options = [
        { value: 'light' as const, label: 'Claro', Icon: SunIcon },
        { value: 'dark' as const, label: 'Oscuro', Icon: MoonIcon },
    ];

    return (
        <div role="radiogroup" aria-label="Modo que revisas" className={cn('inline-flex rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-0.5', className)}>
            {options.map(({ value, label, Icon }) => (
                <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={scheme === value}
                    onClick={() => setScheme(value)}
                    className={cn(
                        'inline-flex min-h-8 items-center gap-1.5 rounded-[0.375rem] px-2.5 text-[0.75rem] font-semibold transition-colors',
                        scheme === value ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm' : 'text-[var(--muted)] hover:text-[var(--text)]',
                    )}
                >
                    <Icon size={14} weight={scheme === value ? 'fill' : 'regular'} />
                    {!compact && label}
                </button>
            ))}
        </div>
    );
}
