import { CaretRightIcon, MagnifyingGlassIcon } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';
import { Checkbox } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import type { PermissionCatalogueModule } from '@/types';

interface PermissionMatrixProps {
    modules: PermissionCatalogueModule[];
    selected: string[];
    onChange: (permissions: string[]) => void;
    baseline?: string[];
}

/**
 * Matriz del catalogo agrupada por modulo y pagina.
 *
 * Muestra el resumen «gana / pierde» contra el punto de partida para que quien reparte
 * permisos sepa exactamente que esta cambiando antes de guardar.
 */
export function PermissionMatrix({ modules, selected, onChange, baseline }: PermissionMatrixProps) {
    const [search, setSearch] = useState('');
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});

    const selectedSet = useMemo(() => new Set(selected), [selected]);

    const filtered = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (!term) {
            return modules;
        }

        return modules
            .map((module) => ({
                ...module,
                groups: module.groups
                    .map((group) => ({
                        ...group,
                        permissions: group.permissions.filter(
                            (permission) =>
                                permission.label.toLowerCase().includes(term) ||
                                permission.name.toLowerCase().includes(term) ||
                                module.display.toLowerCase().includes(term),
                        ),
                    }))
                    .filter((group) => group.permissions.length > 0),
            }))
            .filter((module) => module.groups.length > 0);
    }, [modules, search]);

    const summary = useMemo(() => {
        if (!baseline) {
            return null;
        }

        const before = new Set(baseline);

        return {
            gained: selected.filter((permission) => !before.has(permission)).length,
            lost: baseline.filter((permission) => !selectedSet.has(permission)).length,
        };
    }, [baseline, selected, selectedSet]);

    const toggle = (permission: string) => {
        onChange(selectedSet.has(permission) ? selected.filter((item) => item !== permission) : [...selected, permission]);
    };

    const toggleModule = (module: PermissionCatalogueModule, checked: boolean) => {
        const names = module.groups.flatMap((group) => group.permissions.map((permission) => permission.name));

        onChange(checked ? Array.from(new Set([...selected, ...names])) : selected.filter((item) => !names.includes(item)));
    };

    const toggleAll = (checked: boolean) => {
        const names = modules.flatMap((module) => module.groups.flatMap((group) => group.permissions.map((permission) => permission.name)));

        onChange(checked ? names : []);
    };

    const totalPermissions = modules.reduce((sum, module) => sum + module.total, 0);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="relative flex min-w-[13.75rem] flex-1 items-center">
                    <MagnifyingGlassIcon size={16} className="pointer-events-none absolute left-3 text-[var(--muted)]" />
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Buscar un permiso"
                        className="h-10 w-full rounded-control border border-[var(--line)] bg-[var(--surface)] pl-9 pr-3 text-sm text-[var(--text)] focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/25"
                    />
                </div>

                <div className="flex items-center gap-3 text-[0.78125rem]">
                    <span className="font-mono text-[var(--muted)] tabular">
                        {selected.length} / {totalPermissions}
                    </span>
                    <button type="button" onClick={() => toggleAll(true)} className="font-medium text-[var(--accent)] hover:underline">
                        Marcar todo
                    </button>
                    <button type="button" onClick={() => toggleAll(false)} className="font-medium text-[var(--muted)] hover:underline">
                        Limpiar
                    </button>
                </div>
            </div>

            {summary && (summary.gained > 0 || summary.lost > 0) && (
                <div className="flex flex-wrap items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem]">
                    <span className="font-medium text-[var(--text)]">Al guardar:</span>
                    {summary.gained > 0 && (
                        <span className="rounded-full bg-[var(--accent-soft)] px-2 py-0.5 font-medium text-[var(--accent)]">
                            gana {summary.gained}
                        </span>
                    )}
                    {summary.lost > 0 && (
                        <span className="rounded-full bg-[var(--danger-soft)] px-2 py-0.5 font-medium text-[var(--danger)]">
                            pierde {summary.lost}
                        </span>
                    )}
                </div>
            )}

            <div className="flex flex-col gap-2">
                {filtered.map((module) => {
                    const names = module.groups.flatMap((group) => group.permissions.map((permission) => permission.name));
                    const checkedCount = names.filter((name) => selectedSet.has(name)).length;
                    const isCollapsed = collapsed[module.key] ?? false;

                    return (
                        <section key={module.key} className="overflow-hidden rounded-card border border-[var(--line)]">
                            <header className="flex items-center gap-3 bg-[var(--surface-2)] px-3 py-2.5">
                                <button
                                    type="button"
                                    onClick={() => setCollapsed((current) => ({ ...current, [module.key]: !isCollapsed }))}
                                    aria-label={isCollapsed ? 'Desplegar' : 'Plegar'}
                                    className="grid size-6 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--surface-3)]"
                                >
                                    <CaretRightIcon size={14} className={cn('transition-transform', !isCollapsed && 'rotate-90')} />
                                </button>

                                <p className="flex-1 text-[0.84375rem] font-semibold text-[var(--text)]">{module.display}</p>

                                <span className="font-mono text-[0.75rem] text-[var(--muted)] tabular">
                                    {checkedCount}/{names.length}
                                </span>

                                <button
                                    type="button"
                                    onClick={() => toggleModule(module, checkedCount < names.length)}
                                    className="text-[0.78125rem] font-medium text-[var(--accent)] hover:underline"
                                >
                                    {checkedCount < names.length ? 'Todo' : 'Nada'}
                                </button>
                            </header>

                            {!isCollapsed && (
                                <div className="divide-y divide-[var(--line)]">
                                    {module.groups.map((group) => (
                                        <div key={group.key} className="px-3 py-2.5">
                                            <p className="mb-2 text-[0.75rem] font-medium uppercase tracking-wide text-[var(--muted)]">
                                                {group.display}
                                            </p>
                                            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                                {group.permissions.map((permission) => (
                                                    <Checkbox
                                                        key={permission.name}
                                                        label={permission.label}
                                                        hint={permission.name}
                                                        checked={selectedSet.has(permission.name)}
                                                        onChange={() => toggle(permission.name)}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    );
                })}

                {filtered.length === 0 && (
                    <p className="rounded-card border border-dashed border-[var(--line)] px-4 py-8 text-center text-[0.8125rem] text-[var(--muted)]">
                        Ningun permiso coincide con «{search}».
                    </p>
                )}
            </div>
        </div>
    );
}
