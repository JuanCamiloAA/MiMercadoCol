import { Head, Link } from '@inertiajs/react';
import { LockKeyIcon, SignOutIcon } from '@phosphor-icons/react';
import { useTheme } from '@/hooks/use-theme';

/**
 * Lo que ve un usuario sin permiso de pagar cuando la membresia de su empresa esta suspendida:
 * a quien acudir y como salir. El administrador, en cambio, llega a Ajustes > Membresia.
 */
export default function Suspended({ company }: { company: string }) {
    useTheme('light');

    return (
        <div className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4">
            <Head title="Cuenta suspendida" />

            <div className="w-full max-w-[26.25rem] rounded-card border border-[var(--line)] bg-[var(--surface)] p-7 text-center">
                <span className="mx-auto grid size-12 place-items-center rounded-full bg-[var(--warn-soft)] text-[var(--warn)]">
                    <LockKeyIcon size={24} />
                </span>
                <h1 className="mt-4 text-[1.25rem] text-[var(--text)]">La cuenta está suspendida</h1>
                <p className="mt-1.5 text-[0.84375rem] text-[var(--muted)]">
                    La membresía de {company} no está al día. Pídele a un administrador de la empresa que la pague para volver a trabajar.
                </p>

                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="mt-5 inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-2)]"
                >
                    <SignOutIcon size={16} />
                    Cerrar sesión
                </Link>
            </div>
        </div>
    );
}
