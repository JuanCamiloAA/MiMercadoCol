import { Head, Link, usePage } from '@inertiajs/react';
import { LockKeyIcon, SignOutIcon } from '@phosphor-icons/react';
import { useTheme } from '@/hooks/use-theme';
import type { PageProps } from '@/types';

interface ForbiddenProps {
    permission?: string;
    message?: string;
}

export default function Forbidden({ permission, message }: ForbiddenProps) {
    useTheme('light');

    const user = usePage<PageProps>().props.auth?.user ?? null;

    return (
        <div className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4">
            <Head title="Sin permiso" />

            <div className="w-full max-w-[26.25rem] rounded-card border border-[var(--line)] bg-[var(--surface)] p-7 text-center">
                <span className="mx-auto grid size-12 place-items-center rounded-full bg-[var(--danger-soft)] text-[var(--danger)]">
                    <LockKeyIcon size={24} />
                </span>

                <h1 className="mt-4 text-[1.25rem] text-[var(--text)]">Sin permiso</h1>
                <p className="mt-1.5 text-[0.84375rem] text-[var(--muted)]">
                    {message ?? 'No tienes permiso para abrir esta pagina.'}
                </p>

                {/* Con que cuenta se entro: casi siempre el 403 es que quedo abierta otra sesion. */}
                {user && (
                    <p className="mt-3 text-[0.8125rem] text-[var(--text)]">
                        Entraste como <span className="font-medium">{user.full_name}</span>
                        {user.role ? (
                            <>
                                {' '}
                                <span
                                    className="inline-flex items-center rounded-full px-2 py-0.5 text-[0.71875rem] font-medium"
                                    style={{ backgroundColor: `${user.role.color}1a`, color: user.role.color }}
                                >
                                    {user.role.display_name}
                                </span>
                            </>
                        ) : null}
                    </p>
                )}

                {permission && (
                    <p className="mt-3 rounded-control bg-[var(--surface-2)] px-3 py-2 font-mono text-[0.75rem] text-[var(--muted)]">{permission}</p>
                )}

                <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
                    <Link
                        href={route('dashboard')}
                        className="inline-flex h-10 items-center justify-center rounded-control bg-[var(--accent)] px-4 text-sm font-medium text-[var(--accent-ink)] hover:brightness-110"
                    >
                        Volver al inicio
                    </Link>

                    {user && (
                        <Link
                            href={route('logout')}
                            method="post"
                            as="button"
                            className="inline-flex h-10 items-center justify-center gap-1.5 rounded-control border border-[var(--line)] px-4 text-sm font-medium text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                        >
                            <SignOutIcon size={16} />
                            Entrar con otra cuenta
                        </Link>
                    )}
                </div>
            </div>
        </div>
    );
}
