import { Head, Link, router, usePage } from '@inertiajs/react';
import { CaretDownIcon, ListIcon, SignOutIcon, StorefrontIcon, XIcon } from '@phosphor-icons/react';
import { Fragment, type ReactNode, useState } from 'react';
import { LiveRefresh } from '@/components/realtime/LiveRefresh';
import { BrandLogo } from '@/components/brand/BrandLogo';
import { BrandMark } from '@/components/brand/BrandMark';
import { BrandStyle } from '@/components/brand/BrandStyle';
import { CompanySwitcher } from '@/components/layout/CompanySwitcher';
import { SidebarNav } from '@/components/layout/SidebarNav';
import { Avatar } from '@/components/ui/Misc';
import { RealtimeStatus } from '@/components/ui/RealtimeStatus';
import { ThemeToggle } from '@/components/ui/ThemeToggle';
import { useFlash } from '@/hooks/use-flash';
import { useSurface } from '@/hooks/use-surface';
import { useTheme } from '@/hooks/use-theme';
import { panelSurface, sidebarOnDark } from '@/lib/brand';
import { cn } from '@/lib/cn';
import { applyNavigationOverrides, isNavGroup, NAVIGATION, type NavLink } from '@/lib/navigation';
import { useFeature, usePermission } from '@/lib/permissions';
import type { PageProps } from '@/types';

interface AppLayoutProps {
    title: string;
    children: ReactNode;
}

export default function AppLayout({ title, children }: AppLayoutProps) {
    const page = usePage<PageProps>();
    const { activeStore, storesForSelector, activeCompany, brand, membershipNotice, storefrontUrl } = page.props;
    const surface = panelSurface(brand ?? null);

    const { resolved } = useTheme(surface.default_theme, !surface.allow_theme_toggle);
    useSurface('panel');
    useFlash();

    const { can, user } = usePermission();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);

    const currentRoute = route().current() ?? '';

    // Sin permiso el enlace no se pinta; un grupo o una sección que se queda vacía, tampoco.
    const hasFeature = useFeature();
    const visible = (link: NavLink) =>
        can(link.permission) &&
        route().has(link.routeName) &&
        (!link.superAdminOnly || Boolean(user?.is_super_admin)) &&
        (!link.feature || hasFeature(link.feature));

    // Primero lo que la empresa configuró (ocultar, renombrar, ordenar) y después el permiso:
    // la configuración nunca devuelve algo que el permiso quitó.
    const sections = applyNavigationOverrides(NAVIGATION, brand?.navigation)
        .map((section) => ({
            ...section,
            entries: section.entries
                .map((entry) => (isNavGroup(entry) ? { ...entry, links: entry.links.filter(visible) } : entry))
                .filter((entry) => (isNavGroup(entry) ? entry.links.length > 0 : visible(entry))),
        }))
        .filter((section) => section.entries.length > 0);

    const sidebarStyle = surface.sidebar_style;
    const onDark = sidebarOnDark(brand ?? null, sidebarStyle, resolved);

    // El estilo del menú se logra con tokens, no con CSS por componente: `data-theme` vuelve
    // oscuro el subárbol aunque el panel sea claro, y `data-sidebar` le pone el color de marca.
    const sidebarAttributes = {
        'data-theme': sidebarStyle === 'dark' ? 'dark' : undefined,
        'data-sidebar': sidebarStyle === 'brand' ? 'brand' : undefined,
    };

    const renderSidebar = (mode: 'expanded' | 'icons') => (
        <div
            {...sidebarAttributes}
            className={cn(
                'flex h-full shrink-0 flex-col border-r border-[var(--line)] bg-[var(--surface)] text-[var(--text)]',
                mode === 'icons' ? 'w-[4.25rem]' : 'w-[14.875rem]',
            )}
        >
            <div className={cn('flex h-16 items-center border-b border-[var(--line)]', mode === 'icons' ? 'justify-center px-2' : 'px-4')}>
                {mode === 'icons' ? (
                    <span title={brand?.display_name ?? undefined}>
                        <BrandMark className="size-9 rounded-control" />
                    </span>
                ) : (
                    <BrandLogo onDark={onDark} subtitle={activeCompany?.name ?? (user?.is_super_admin ? 'Todas las empresas' : 'Consolidado')} className="w-full" />
                )}
            </div>

            <SidebarNav sections={sections} currentRoute={currentRoute} onNavigate={() => setMobileOpen(false)} mode={mode} />

            {/* La tienda de la sede activa; si no esta publicada y encendida, el enlace no se pinta. */}
            {storefrontUrl && (
                <div className={cn('border-t border-[var(--line)]', mode === 'icons' ? 'flex justify-center p-2' : 'p-3')}>
                    <a
                        href={storefrontUrl}
                        target="_blank"
                        rel="noreferrer"
                        title="Ver la tienda"
                        aria-label={mode === 'icons' ? 'Ver la tienda' : undefined}
                        className={cn(
                            'flex h-9 items-center gap-2.5 rounded-control text-[0.8125rem] font-medium text-[var(--muted)] transition-colors hover:bg-[var(--surface-2)] hover:text-[var(--text)]',
                            mode === 'icons' ? 'size-11 justify-center' : 'px-2.5',
                        )}
                    >
                        <StorefrontIcon size={18} />
                        {mode === 'expanded' && 'Ver la tienda'}
                    </a>
                </div>
            )}
        </div>
    );

    return (
        <div className="flex min-h-screen bg-[var(--bg)]">
            <Head title={title} />
            <BrandStyle />
            {/* Lo que cambia en otras pestañas llega solo (tiempo real). */}
            <LiveRefresh />

            <aside className="sticky top-0 hidden h-screen lg:block">{renderSidebar(surface.sidebar_mode)}</aside>

            {/* En el celular el menú siempre va completo: ahí no hay puntero para leer un `title`. */}
            {mobileOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/40" onClick={() => setMobileOpen(false)} />
                    <div className="absolute inset-y-0 left-0 flex h-full flex-col">
                        {user?.is_super_admin && (
                            <div className="border-r border-b border-[var(--line)] bg-[var(--surface)] p-3">
                                <CompanySwitcher block />
                            </div>
                        )}
                        <div className="min-h-0 flex-1">{renderSidebar('expanded')}</div>
                    </div>
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-[var(--line)] bg-[var(--surface)] px-4 lg:px-6">
                    <button
                        type="button"
                        onClick={() => setMobileOpen((open) => !open)}
                        aria-label="Abrir el menu"
                        className="grid size-9 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] lg:hidden"
                    >
                        {mobileOpen ? <XIcon size={20} /> : <ListIcon size={20} />}
                    </button>

                    <h1 className="truncate text-[0.9375rem] font-semibold text-[var(--text)]">{title}</h1>

                    <div className="ml-auto flex items-center gap-2">
                        <RealtimeStatus />
                        <CompanySwitcher className="hidden md:block" />
                        {surface.allow_theme_toggle && <ThemeToggle fallback={surface.default_theme} className="hidden sm:inline-flex" />}

                        {storesForSelector.length > 1 && (
                            <select
                                value={activeStore?.id ?? ''}
                                onChange={(event) =>
                                    router.put(
                                        route('stores.switch'),
                                        { store_id: Number(event.target.value) },
                                        { preserveScroll: true },
                                    )
                                }
                                className="h-9 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2.5 text-[0.8125rem] text-[var(--text)] focus:border-[var(--accent)] focus:outline-none"
                                aria-label="Sede activa"
                            >
                                {storesForSelector.map((store) => (
                                    <option key={store.id} value={store.id}>
                                        Sede {store.name}
                                    </option>
                                ))}
                            </select>
                        )}

                        {storesForSelector.length === 1 && activeStore && (
                            <span className="hidden rounded-control border border-[var(--line)] px-2.5 py-1.5 text-[0.75rem] text-[var(--muted)] sm:inline">
                                Sede {activeStore.name}
                            </span>
                        )}

                        <div className="relative">
                            <button
                                type="button"
                                onClick={() => setUserMenuOpen((open) => !open)}
                                className="flex h-9 items-center gap-2 rounded-control border border-[var(--line)] pl-1 pr-2 transition-colors hover:bg-[var(--surface-2)]"
                            >
                                <Avatar name={user?.full_name ?? ''} src={user?.avatar} size={28} color={user?.role?.color} />
                                <span className="hidden max-w-[7.5rem] truncate text-[0.8125rem] font-medium text-[var(--text)] sm:inline">
                                    {user?.name}
                                </span>
                                <CaretDownIcon size={14} className="text-[var(--muted)]" />
                            </button>

                            {userMenuOpen && (
                                <Fragment>
                                    <div className="fixed inset-0 z-10" onClick={() => setUserMenuOpen(false)} />
                                    <div className="absolute right-0 z-20 mt-1.5 w-60 rounded-card border border-[var(--line)] bg-[var(--surface)] p-1.5 shadow-lg">
                                        <div className="border-b border-[var(--line)] px-2.5 pb-2.5 pt-1.5">
                                            <p className="truncate text-[0.8125rem] font-semibold text-[var(--text)]">{user?.full_name}</p>
                                            <p className="truncate text-[0.75rem] text-[var(--muted)]">{user?.email}</p>
                                            {user?.role && (
                                                <span
                                                    className="mt-1.5 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[0.6875rem] font-medium"
                                                    style={{ backgroundColor: `${user.role.color}1a`, color: user.role.color }}
                                                >
                                                    <span className="size-1.5 rounded-full" style={{ backgroundColor: user.role.color }} />
                                                    {user.role.display_name}
                                                </span>
                                            )}
                                        </div>
                                        {surface.allow_theme_toggle && (
                                            <div className="mt-1 flex items-center justify-between gap-2 rounded-chip px-2.5 py-1.5 sm:hidden">
                                                <span className="text-[0.8125rem] text-[var(--text)]">Tema</span>
                                                <ThemeToggle fallback={surface.default_theme} compact />
                                            </div>
                                        )}
                                        <Link
                                            href={route('password.change.show')}
                                            className="mt-1 flex h-9 items-center rounded-chip px-2.5 text-[0.8125rem] text-[var(--text)] hover:bg-[var(--surface-2)]"
                                        >
                                            Cambiar mi contrasena
                                        </Link>
                                        <Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                            className="flex h-9 w-full items-center gap-2 rounded-chip px-2.5 text-left text-[0.8125rem] text-[var(--danger)] hover:bg-[var(--danger-soft)]"
                                        >
                                            <SignOutIcon size={16} />
                                            Cerrar sesion
                                        </Link>
                                    </div>
                                </Fragment>
                            )}
                        </div>
                    </div>
                </header>

                {membershipNotice && (
                    <div
                        role="status"
                        className={cn(
                            'flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-4 py-2 text-[0.8125rem] font-medium lg:px-6',
                            membershipNotice.tone === 'danger'
                                ? 'border-[var(--danger)]/30 bg-[var(--danger-soft)] text-[var(--danger)]'
                                : 'border-[var(--warn)]/30 bg-[var(--warn-soft)] text-[var(--warn)]',
                        )}
                    >
                        <span className="min-w-0 flex-1">{membershipNotice.message}</span>
                        {membershipNotice.can_pay && route().has('settings.index') && (
                            <Link href={route('settings.index', { pestana: 'membresia' })} className="shrink-0 font-semibold underline underline-offset-2">
                                Pagar ahora
                            </Link>
                        )}
                    </div>
                )}

                <main className="flex-1 px-4 py-6 lg:px-6">
                    <div className="mx-auto w-full max-w-[var(--content-max)] animate-fade-in">{children}</div>
                </main>
            </div>
        </div>
    );
}
