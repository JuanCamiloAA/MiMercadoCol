import { router, usePage } from '@inertiajs/react';
import { CopyIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Tabs } from '@/components/ui/Misc';
import { usePermission } from '@/lib/permissions';
import type { PageProps } from '@/types';

export interface UserTabCounts {
    platform: number;
    companies: number;
}

type UserTab = 'platform' | 'companies';

const TAB_ROUTES: Record<UserTab, { route: string; permission: string; label: string }> = {
    platform: { route: 'platform.users.index', permission: 'platform.users.view', label: 'De plataforma' },
    companies: { route: 'platform.company_users.index', permission: 'platform.company_users.view', label: 'De empresas' },
};

export const ICON_BUTTON = 'grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]';

/**
 * Las dos pestañas de Plataforma > Usuarios. Son las mismas dos entradas del submenú lateral.
 */
export function UsersTabs({ active, counts }: { active: UserTab; counts: UserTabCounts }) {
    const { can } = usePermission();
    const tabs = (Object.keys(TAB_ROUTES) as UserTab[])
        .filter((tab) => can(TAB_ROUTES[tab].permission))
        .map((tab) => ({ value: tab, label: TAB_ROUTES[tab].label, count: counts[tab] }));

    return <Tabs className="mb-4" tabs={tabs} active={active} onChange={(tab) => tab !== active && router.get(route(TAB_ROUTES[tab].route))} />;
}

/**
 * La contraseña temporal llega una sola vez en el flash: se muestra con botón de copiar y después
 * ya no se puede volver a leer.
 */
export function TemporaryPasswordDialog() {
    const { flash } = usePage<PageProps>().props;
    const [password, setPassword] = useState<string | null>(null);

    useEffect(() => {
        if (flash?.temporary_password) {
            setPassword(flash.temporary_password);
        }
    }, [flash?.temporary_password]);

    const copy = async () => {
        if (!password) {
            return;
        }

        try {
            await navigator.clipboard.writeText(password);
            toast.success('Contraseña copiada.');
        } catch {
            toast.error('No se pudo copiar: selecciónala y cópiala a mano.');
        }
    };

    return (
        <Modal
            open={password !== null}
            onClose={() => setPassword(null)}
            size="sm"
            title="Contraseña temporal"
            description="Cópiala ahora: no se vuelve a mostrar. Entrégala por un medio seguro; al entrar tendrá que cambiarla."
            footer={<Button onClick={() => setPassword(null)}>Listo</Button>}
        >
            <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2.5 font-mono text-[1rem] tabular select-all">
                    {password}
                </code>
                <Button variant="secondary" onClick={copy} icon={<CopyIcon size={16} />}>
                    Copiar
                </Button>
            </div>
        </Modal>
    );
}
