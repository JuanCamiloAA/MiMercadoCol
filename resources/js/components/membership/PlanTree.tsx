import { Link } from '@inertiajs/react';
import { InfoIcon } from '@phosphor-icons/react';
import { useMemo } from 'react';
import { type PermissionAnnotation, PermissionMatrix } from '@/components/PermissionMatrix';
import { date } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { PermissionCatalogueModule } from '@/types';

/** De donde sale cada accion de lo que tiene una empresa (`Entitlement::tree()`). */
export type EntitlementSource = 'plan' | 'no_plan' | 'grant' | 'revoke' | 'always' | 'none';

export interface EntitlementAction {
    name: string;
    label: string;
    allowed: boolean;
    source: EntitlementSource;
    ends_at: string | null;
}

export interface EntitlementTreeModule {
    key: string;
    node: string;
    display: string;
    order: number;
    pages: { key: string; node: string; display: string; actions: EntitlementAction[] }[];
}

/** El arbol del servidor con el formato del asignador, para pintarlo con la misma matriz. */
export function treeToCatalogue(tree: EntitlementTreeModule[]): PermissionCatalogueModule[] {
    return tree.map((module) => ({
        key: module.key,
        display: module.display,
        icon: null,
        order: module.order,
        super_admin_only: false,
        total: module.pages.reduce((sum, page) => sum + page.actions.length, 0),
        groups: module.pages.map((page) => ({
            key: page.key,
            display: page.display,
            permissions: page.actions.map((action) => ({ name: action.name, label: action.label })),
        })),
    }));
}

export function sourceAnnotation(action: EntitlementAction): PermissionAnnotation | null {
    switch (action.source) {
        case 'plan':
            return { label: 'Del plan', tone: 'neutral' };
        case 'grant':
            return { label: action.ends_at ? `Agregado hasta ${date(action.ends_at, 'd MMM')}` : 'Agregado', tone: 'accent' };
        case 'revoke':
            return { label: action.ends_at ? `Quitado hasta ${date(action.ends_at, 'd MMM')}` : 'Quitado', tone: 'danger' };
        case 'always':
            return { label: 'Siempre incluido', tone: 'info' };
        default:
            return null;
    }
}

/**
 * Lo que tiene una empresa, en solo lectura: el mismo arbol del editor de planes con el origen
 * de cada accion. Lo que no tiene se ve sin marcar, para que se sepa que existe.
 */
export function EntitlementTree({ tree, onlyAllowed = false }: { tree: EntitlementTreeModule[]; onlyAllowed?: boolean }) {
    const { modules, selected, annotations } = useMemo(() => {
        const source = onlyAllowed
            ? tree
                  .map((module) => ({
                      ...module,
                      pages: module.pages
                          .map((page) => ({ ...page, actions: page.actions.filter((action) => action.allowed || action.source === 'revoke') }))
                          .filter((page) => page.actions.length > 0),
                  }))
                  .filter((module) => module.pages.length > 0)
            : tree;
        const actions = source.flatMap((module) => module.pages.flatMap((page) => page.actions));
        const notes: Record<string, PermissionAnnotation> = {};

        actions.forEach((action) => {
            const note = sourceAnnotation(action);

            if (note) {
                notes[action.name] = note;
            }
        });

        return {
            modules: treeToCatalogue(source),
            selected: actions.filter((action) => action.allowed).map((action) => action.name),
            annotations: notes,
        };
    }, [tree, onlyAllowed]);

    return <PermissionMatrix modules={modules} selected={selected} readOnly annotations={annotations} />;
}

/**
 * La nota al pie del asignador de Roles y Usuarios: cuantas funciones no trae el plan, con el
 * enlace a la membresia solo si la persona puede verla.
 */
export function PlanHiddenNote({ hidden }: { hidden: number }) {
    const { can } = usePermission();

    if (hidden <= 0) {
        return null;
    }

    return (
        <p className="flex flex-wrap items-center gap-1.5 rounded-control border border-dashed border-[var(--line-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">
            <InfoIcon size={15} className="shrink-0" />
            {hidden} {hidden === 1 ? 'función no está' : 'funciones no están'} en tu plan.
            {can('settings.membership.view') && (
                <Link href={route('settings.index', { pestana: 'membresia' })} className="font-medium text-[var(--accent)] hover:underline">
                    Ver Ajustes &gt; Membresía
                </Link>
            )}
        </p>
    );
}
