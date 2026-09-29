import { router, usePage } from '@inertiajs/react';
import { BuildingsIcon, CaretUpDownIcon, CheckIcon, GlobeIcon, MagnifyingGlassIcon } from '@phosphor-icons/react';
import { Fragment, useEffect, useState } from 'react';
import { cn } from '@/lib/cn';
import type { CompanyOption, PageProps } from '@/types';

/** Con mas de estas empresas aparece el buscador. */
const SEARCH_FROM = 8;

const fold = (value: string) =>
    value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

/**
 * El selector de empresa del super usuario: «Todas las empresas» o una en particular.
 *
 * Elegir una lo deja trabajar como su administrador; «Todas» muestra el resumen de la
 * plataforma. Las inactivas y suspendidas se pueden elegir (hay que poder entrar a
 * arreglarlas) y se marcan. Para los demas usuarios no se pinta.
 */
export function CompanySwitcher({ className, block = false }: { className?: string; block?: boolean }) {
    const { companiesForSelector, activeCompanyId, auth } = usePage<PageProps>().props;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [sending, setSending] = useState(false);

    useEffect(() => {
        if (!open) {
            setQuery('');
        }
    }, [open]);

    if (!auth.user?.is_super_admin) {
        return null;
    }

    const companies = companiesForSelector ?? [];
    const active = companies.find((company) => company.id === activeCompanyId) ?? null;
    const visible = query.trim() === '' ? companies : companies.filter((company) => fold(company.name).includes(fold(query.trim())));

    const choose = (id: number | null) => {
        setOpen(false);

        if (id === activeCompanyId) {
            return;
        }

        setSending(true);
        router.post(
            route('platform.active-company'),
            { company_id: id, redirect: window.location.pathname + window.location.search },
            { onFinish: () => setSending(false) },
        );
    };

    return (
        <div className={cn('relative', block && 'w-full', className)}>
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-label="Empresa activa"
                disabled={sending}
                className={cn(
                    'flex h-9 items-center gap-2 rounded-[0.5625rem] border border-[var(--line)] bg-[var(--surface)] pr-2 pl-2.5 text-[0.8125rem] font-medium text-[var(--text)] transition-colors hover:bg-[var(--surface-2)] disabled:opacity-60',
                    block ? 'w-full' : 'max-w-[16rem]',
                )}
            >
                {active ? <BuildingsIcon size={16} className="shrink-0 text-[var(--accent)]" /> : <GlobeIcon size={16} className="shrink-0 text-[var(--accent)]" />}
                <span className="min-w-0 flex-1 truncate text-left">{active ? active.name : 'Todas las empresas'}</span>
                {active && <StatusMark company={active} />}
                <CaretUpDownIcon size={14} className="shrink-0 text-[var(--muted)]" />
            </button>

            {open && (
                <Fragment>
                    <div className="fixed inset-0 z-30" onClick={() => setOpen(false)} />
                    <div
                        className={cn(
                            'absolute z-40 mt-1.5 w-72 overflow-hidden rounded-card border border-[var(--line)] bg-[var(--surface)] shadow-lg',
                            block ? 'left-0' : 'left-0 sm:right-0 sm:left-auto',
                        )}
                        onKeyDown={(event) => event.key === 'Escape' && setOpen(false)}
                    >
                        {companies.length > SEARCH_FROM && (
                            <div className="relative border-b border-[var(--line)] p-2">
                                <MagnifyingGlassIcon size={15} className="pointer-events-none absolute top-1/2 left-4.5 -translate-y-1/2 text-[var(--muted)]" />
                                <input
                                    type="search"
                                    autoFocus
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Buscar empresa"
                                    aria-label="Buscar empresa"
                                    className="h-9 w-full rounded-control border border-[var(--line)] bg-[var(--surface-2)] pr-3 pl-8 text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none"
                                />
                            </div>
                        )}

                        <ul role="listbox" aria-label="Empresas" className="max-h-80 overflow-y-auto p-1.5">
                            {query.trim() === '' && (
                                <Option selected={activeCompanyId === null} onClick={() => choose(null)}>
                                    <GlobeIcon size={16} className="shrink-0 text-[var(--muted)]" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-semibold">Todas las empresas</span>
                                        <span className="block truncate text-[0.71875rem] text-[var(--muted)]">Resumen de la plataforma</span>
                                    </span>
                                </Option>
                            )}

                            {visible.map((company) => (
                                <Option key={company.id} selected={company.id === activeCompanyId} onClick={() => choose(company.id)}>
                                    <BuildingsIcon size={16} className="shrink-0 text-[var(--muted)]" />
                                    <span className="min-w-0 flex-1 truncate">{company.name}</span>
                                    <StatusMark company={company} />
                                </Option>
                            ))}

                            {visible.length === 0 && <li className="px-3 py-4 text-center text-[0.8125rem] text-[var(--muted)]">Ninguna empresa coincide.</li>}
                        </ul>
                    </div>
                </Fragment>
            )}
        </div>
    );
}

function Option({ selected, onClick, children }: { selected: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <li role="option" aria-selected={selected}>
            <button
                type="button"
                onClick={onClick}
                className={cn(
                    'flex w-full items-center gap-2.5 rounded-chip px-2.5 py-2 text-left text-[0.8125rem] transition-colors',
                    selected ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'text-[var(--text)] hover:bg-[var(--surface-2)]',
                )}
            >
                {children}
                {selected && <CheckIcon size={14} weight="bold" className="shrink-0" />}
            </button>
        </li>
    );
}

/** Inactiva, suspendida o en gracia: lo que el super usuario tiene que ver antes de entrar. */
function StatusMark({ company }: { company: CompanyOption }) {
    if (!company.is_active) {
        return <span className="shrink-0 rounded-full bg-[var(--surface-3)] px-1.5 py-0.5 text-[0.65625rem] font-semibold text-[var(--muted)]">Inactiva</span>;
    }

    if (company.membership_status === 'suspended' || company.membership_status === 'cancelled') {
        return (
            <span className="shrink-0 rounded-full bg-[var(--danger-soft)] px-1.5 py-0.5 text-[0.65625rem] font-semibold text-[var(--danger)]">
                {company.membership_label}
            </span>
        );
    }

    if (company.membership_status === 'grace') {
        return (
            <span className="shrink-0 rounded-full bg-[var(--warn-soft)] px-1.5 py-0.5 text-[0.65625rem] font-semibold text-[var(--warn)]">
                {company.membership_label}
            </span>
        );
    }

    return null;
}
