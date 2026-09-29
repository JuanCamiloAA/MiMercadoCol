import { Head } from '@inertiajs/react';
import { StorefrontIcon } from '@phosphor-icons/react';
import { useTheme } from '@/hooks/use-theme';

/**
 * La tienda de una empresa suspendida, sin tienda en su plan o apagada. No se explica por que:
 * al cliente solo le importa que por ahora no puede comprar aqui y a donde ir.
 */
export default function StorefrontUnavailable({ message, companyName }: { message: string; companyName: string | null }) {
    useTheme('light');

    return (
        <div className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4">
            <Head title="Tienda no disponible" />

            <div className="w-full max-w-[26.25rem] rounded-card border border-[var(--line)] bg-[var(--surface)] p-7 text-center">
                <span className="mx-auto grid size-12 place-items-center rounded-full bg-[var(--surface-2)] text-[var(--muted)]">
                    <StorefrontIcon size={24} />
                </span>
                <h1 className="mt-4 text-[1.25rem] text-[var(--text)]">{companyName ?? 'Tienda en línea'}</h1>
                <p className="mt-1.5 text-[0.9375rem] text-[var(--muted)]">{message}</p>

                <a
                    href="/"
                    className="mt-5 inline-flex h-10 items-center rounded-control bg-[var(--accent)] px-4 text-sm font-semibold text-[var(--accent-ink)] hover:opacity-90"
                >
                    Buscar otra tienda
                </a>
            </div>
        </div>
    );
}
