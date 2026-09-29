import { Head, Link } from '@inertiajs/react';
import { WarningOctagonIcon } from '@phosphor-icons/react';
import { useTheme } from '@/hooks/use-theme';

interface ServerErrorProps {
    status?: number;
}

export default function ServerError({ status = 500 }: ServerErrorProps) {
    useTheme('light');

    return (
        <div className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4">
            <Head title="Algo se rompio" />

            <div className="w-full max-w-[26.25rem] rounded-card border border-[var(--line)] bg-[var(--surface)] p-7 text-center">
                <span className="mx-auto grid size-12 place-items-center rounded-full bg-[var(--danger-soft)] text-[var(--danger)]">
                    <WarningOctagonIcon size={24} />
                </span>

                <h1 className="mt-4 text-[1.25rem] text-[var(--text)]">
                    {status === 503 ? 'El sistema esta en mantenimiento' : 'Algo se rompio de nuestro lado'}
                </h1>
                <p className="mt-1.5 text-[0.84375rem] text-[var(--muted)]">
                    {status === 503
                        ? 'Vuelve a intentarlo en unos minutos.'
                        : 'El error quedo registrado. Intenta de nuevo y, si sigue pasando, avisale al administrador.'}
                </p>

                <div className="mt-5 flex justify-center gap-2">
                    <button
                        type="button"
                        onClick={() => window.location.reload()}
                        className="inline-flex h-10 items-center justify-center rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                    >
                        Reintentar
                    </button>
                    <Link
                        href={route('dashboard')}
                        className="inline-flex h-10 items-center justify-center rounded-control bg-[var(--accent)] px-4 text-sm font-medium text-[var(--accent-ink)] hover:brightness-110"
                    >
                        Volver al inicio
                    </Link>
                </div>
            </div>
        </div>
    );
}
