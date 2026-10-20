import { CaretRightIcon, LockSimpleIcon, MagnifyingGlassIcon } from '@phosphor-icons/react';
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { Badge, type BadgeTone } from '@/components/ui/Misc';
import { cn } from '@/lib/cn';
import type { PermissionCatalogueModule } from '@/types';

/** Una etiqueta junto al permiso: de donde sale («Del plan», «Agregado hasta…»). */
export interface PermissionAnnotation {
    label: string;
    tone: BadgeTone;
}

interface PermissionMatrixProps {
    modules: PermissionCatalogueModule[];
    selected: string[];
    onChange?: (permissions: string[]) => void;
    baseline?: string[];
    /** Siempre marcados y sin poder quitarse (lo que todo plan incluye). */
    locked?: string[];
    /** Solo para mirar: sin casillas que se puedan tocar. */
    readOnly?: boolean;
    /** Etiqueta por permiso. */
    annotations?: Record<string, PermissionAnnotation>;
    /**
     * El editor de planes: un modulo o una pagina completos se guardan como comodin, y la
     * pantalla lo dice («incluye lo que se agregue despues»).
     */
    wildcards?: boolean;
    /** Lo que va al pie, como la nota de lo que el plan no incluye. */
    footer?: ReactNode;
}

/**
 * Matriz del catalogo agrupada por modulo y pagina.
 *
 * Muestra el resumen «gana / pierde» contra el punto de partida para que quien reparte
 * permisos sepa exactamente que esta cambiando antes de guardar. La misma matriz arma lo que
 * incluye un plan y lo muestra en solo lectura en la empresa y en Ajustes > Membresia.
 */
export function PermissionMatrix({ modules, selected, onChange, baseline, locked = [], readOnly = false, annotations, wildcards = false, footer }: PermissionMatrixProps) {
    const [search, setSearch] = useState('');
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});

    const lockedSet = useMemo(() => new Set(locked), [locked]);
    const selectedSet = useMemo(() => new Set([...selected, ...locked]), [selected, locked]);
    const editable = !readOnly && onChange !== undefined;

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
                                group.display.toLowerCase().includes(term) ||
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

    const setMany = (names: string[], checked: boolean) => {
        if (!editable) {
            return;
        }

        const touchable = names.filter((name) => !lockedSet.has(name));

        onChange?.(checked ? Array.from(new Set([...selected, ...touchable])) : selected.filter((item) => !touchable.includes(item)));
    };

    const toggle = (permission: string) => setMany([permission], !selectedSet.has(permission));

    const allNames = modules.flatMap((module) => module.groups.flatMap((group) => group.permissions.map((permission) => permission.name)));
    const totalPermissions = allNames.length;
    const selectedCount = allNames.filter((name) => selectedSet.has(name)).length;

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
                        {selectedCount} / {totalPermissions}
                    </span>
                    {editable && (
                        <>
                            <button type="button" onClick={() => setMany(allNames, true)} className="font-medium text-[var(--accent)] hover:underline">
                                Marcar todo
                            </button>
                            <button type="button" onClick={() => setMany(allNames, false)} className="font-medium text-[var(--muted)] hover:underline">
                                Limpiar
                            </button>
                        </>
                    )}
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
                    // El estado del modulo se mide contra el modulo completo, no contra lo filtrado.
                    const full = modules.find((item) => item.key === module.key) ?? module;
                    const names = full.groups.flatMap((group) => group.permissions.map((permission) => permission.name));
                    const checkedCount = names.filter((name) => selectedSet.has(name)).length;
                    const isCollapsed = collapsed[module.key] ?? false;
                    const complete = checkedCount === names.length && names.length > 0;

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

                                <TriState
                                    checked={complete}
                                    indeterminate={checkedCount > 0 && !complete}
                                    disabled={!editable}
                                    label={`Todo ${module.display}`}
                                    onChange={(checked) => setMany(names, checked)}
                                />

                                <p className="min-w-0 flex-1 text-[0.84375rem] font-semibold text-[var(--text)]">
                                    {module.display}
                                    {wildcards && complete && (
                                        <span className="ml-2 text-[0.71875rem] font-normal text-[var(--accent)]">Incluye lo que se agregue después</span>
                                    )}
                                </p>

                                <span className="font-mono text-[0.75rem] text-[var(--muted)] tabular">
                                    {checkedCount}/{names.length}
                                </span>
                            </header>

                            {!isCollapsed && (
                                <div className="divide-y divide-[var(--line)]">
                                    {module.groups.map((group) => {
                                        const fullGroup = full.groups.find((item) => item.key === group.key) ?? group;
                                        const groupNames = fullGroup.permissions.map((permission) => permission.name);
                                        const groupChecked = groupNames.filter((name) => selectedSet.has(name)).length;
                                        const groupComplete = groupChecked === groupNames.length && groupNames.length > 0;

                                        return (
                                            <div key={group.key} className="px-3 py-2.5">
                                                <div className="mb-2 flex flex-wrap items-center gap-2">
                                                    <TriState
                                                        checked={groupComplete}
                                                        indeterminate={groupChecked > 0 && !groupComplete}
                                                        disabled={!editable}
                                                        label={`Toda la página ${group.display}`}
                                                        onChange={(checked) => setMany(groupNames, checked)}
                                                    />
                                                    <p className="text-[0.75rem] font-medium uppercase tracking-wide text-[var(--muted)]">{group.display}</p>
                                                    {wildcards && groupComplete && !complete && (
                                                        <span className="text-[0.71875rem] text-[var(--accent)]">Incluye lo que se agregue después</span>
                                                    )}
                                                </div>
                                                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                                    {group.permissions.map((permission) => {
                                                        const isLocked = lockedSet.has(permission.name);
                                                        const annotation = annotations?.[permission.name];

                                                        return (
                                                            <label
                                                                key={permission.name}
                                                                className={cn('flex items-start gap-2.5', editable && !isLocked ? 'cursor-pointer' : 'cursor-default')}
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    checked={selectedSet.has(permission.name)}
                                                                    disabled={!editable || isLocked}
                                                                    onChange={() => toggle(permission.name)}
                                                                    className="mt-0.5 size-[1.125rem] shrink-0 rounded-chip border border-[var(--line-2)] bg-[var(--surface)] accent-[var(--accent)] disabled:cursor-default"
                                                                />
                                                                <span className="min-w-0 select-none text-sm leading-tight text-[var(--text)]">
                                                                    <span className="flex flex-wrap items-center gap-1.5">
                                                                        {permission.label}
                                                                        {isLocked && <LockSimpleIcon size={12} className="text-[var(--muted)]" aria-label="Siempre incluido" />}
                                                                        {annotation && <Badge tone={annotation.tone}>{annotation.label}</Badge>}
                                                                    </span>
                                                                    <span className="mt-0.5 block text-[0.75rem] font-normal text-[var(--muted)]">{permission.name}</span>
                                                                </span>
                                                            </label>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        );
                                    })}
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

            {footer}
        </div>
    );
}

/** Casilla de tres estados: marcada, vacia o a medias (la del modulo y la de la pagina). */
function TriState({ checked, indeterminate, disabled, label, onChange }: { checked: boolean; indeterminate: boolean; disabled: boolean; label: string; onChange: (checked: boolean) => void }) {
    const ref = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (ref.current) {
            ref.current.indeterminate = indeterminate;
        }
    }, [indeterminate]);

    return (
        <input
            ref={ref}
            type="checkbox"
            aria-label={label}
            checked={checked}
            disabled={disabled}
            onChange={(event) => onChange(event.target.checked)}
            className="size-[1.125rem] shrink-0 cursor-pointer rounded-chip border border-[var(--line-2)] bg-[var(--surface)] accent-[var(--accent)] disabled:cursor-default"
        />
    );
}
