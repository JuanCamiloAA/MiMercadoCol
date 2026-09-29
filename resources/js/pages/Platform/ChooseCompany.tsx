import { router } from '@inertiajs/react';
import { BuildingsIcon, CaretRightIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { EmptyState } from '@/components/ui/Table';
import { PageHeader, SearchInput } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import type { MembershipStatus } from '@/types';
import { MembershipBadge } from '@/components/membership/MembershipParts';

interface CompanyChoice {
    id: number;
    name: string;
    tax_id: string;
    city: string | null;
    is_active: boolean;
    membership_status: MembershipStatus;
    membership_label: string;
}

interface ChooseCompanyProps {
    /** A donde iba: al elegir, vuelve ahi. */
    destination: string;
    companies: CompanyChoice[];
}

const fold = (value: string) =>
    value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

/**
 * La pantalla intermedia del super usuario en «Todas las empresas»: los modulos de empresa
 * (caja, inventario, compras, ajustes...) necesitan una. Al elegirla vuelve a donde iba.
 */
export default function ChooseCompany({ destination, companies }: ChooseCompanyProps) {
    const [query, setQuery] = useState('');
    const [sending, setSending] = useState<number | null>(null);

    const visible = query.trim() === '' ? companies : companies.filter((company) => fold(`${company.name} ${company.tax_id} ${company.city ?? ''}`).includes(fold(query.trim())));

    const choose = (company: CompanyChoice) => {
        setSending(company.id);
        router.post(route('platform.active-company'), { company_id: company.id, redirect: destination }, { onFinish: () => setSending(null) });
    };

    return (
        <AppLayout title="Elige una empresa">
            <PageHeader title="Elige una empresa" description="Esta pantalla es de una empresa. Elige en cuál quieres trabajar y te llevamos de vuelta." />

            {companies.length > 6 && (
                <SearchInput containerClassName="mb-4 max-w-md" placeholder="Buscar por nombre, NIT o ciudad" value={query} onChange={(event) => setQuery(event.target.value)} autoFocus />
            )}

            {visible.length === 0 ? (
                <EmptyState title="Ninguna empresa coincide" icon={<BuildingsIcon size={24} />} />
            ) : (
                <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    {visible.map((company) => (
                        <li key={company.id}>
                            <button
                                type="button"
                                disabled={sending !== null}
                                onClick={() => choose(company)}
                                className={cn(
                                    'flex w-full items-center gap-3 rounded-card border border-[var(--line)] bg-[var(--surface)] p-4 text-left transition-colors hover:border-[var(--accent)] hover:bg-[var(--surface-2)] disabled:opacity-60',
                                    !company.is_active && 'opacity-70',
                                )}
                            >
                                <span className="grid size-10 shrink-0 place-items-center rounded-control bg-[var(--accent-soft)] text-[var(--accent)]">
                                    <BuildingsIcon size={20} />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[0.875rem] font-semibold">{company.name}</span>
                                    <span className="block truncate font-mono text-[0.75rem] text-[var(--muted)] tabular">
                                        NIT {company.tax_id}
                                        {company.city ? ` · ${company.city}` : ''}
                                    </span>
                                    <span className="mt-1.5 flex flex-wrap gap-1">
                                        <MembershipBadge status={company.membership_status} label={company.membership_label} />
                                        {!company.is_active && (
                                            <span className="rounded-full bg-[var(--surface-3)] px-2 py-0.5 text-[0.71875rem] font-medium text-[var(--muted)]">Desactivada</span>
                                        )}
                                    </span>
                                </span>
                                <CaretRightIcon size={16} className="shrink-0 text-[var(--muted)]" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
