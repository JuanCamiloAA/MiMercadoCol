import { cn } from '@/lib/cn';
import { isNavGroup, type NavEntry, type NavGroup, type NavLink, type NavSection } from '@/lib/navigation';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { CaretDownIcon, CaretRightIcon } from '@phosphor-icons/react';
import { type ReactNode, useId, useState } from 'react';

/** Donde se recuerda qué quedó abierto. Es del navegador de quien mira, no de la cuenta. */
const STORAGE_KEY = 'lacanasta.sidebar';

type OpenState = Record<string, boolean>;

interface SidebarNavProps {
    sections: NavSection[];
    currentRoute: string;
    onNavigate: () => void;
    /** `icons`: la columna angosta de Ajustes > Apariencia, con los grupos en menus flotantes. */
    mode?: 'expanded' | 'icons';
}

const matches = (link: NavLink, currentRoute: string) => link.match.some((prefix) => currentRoute === prefix || currentRoute.startsWith(prefix));

const entryIsActive = (entry: NavEntry, currentRoute: string): boolean =>
    isNavGroup(entry) ? entry.links.some((link) => matches(link, currentRoute)) : matches(entry, currentRoute);

function readStored(): OpenState {
    try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}');

        return parsed !== null && typeof parsed === 'object' ? (parsed as OpenState) : {};
    } catch {
        // Ventana privada o almacenamiento bloqueado: el menú arranca con lo de siempre.
        return {};
    }
}

function writeStored(state: OpenState): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch {
        // Si no se puede guardar, el menú sigue funcionando; solo no lo recuerda.
    }
}

/**
 * El menú lateral: secciones y grupos que se pliegan.
 *
 * El layout se vuelve a montar en cada visita de Inertia, así que lo abierto se recuerda en
 * `localStorage`. Al llegar a una pantalla, su sección y su grupo se abren siempre: el menú
 * nunca esconde dónde está uno parado. Las secciones arrancan abiertas y los grupos cerrados.
 */
export function SidebarNav({ sections, currentRoute, onNavigate, mode = 'expanded' }: SidebarNavProps) {
    if (mode === 'icons') {
        return <IconNav sections={sections} currentRoute={currentRoute} onNavigate={onNavigate} />;
    }

    return <ExpandedNav sections={sections} currentRoute={currentRoute} onNavigate={onNavigate} />;
}

function ExpandedNav({ sections, currentRoute, onNavigate }: Omit<SidebarNavProps, 'mode'>) {
    const [open, setOpen] = useState<OpenState>(() => {
        const state = readStored();

        for (const section of sections) {
            const activeEntry = section.entries.find((entry) => entryIsActive(entry, currentRoute));

            if (activeEntry) {
                state[`section:${section.key}`] = true;

                if (isNavGroup(activeEntry)) {
                    state[`group:${activeEntry.key}`] = true;
                }
            }
        }

        return state;
    });

    const toggle = (key: string, fallback: boolean) =>
        setOpen((current) => {
            const next = { ...current, [key]: !(current[key] ?? fallback) };
            writeStored(next);

            return next;
        });

    return (
        <nav className="flex-1 overflow-y-auto px-3 py-3" aria-label="Menú principal">
            {sections.map((section) => {
                const key = `section:${section.key}`;
                const expanded = open[key] ?? true;

                return (
                    <Disclosure
                        key={section.key}
                        expanded={expanded}
                        className="mb-2 last:mb-0"
                        trigger={(props) => (
                            <button
                                type="button"
                                onClick={() => toggle(key, true)}
                                {...props}
                                className="flex h-8 w-full items-center justify-between rounded-chip px-2 text-[0.6875rem] font-semibold tracking-wider text-[var(--muted)] uppercase transition-colors hover:text-[var(--text)]"
                            >
                                {section.title}
                                <CaretDownIcon
                                    size={12}
                                    weight="bold"
                                    className={cn('transition-transform duration-200 motion-reduce:transition-none', !expanded && '-rotate-90')}
                                />
                            </button>
                        )}
                    >
                        <ul className="flex flex-col gap-0.5 pb-1">
                            {section.entries.map((entry) =>
                                isNavGroup(entry) ? (
                                    <GroupItem
                                        key={entry.key}
                                        group={entry}
                                        currentRoute={currentRoute}
                                        expanded={open[`group:${entry.key}`] ?? false}
                                        onToggle={() => toggle(`group:${entry.key}`, false)}
                                        onNavigate={onNavigate}
                                    />
                                ) : (
                                    <li key={entry.routeName}>
                                        <TopLink link={entry} active={matches(entry, currentRoute)} onNavigate={onNavigate} />
                                    </li>
                                ),
                            )}
                        </ul>
                    </Disclosure>
                );
            })}
        </nav>
    );
}

function TopLink({ link, active, onNavigate }: { link: NavLink; active: boolean; onNavigate: () => void }) {
    const LinkIcon = link.icon;

    return (
        <Link
            href={route(link.routeName)}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'flex h-9 items-center gap-2.5 rounded-control px-2.5 text-[0.84375rem] font-medium transition-colors',
                active ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]',
            )}
        >
            <LinkIcon size={18} weight={active ? 'fill' : 'regular'} />
            {link.label}
        </Link>
    );
}

interface GroupItemProps {
    group: NavGroup;
    currentRoute: string;
    expanded: boolean;
    onToggle: () => void;
    onNavigate: () => void;
}

function GroupItem({ group, currentRoute, expanded, onToggle, onNavigate }: GroupItemProps) {
    // Un desplegable con una sola opción es un clic de más: va como enlace suelto.
    if (group.links.length === 1) {
        const [only] = group.links;

        return (
            <li>
                <TopLink link={only} active={matches(only, currentRoute)} onNavigate={onNavigate} />
            </li>
        );
    }

    const GroupIcon = group.icon;
    const containsActive = group.links.some((link) => matches(link, currentRoute));

    return (
        <li>
            <Disclosure
                expanded={expanded}
                trigger={(props) => (
                    <button
                        type="button"
                        onClick={onToggle}
                        {...props}
                        className={cn(
                            'flex h-9 w-full items-center gap-2.5 rounded-control px-2.5 text-[0.84375rem] font-medium transition-colors hover:bg-[var(--surface-2)]',
                            containsActive ? 'text-[var(--text)]' : 'text-[var(--muted)] hover:text-[var(--text)]',
                        )}
                    >
                        <GroupIcon
                            size={18}
                            weight={containsActive ? 'fill' : 'regular'}
                            className={containsActive ? 'text-[var(--accent)]' : undefined}
                        />
                        <span className="flex-1 text-left">{group.label}</span>
                        <CaretRightIcon
                            size={13}
                            weight="bold"
                            className={cn(
                                'text-[var(--muted)] transition-transform duration-200 motion-reduce:transition-none',
                                expanded && 'rotate-90',
                            )}
                        />
                    </button>
                )}
            >
                <ul className="mt-0.5 ml-[1.1875rem] flex flex-col gap-0.5 border-l border-[var(--line)] pb-1 pl-2">
                    {group.links.map((link) => {
                        const active = matches(link, currentRoute);

                        return (
                            <li key={link.routeName}>
                                <Link
                                    href={route(link.routeName)}
                                    onClick={onNavigate}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'flex min-h-8 items-center rounded-chip px-2.5 py-1.5 text-[0.8125rem] leading-tight transition-colors',
                                        active
                                            ? 'bg-[var(--accent-soft)] font-semibold text-[var(--accent)]'
                                            : 'font-medium text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]',
                                    )}
                                >
                                    {link.label}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </Disclosure>
        </li>
    );
}

interface DisclosureProps {
    expanded: boolean;
    trigger: (props: { 'aria-expanded': boolean; 'aria-controls': string }) => ReactNode;
    children: ReactNode;
    className?: string;
}

/**
 * Un bloque que se pliega con animación de alto.
 *
 * Plegado queda `inert`: si no, el tabulador seguiría entrando a enlaces que no se ven.
 */
function Disclosure({ expanded, trigger, children, className }: DisclosureProps) {
    const id = useId();

    return (
        <div className={className}>
            {trigger({ 'aria-expanded': expanded, 'aria-controls': id })}
            <div
                id={id}
                inert={!expanded}
                className={cn(
                    'grid transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none',
                    expanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]',
                )}
            >
                <div className="min-h-0 overflow-hidden">{children}</div>
            </div>
        </div>
    );
}

/**
 * El menu solo con iconos.
 *
 * Cada seccion se separa con una raya en vez de su titulo. Un enlace suelto es un icono; un
 * grupo es un icono que abre un menu flotante con sus enlaces. Todo lleva `title` y
 * `aria-label`, porque sin el texto a la vista el nombre solo lo dan esos dos.
 */
function IconNav({ sections, currentRoute, onNavigate }: Omit<SidebarNavProps, 'mode'>) {
    return (
        <nav className="flex-1 overflow-y-auto px-2 py-3" aria-label="Menú principal">
            {sections.map((section, index) => (
                <ul
                    key={section.key}
                    aria-label={section.title}
                    className={cn('flex flex-col items-center gap-1', index > 0 && 'mt-2 border-t border-[var(--line)] pt-2')}
                >
                    {section.entries.map((entry) => {
                        if (isNavGroup(entry) && entry.links.length > 1) {
                            return (
                                <li key={entry.key}>
                                    <GroupFlyout group={entry} currentRoute={currentRoute} onNavigate={onNavigate} />
                                </li>
                            );
                        }

                        const link = isNavGroup(entry) ? entry.links[0] : entry;

                        return (
                            <li key={link.routeName}>
                                <IconLink link={link} active={matches(link, currentRoute)} onNavigate={onNavigate} />
                            </li>
                        );
                    })}
                </ul>
            ))}
        </nav>
    );
}

const ICON_ITEM = 'grid size-11 place-items-center rounded-control transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent)]';

function IconLink({ link, active, onNavigate }: { link: NavLink; active: boolean; onNavigate: () => void }) {
    const LinkIcon = link.icon;

    return (
        <Link
            href={route(link.routeName)}
            onClick={onNavigate}
            title={link.label}
            aria-label={link.label}
            aria-current={active ? 'page' : undefined}
            className={cn(
                ICON_ITEM,
                active ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]',
            )}
        >
            <LinkIcon size={20} weight={active ? 'fill' : 'regular'} />
        </Link>
    );
}

function GroupFlyout({ group, currentRoute, onNavigate }: { group: NavGroup; currentRoute: string; onNavigate: () => void }) {
    const GroupIcon = group.icon;
    const containsActive = group.links.some((link) => matches(link, currentRoute));

    return (
        <Popover>
            <PopoverButton
                title={group.label}
                aria-label={group.label}
                className={cn(
                    ICON_ITEM,
                    containsActive
                        ? 'bg-[var(--accent-soft)] text-[var(--accent)]'
                        : 'text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)] data-[open]:bg-[var(--surface-2)] data-[open]:text-[var(--text)]',
                )}
            >
                <GroupIcon size={20} weight={containsActive ? 'fill' : 'regular'} />
            </PopoverButton>

            <PopoverPanel
                anchor={{ to: 'right start', gap: 10 }}
                className="z-50 w-56 rounded-card border border-[var(--line)] bg-[var(--surface)] p-1.5 shadow-lg focus:outline-none"
            >
                {({ close }) => (
                    <>
                        <p className="px-2.5 pt-1 pb-1.5 text-[0.6875rem] font-semibold tracking-wider text-[var(--muted)] uppercase">{group.label}</p>
                        <ul className="flex flex-col gap-0.5">
                            {group.links.map((link) => {
                                const active = matches(link, currentRoute);

                                return (
                                    <li key={link.routeName}>
                                        <Link
                                            href={route(link.routeName)}
                                            onClick={() => {
                                                close();
                                                onNavigate();
                                            }}
                                            aria-current={active ? 'page' : undefined}
                                            className={cn(
                                                'flex min-h-9 items-center rounded-chip px-2.5 py-1.5 text-[0.8125rem] leading-tight transition-colors',
                                                active
                                                    ? 'bg-[var(--accent-soft)] font-semibold text-[var(--accent)]'
                                                    : 'font-medium text-[var(--text)] hover:bg-[var(--surface-2)]',
                                            )}
                                        >
                                            {link.label}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}
            </PopoverPanel>
        </Popover>
    );
}