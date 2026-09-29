import { ArrowDownIcon, ArrowUpIcon, EyeIcon, EyeSlashIcon, LockSimpleIcon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { applyNavigationOverrides, entryKey, isNavGroup, NAVIGATION, PLATFORM_SECTIONS, type NavEntry, type NavigationOverrides } from '@/lib/navigation';
import { FieldBlock, PreviewFrame, SaveBar, TabHeader } from '../SettingField';
import { useSettingsForm } from '../form';
import type { SettingKey, TabProps } from '../types';

export const NAVIGATION_KEYS = ['panel.navigation', 'panel.home_by_role'] as const satisfies readonly SettingKey[];

/** El menu de la empresa: la seccion de plataforma no es suya y no se edita aqui. */
const COMPANY_NAVIGATION = NAVIGATION.filter((section) => !PLATFORM_SECTIONS.includes(section.key));

/** PHP manda `[]` cuando esta vacio. */
const asOverrides = (value: NavigationOverrides | []): NavigationOverrides => (Array.isArray(value) ? {} : value);
const asHomes = (value: Record<string, string> | []): Record<string, string> => (Array.isArray(value) ? {} : value);

/** Nombre original de cada clave, para mostrarlo como sugerencia y saber si hubo cambio. */
const ORIGINAL_LABELS: Record<string, string> = Object.fromEntries(
    COMPANY_NAVIGATION.flatMap((section) => [
        [section.key, section.title],
        ...section.entries.flatMap((entry) =>
            isNavGroup(entry)
                ? [[entry.key, entry.label], ...entry.links.map((link) => [link.routeName, link.label])]
                : [[entry.routeName, entry.label]],
        ),
    ]),
);

/**
 * Ajustes > Menu: ordenar, renombrar u ocultar lo que se ve en el menu lateral, y a que pagina
 * entra cada rol.
 *
 * Solo se guardan diferencias con el menu del codigo. Lo que se agregue manana aparece en su
 * sitio sin que haya que volver aqui. Ocultar no quita permisos, y lo que alguien no puede abrir
 * no se le muestra aunque este visible aqui.
 */
export function NavigationTab({ settings, defaults, readOnly, onDirtyChange, lockedNavigation, roles, homePages }: TabProps) {
    const { form, data, errors, set, save, reset } = useSettingsForm(NAVIGATION_KEYS, settings, onDirtyChange);

    const overrides = asOverrides(data['panel.navigation']);
    const homes = asHomes(data['panel.home_by_role']);
    const hidden = new Set(overrides.hidden ?? []);
    const locked = new Set(lockedNavigation);

    // El menu con orden y nombres aplicados, pero sin ocultar nada: aqui se ve todo.
    const sections = applyNavigationOverrides(COMPANY_NAVIGATION, { ...overrides, hidden: [] });

    const update = (next: NavigationOverrides) => set('panel.navigation', next);

    const moveSection = (index: number, delta: number) => {
        const order = sections.map((section) => section.key);
        const target = index + delta;

        if (target < 0 || target >= order.length) {
            return;
        }

        [order[index], order[target]] = [order[target], order[index]];
        update({ ...overrides, section_order: order });
    };

    const moveEntry = (sectionKey: string, entries: NavEntry[], index: number, delta: number) => {
        const order = entries.map(entryKey);
        const target = index + delta;

        if (target < 0 || target >= order.length) {
            return;
        }

        [order[index], order[target]] = [order[target], order[index]];
        update({ ...overrides, entry_order: { ...(overrides.entry_order ?? {}), [sectionKey]: order } });
    };

    const rename = (key: string, label: string) => {
        const labels = { ...(overrides.labels ?? {}) };

        if (label.trim() === '' || label === ORIGINAL_LABELS[key]) {
            delete labels[key];
        } else {
            labels[key] = label;
        }

        update({ ...overrides, labels });
    };

    const toggle = (key: string) => {
        if (locked.has(key)) {
            return;
        }

        const next = hidden.has(key) ? [...hidden].filter((item) => item !== key) : [...hidden, key];
        update({ ...overrides, hidden: next });
    };

    const labelOf = (key: string) => overrides.labels?.[key] ?? '';

    const factory = () => {
        reset(defaults);
    };

    const navigationError = Object.entries(errors).find(([key]) => key.startsWith('panel.navigation'))?.[1];

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,17rem)]">
            <div className="flex flex-col gap-5">
                <TabHeader scope="Empresa · lo ven todos, cada quien según sus permisos" onFactory={readOnly ? undefined : factory} />

                <FieldBlock
                    label="Menú lateral"
                    error={navigationError}
                    hint="Ordena con las flechas, escribe otro nombre u oculta con el ojo. Ajustes y lo que lo contiene no se pueden ocultar: nadie podría volver aquí para deshacerlo."
                >
                    <div className="flex flex-col gap-3">
                        {sections.map((section, sectionIndex) => {
                            const sectionHidden = hidden.has(section.key);

                            return (
                                <div key={section.key} className={cn('rounded-card border border-[var(--line)] bg-[var(--surface)]', sectionHidden && 'opacity-60')}>
                                    <Row
                                        readOnly={readOnly}
                                        onUp={sectionIndex > 0 ? () => moveSection(sectionIndex, -1) : undefined}
                                        onDown={sectionIndex < sections.length - 1 ? () => moveSection(sectionIndex, 1) : undefined}
                                        value={labelOf(section.key)}
                                        placeholder={ORIGINAL_LABELS[section.key]}
                                        onRename={(label) => rename(section.key, label)}
                                        hidden={sectionHidden}
                                        locked={locked.has(section.key)}
                                        onToggle={() => toggle(section.key)}
                                        strong
                                        what={`la sección ${section.title}`}
                                    />

                                    <ul className="flex flex-col gap-1 border-t border-[var(--line)] p-2">
                                        {section.entries.map((entry, entryIndex) => {
                                            const key = entryKey(entry);
                                            const Icon = entry.icon;

                                            return (
                                                <li key={key} className={cn(hidden.has(key) && 'opacity-60')}>
                                                    <Row
                                                        readOnly={readOnly}
                                                        icon={<Icon size={16} />}
                                                        onUp={entryIndex > 0 ? () => moveEntry(section.key, section.entries, entryIndex, -1) : undefined}
                                                        onDown={
                                                            entryIndex < section.entries.length - 1
                                                                ? () => moveEntry(section.key, section.entries, entryIndex, 1)
                                                                : undefined
                                                        }
                                                        value={labelOf(key)}
                                                        placeholder={ORIGINAL_LABELS[key]}
                                                        onRename={(label) => rename(key, label)}
                                                        hidden={hidden.has(key)}
                                                        locked={locked.has(key)}
                                                        onToggle={() => toggle(key)}
                                                        what={entry.label}
                                                    />

                                                    {isNavGroup(entry) && (
                                                        <ul className="mt-1 ml-9 flex flex-col gap-1 border-l border-[var(--line)] pl-2">
                                                            {entry.links.map((link) => (
                                                                <li key={link.routeName} className={cn(hidden.has(link.routeName) && 'opacity-60')}>
                                                                    <Row
                                                                        readOnly={readOnly}
                                                                        value={labelOf(link.routeName)}
                                                                        placeholder={ORIGINAL_LABELS[link.routeName]}
                                                                        onRename={(label) => rename(link.routeName, label)}
                                                                        hidden={hidden.has(link.routeName)}
                                                                        locked={locked.has(link.routeName)}
                                                                        onToggle={() => toggle(link.routeName)}
                                                                        what={link.label}
                                                                        compact
                                                                    />
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            );
                        })}
                    </div>
                </FieldBlock>

                <FieldBlock
                    label="Página de inicio por rol"
                    error={errors['panel.home_by_role']}
                    hint="A dónde entra cada quien al ingresar. Si el rol no puede abrir esa página, entra a la primera de la lista que sí pueda."
                >
                    <div className="flex flex-col divide-y divide-[var(--line)] rounded-card border border-[var(--line)] bg-[var(--surface)]">
                        {roles.map((role) => (
                            <label key={role.value} className="flex items-center justify-between gap-3 px-3 py-2">
                                <span className="text-[0.8125rem] font-medium">{role.label}</span>
                                <select
                                    value={homes[role.value] ?? 'dashboard'}
                                    disabled={readOnly}
                                    onChange={(event) => set('panel.home_by_role', { ...homes, [role.value]: event.target.value })}
                                    className="h-9 w-48 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2.5 text-[0.8125rem] text-[var(--text)] focus:border-[var(--accent)] focus:outline-none"
                                >
                                    {homePages.map((page) => (
                                        <option key={page.value} value={page.value}>
                                            {page.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ))}
                    </div>
                </FieldBlock>

                {!readOnly && (
                    <SaveBar dirty={form.isDirty} processing={form.processing} onSave={() => save(route('settings.navigation.update'))} label="Guardar el menú" />
                )}
            </div>

            <div className="lg:sticky lg:top-20 lg:self-start">
                <PreviewFrame title="Vista previa · así queda el menú" note="Cada quien ve solo lo que sus permisos le dejan abrir.">
                    <MenuPreview overrides={overrides} />
                </PreviewFrame>
            </div>
        </div>
    );
}

interface RowProps {
    readOnly: boolean;
    icon?: ReactNode;
    onUp?: () => void;
    onDown?: () => void;
    value: string;
    placeholder: string;
    onRename: (label: string) => void;
    hidden: boolean;
    locked: boolean;
    onToggle: () => void;
    strong?: boolean;
    compact?: boolean;
    /** Como se nombra el elemento en los textos para lectores de pantalla. */
    what: string;
}

function Row({ readOnly, icon, onUp, onDown, value, placeholder, onRename, hidden, locked, onToggle, strong, compact, what }: RowProps) {
    const arrow = 'grid size-7 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]';

    return (
        <div className={cn('flex items-center gap-1.5', strong ? 'p-2' : '')}>
            {!compact && (
                <span className="flex shrink-0 items-center">
                    {!readOnly && onUp ? (
                        <button type="button" onClick={onUp} className={arrow} aria-label={`Subir ${what}`} title="Subir">
                            <ArrowUpIcon size={14} />
                        </button>
                    ) : (
                        <span className="size-7" />
                    )}
                    {!readOnly && onDown ? (
                        <button type="button" onClick={onDown} className={arrow} aria-label={`Bajar ${what}`} title="Bajar">
                            <ArrowDownIcon size={14} />
                        </button>
                    ) : (
                        <span className="size-7" />
                    )}
                </span>
            )}

            {icon && <span className="shrink-0 text-[var(--muted)]">{icon}</span>}

            <input
                type="text"
                value={value}
                placeholder={placeholder}
                maxLength={30}
                disabled={readOnly}
                aria-label={`Nombre de ${what}`}
                onChange={(event) => onRename(event.target.value)}
                className={cn(
                    'h-8 min-w-0 flex-1 rounded-chip border border-transparent bg-transparent px-2 text-[var(--text)] placeholder:text-[var(--text)]',
                    'hover:border-[var(--line)] focus:border-[var(--accent)] focus:bg-[var(--surface)] focus:outline-none',
                    strong ? 'text-[0.75rem] font-semibold tracking-wider uppercase' : 'text-[0.8125rem]',
                    value !== '' && 'font-semibold text-[var(--accent)]',
                )}
            />

            {hidden && <span className="shrink-0 rounded-full bg-[var(--surface-2)] px-2 py-0.5 text-[0.6875rem] font-medium text-[var(--muted)]">Oculto</span>}

            {locked ? (
                <span className="grid size-8 shrink-0 place-items-center text-[var(--muted)]" title="No se puede ocultar: aquí vive Ajustes">
                    <LockSimpleIcon size={15} />
                </span>
            ) : (
                !readOnly && (
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-label={hidden ? `Mostrar ${what}` : `Ocultar ${what}`}
                        title={hidden ? 'Mostrar' : 'Ocultar'}
                        className="grid size-8 shrink-0 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                    >
                        {hidden ? <EyeSlashIcon size={16} /> : <EyeIcon size={16} />}
                    </button>
                )
            )}
        </div>
    );
}

/** El menu resultante, sin enlaces: solo para ver el orden y los nombres. */
function MenuPreview({ overrides }: { overrides: NavigationOverrides }) {
    const sections = applyNavigationOverrides(COMPANY_NAVIGATION, overrides);

    return (
        <nav className="flex flex-col gap-3" inert aria-label="Vista previa del menú">
            {sections.map((section) => (
                <div key={section.key}>
                    <p className="px-2 pb-1 text-[0.65625rem] font-semibold tracking-wider text-[var(--muted)] uppercase">{section.title}</p>
                    <ul className="flex flex-col gap-0.5">
                        {section.entries.map((entry) => {
                            const Icon = entry.icon;

                            return (
                                <li key={entryKey(entry)}>
                                    <span className="flex h-8 items-center gap-2 rounded-chip px-2 text-[0.8125rem] font-medium text-[var(--text)]">
                                        <Icon size={16} className="text-[var(--muted)]" />
                                        {entry.label}
                                    </span>
                                    {isNavGroup(entry) && entry.links.length > 1 && (
                                        <ul className="ml-[1.0625rem] flex flex-col border-l border-[var(--line)] pl-2">
                                            {entry.links.map((link) => (
                                                <li key={link.routeName} className="py-0.5 text-[0.75rem] text-[var(--muted)]">
                                                    {link.label}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}
