import { Head, Link } from '@inertiajs/react';
import { MagnifyingGlassIcon } from '@phosphor-icons/react';
import { useTheme } from '@/hooks/use-theme';

export default function NotFound() {
    useTheme('light');

    return (
        <div className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4">
            <Head title="No encontrado" />

            <div className="w-full max-w-[26.25rem] rounded-card border border-[var(--line)] bg-[var(--surface)] p-7 text-center">
                <span className="mx-auto grid size-12 place-items-center rounded-full bg-[var(--surface-2)] text-[var(--muted)]">
                    <MagnifyingGlassIcon size={24} />
                </span>

                <h1 className="mt-4 text-[1.25rem] text-[var(--text)]">No encontramos esa pagina</h1>
                <p className="mt-1.5 text-[0.84375rem] text-[var(--muted)]">
                    Es posible que el registro se haya eliminado o que el enlace este mal escrito.
                </p>

                <Link
                    href={route('dashboard')}
                    className="mt-5 inline-flex h-10 items-center justify-center rounded-control bg-[var(--accent)] px-4 text-sm font-medium text-[var(--accent-ink)] hover:brightness-110"
                >
                    Volver al inicio
                </Link>
            </div>
        </div>
    );
}
