import { DesktopIcon, MoonIcon, SunIcon, type Icon } from '@phosphor-icons/react';
import { useTheme, type ThemePreference } from '@/hooks/use-theme';
import { cn } from '@/lib/cn';

interface Option {
    value: ThemePreference;
    label: string;
    icon: Icon;
}

const OPTIONS: Option[] = [
    { value: 'light', label: 'Claro', icon: SunIcon },
    { value: 'dark', label: 'Oscuro', icon: MoonIcon },
    { value: 'system', label: 'Como el sistema', icon: DesktopIcon },
];

interface ThemeToggleProps {
    /** Tema propio de la pantalla mientras nadie haya escogido uno. */
    fallback: ThemePreference;
    /** Version angosta para la barra del celular. */
    compact?: boolean;
    /** En vertical cabe en la columna de iconos del punto de venta. */
    orientation?: 'horizontal' | 'vertical';
    className?: string;
}

/**
 * Interruptor de tema: claro, oscuro o el del sistema operativo.
 *
 * Si la empresa lo oculto en Ajustes, quien lo usa simplemente no lo pinta: no hay version
 * deshabilitada.
 *
 * Son tres botones a la vista en vez de uno que rota, porque un cajero con guantes no debe
 * tener que adivinar cuantas veces tocar para volver al que tenia.
 */
export function ThemeToggle({ fallback, compact = false, orientation = 'horizontal', className }: ThemeToggleProps) {
    const { preference, setPreference } = useTheme(fallback);
    const size = compact ? 'size-7' : 'size-8';

    return (
        <div
            role="radiogroup"
            aria-label="Tema de la pantalla"
            className={cn(
                'inline-flex items-center gap-0.5 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-0.5',
                orientation === 'vertical' && 'flex-col',
                className,
            )}
        >
            {OPTIONS.map((option) => {
                const OptionIcon = option.icon;
                const active = preference === option.value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        aria-label={option.label}
                        title={option.label}
                        onClick={() => setPreference(option.value)}
                        className={cn(
                            'grid place-items-center rounded-chip transition-colors',
                            size,
                            'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent)]',
                            active
                                ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm'
                                : 'text-[var(--muted)] hover:text-[var(--text)]',
                        )}
                    >
                        <OptionIcon size={compact ? 15 : 16} weight={active ? 'fill' : 'regular'} />
                    </button>
                );
            })}
        </div>
    );
}
