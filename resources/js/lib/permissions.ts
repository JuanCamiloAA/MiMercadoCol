import { usePage } from '@inertiajs/react';
import type { AuthUser, PageProps } from '@/types';

export function useAuth(): AuthUser | null {
    return usePage<PageProps>().props.auth.user;
}

/**
 * Si no tiene el permiso, el boton no se pinta. Nunca se pinta deshabilitado: un boton que
 * no se puede usar solo genera preguntas.
 */
export function usePermission() {
    const user = useAuth();

    const can = (permission: string): boolean => {
        if (!user) {
            return false;
        }

        if (user.is_super_admin) {
            return true;
        }

        return user.permissions.includes(permission);
    };

    const canAny = (...permissions: string[]): boolean => permissions.some(can);

    const canPage = (page: string): boolean => {
        if (!user) {
            return false;
        }

        return user.is_super_admin || user.accessible_pages.includes(page);
    };

    return { can, canAny, canPage, user };
}

/**
 * Si el plan de la empresa activa incluye una funcion. Lo que el plan no incluye no se pinta; el
 * servidor igual lo rechaza (`EnsurePlanFeatures`).
 */
export function useFeature() {
    const company = usePage<PageProps>().props.activeCompany;

    return (feature: string): boolean => company?.features.includes(feature) ?? false;
}
