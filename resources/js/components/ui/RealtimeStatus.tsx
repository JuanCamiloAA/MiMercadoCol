import { CircleIcon, WifiHighIcon, WifiSlashIcon } from '@phosphor-icons/react';
import { useRealtimeStatus } from '@/hooks/use-realtime';
import { cn } from '@/lib/cn';

/**
 * Si la pantalla se está enterando de lo que pasa, o no.
 *
 * Es discreto a propósito, pero tiene que estar: sin él, «no ha entrado ningún pedido» y «el
 * socket se cayó hace media hora» se ven exactamente igual, y quien está en el mostrador
 * confía en una pantalla que dejó de informar.
 */
export function RealtimeStatus({ compact = false }: { compact?: boolean }) {
    const { connected, enabled, label } = useRealtimeStatus();

    if (!enabled) {
        return null;
    }

    if (compact) {
        return (
            <span
                title={label}
                aria-label={label}
                className={cn('grid size-6 place-items-center', connected ? 'text-[var(--accent)]' : 'text-[var(--warn)]')}
            >
                <CircleIcon size={9} weight="fill" className={connected ? undefined : 'animate-pulse'} />
            </span>
        );
    }

    return (
        <span
            title={connected ? 'La pantalla se actualiza sola' : 'No se está actualizando sola; recarga si algo no cuadra'}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-chip px-2 py-1 text-[0.71875rem] font-medium',
                connected ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'bg-[var(--warn-soft)] text-[var(--warn)]',
            )}
        >
            {connected ? <WifiHighIcon size={13} weight="bold" /> : <WifiSlashIcon size={13} weight="bold" className="animate-pulse" />}
            {label}
        </span>
    );
}
