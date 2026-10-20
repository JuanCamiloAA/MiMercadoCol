import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import { usePermission } from '@/lib/permissions';

const TABS = [
    { key: 'methods', label: 'Medios', route: 'payment_methods.index', permission: 'payment_methods.index.view' },
    { key: 'gateways', label: 'Pasarelas', route: 'payment_methods.gateways.index', permission: 'payment_methods.gateways.view' },
    { key: 'terminals', label: 'Datáfonos integrados', route: 'payment_methods.terminals.index', permission: 'payment_methods.terminals.view' },
    { key: 'transactions', label: 'Transacciones', route: 'payment_methods.transactions.index', permission: 'payment_methods.transactions.view' },
] as const;

export type PaymentTab = (typeof TABS)[number]['key'];

/**
 * Las pestañas del módulo. Cada una sale solo si quien entra tiene su permiso (y el plan lo
 * incluye: sin pasarelas en el plan, el permiso no llega y la pestaña no se pinta).
 */
export default function PaymentTabs({ active, counts }: { active: PaymentTab; counts?: Partial<Record<PaymentTab, number>> }) {
    const { can } = usePermission();
    const visible = TABS.filter((tab) => can(tab.permission));

    if (visible.length < 2) {
        return null;
    }

    return (
        <nav className="mb-5 flex flex-wrap items-center gap-1 border-b border-[var(--line)]" aria-label="Secciones de medios de pago">
            {visible.map((tab) => (
                <Link
                    key={tab.key}
                    href={route(tab.route)}
                    preserveScroll
                    className={cn(
                        '-mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-[0.8125rem] font-medium transition-colors',
                        active === tab.key ? 'border-[var(--accent)] text-[var(--accent)]' : 'border-transparent text-[var(--muted)] hover:text-[var(--text)]',
                    )}
                >
                    {tab.label}
                    {counts?.[tab.key] !== undefined && counts[tab.key]! > 0 && (
                        <span className="rounded-full bg-[var(--warn-soft)] px-1.5 py-0.5 font-mono text-[0.6875rem] tabular text-[var(--warn)]">{counts[tab.key]}</span>
                    )}
                </Link>
            ))}
        </nav>
    );
}
