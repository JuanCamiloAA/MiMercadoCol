import { Head, Link, usePage } from '@inertiajs/react';
import {
    CashRegisterIcon,
    HouseIcon,
    LockKeyIcon,
    ShoppingCartIcon,
    SignOutIcon,
    type Icon,
} from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { BrandMark } from '@/components/brand/BrandMark';
import { BrandStyle } from '@/components/brand/BrandStyle';
import { ThemeToggle } from '@/components/ui/ThemeToggle';
import { useFlash } from '@/hooks/use-flash';
import { useSurface } from '@/hooks/use-surface';
import { useTheme } from '@/hooks/use-theme';
import { posSurface } from '@/lib/brand';
import { cn } from '@/lib/cn';
import { usePermission } from '@/lib/permissions';
import type { PageProps } from '@/types';

interface PosLayoutProps {
    title: string;
    children: ReactNode;
    current: 'sale' | 'opening' | 'closing';
}

interface RailItem {
    key: PosLayoutProps['current'];
    label: string;
    icon: Icon;
    routeName: string;
    permission: string;
}

const RAIL: RailItem[] = [
    { key: 'sale', label: 'Vender', icon: ShoppingCartIcon, routeName: 'pos.index', permission: 'pos.sale.view' },
    { key: 'opening', label: 'Apertura', icon: CashRegisterIcon, routeName: 'registers.opening.create', permission: 'registers.open.view' },
    { key: 'closing', label: 'Cierre', icon: LockKeyIcon, routeName: 'registers.closing.create', permission: 'registers.close.view' },
];

export default function PosLayout({ title, children, current }: PosLayoutProps) {
    const { brand, posRail, activeCompany, auth } = usePage<PageProps>().props;
    const surface = posSurface(brand ?? null);

    useTheme(surface.default_theme, !surface.allow_theme_toggle);
    useSurface('pos');
    useFlash();

    const { can } = usePermission();

    // El orden lo pone la empresa en Ajustes > Caja; el permiso sigue filtrando después.
    const order = posRail ?? RAIL.map((item) => item.key);
    const rail = [...RAIL].sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));

    /*
     * La caja ocupa la pantalla exacta, ni un pixel mas.
     *
     * Con `min-h-screen` el cascaron podia crecer hacia abajo, y entonces los paneles de
     * adentro —que ya traen su propio `overflow-y-auto`— nunca se acotaban: en vez de
     * desplazarse la lista, se desplazaba la pagina entera y el boton de cobrar quedaba debajo
     * del borde. Con `h-screen` y sin desbordamiento, cada panel se desplaza solo y el total y
     * el cobro no se mueven de su sitio.
     */
    return (
        <div className="flex h-screen overflow-hidden bg-[var(--bg)] text-[var(--text)]">
            <Head title={title} />
            <BrandStyle />

            <nav className="flex h-full w-[4.5rem] shrink-0 flex-col items-center gap-2 overflow-y-auto border-r border-[var(--line)] bg-[var(--surface)] py-3">
                <span className="mb-2" title={brand?.display_name ?? undefined}>
                    <BrandMark className="size-10 rounded-card" iconClassName="size-6" ink="var(--accent-ink)" />
                </span>

                {/* El super usuario ve en que empresa esta vendiendo. Aqui no se cambia: cambiar
                    de empresa a mitad de una venta es un error esperando pasar. */}
                {auth.user?.is_super_admin && activeCompany && (
                    <span
                        title={activeCompany.name}
                        className="-mt-1 mb-1 line-clamp-2 max-w-[3.75rem] text-center text-[0.625rem] leading-tight font-semibold text-[var(--muted)]"
                    >
                        {activeCompany.name}
                    </span>
                )}

                {rail
                    .filter((item) => can(item.permission))
                    .map((item) => {
                        const ItemIcon = item.icon;
                        const active = current === item.key;

                        return (
                            <Link
                                key={item.key}
                                href={route(item.routeName)}
                                className={cn(
                                    'flex h-[3.625rem] w-[3.625rem] flex-col items-center justify-center gap-1 rounded-card text-[0.6875rem] font-medium transition-colors',
                                    active
                                        ? 'bg-[var(--accent)] text-[var(--accent-ink)]'
                                        : 'text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]',
                                )}
                            >
                                <ItemIcon size={22} weight={active ? 'fill' : 'regular'} />
                                {item.label}
                            </Link>
                        );
                    })}

                <div className="mt-auto flex flex-col items-center gap-2">
                    {surface.allow_theme_toggle && <ThemeToggle fallback={surface.default_theme} orientation="vertical" compact />}

                    <Link
                        href={route('dashboard')}
                        title="Volver al panel"
                        className="flex h-[3.25rem] w-[3.625rem] flex-col items-center justify-center gap-1 rounded-card text-[0.6875rem] font-medium text-[var(--muted)] transition-colors hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                    >
                        <HouseIcon size={20} />
                        Panel
                    </Link>
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        title="Cerrar sesion"
                        className="flex h-[3.25rem] w-[3.625rem] flex-col items-center justify-center gap-1 rounded-card text-[0.6875rem] font-medium text-[var(--muted)] transition-colors hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                    >
                        <SignOutIcon size={20} />
                        Salir
                    </Link>
                </div>
            </nav>

            <main className="flex min-w-0 flex-1 flex-col overflow-hidden">{children}</main>
        </div>
    );
}
