import { Link } from '@inertiajs/react';
import { usePermission } from '@/lib/permissions';

/** El documento que explica un movimiento del kardex, cuando se puede abrir. */
export interface MovementReferenceData {
    kind: 'supplier_return' | 'customer_return' | 'service';
    id: number;
    label: string;
}

/**
 * Debajo del tipo de movimiento: el número de la devolución a proveedor o de venta (con enlace a
 * su ficha) o el servicio que consumió el artículo.
 */
export function MovementReference({ reference }: { reference: MovementReferenceData | null | undefined }) {
    const { can } = usePermission();

    if (!reference) {
        return null;
    }

    if (reference.kind === 'service') {
        return <span className="mt-0.5 block text-[0.6875rem] text-[var(--muted)]">Consumo en servicio: {reference.label}</span>;
    }

    if (reference.kind === 'customer_return') {
        return can('sales.returns.view') ? (
            <Link
                href={route('customer_returns.show', reference.id)}
                className="mt-0.5 block font-mono text-[0.6875rem] font-medium text-[var(--accent)] hover:underline"
            >
                {reference.label}
            </Link>
        ) : (
            <span className="mt-0.5 block font-mono text-[0.6875rem] text-[var(--muted)]">{reference.label}</span>
        );
    }

    return can('purchases.returns.view') ? (
        <Link
            href={route('supplier_returns.show', reference.id)}
            className="mt-0.5 block font-mono text-[0.6875rem] font-medium text-[var(--accent)] hover:underline"
        >
            {reference.label}
        </Link>
    ) : (
        <span className="mt-0.5 block font-mono text-[0.6875rem] text-[var(--muted)]">{reference.label}</span>
    );
}
