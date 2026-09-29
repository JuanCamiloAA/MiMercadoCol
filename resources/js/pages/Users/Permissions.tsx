import { useForm, usePage } from '@inertiajs/react';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import type { FormEvent } from 'react';
import { PermissionMatrix } from '@/components/PermissionMatrix';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Badge, PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import type { PageProps } from '@/types';

interface UserPermissionsProps {
    user: {
        id: number;
        name: string;
        email: string;
        role: { name: string; display_name: string; color: string } | null;
    };
    userPermissions: string[];
}

export default function UserPermissions({ user, userPermissions }: UserPermissionsProps) {
    const { permissionMatrix } = usePage<PageProps>().props;

    const { data, setData, put, processing } = useForm({ permissions: userPermissions });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('users.permissions.update', user.id));
    };

    return (
        <AppLayout title={`Permisos de ${user.name}`}>
            <PageHeader
                title={`Permisos de ${user.name}`}
                description="Lo que puede hacer esta persona exactamente. El rol solo fue el punto de partida."
                actions={
                    <ButtonLink href={route('users.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            <form onSubmit={submit}>
                <Card>
                    <CardHeader
                        title={
                            <span className="flex flex-wrap items-center gap-2">
                                {user.email}
                                {user.role && (
                                    <Badge dotColor={user.role.color} tone="neutral">
                                        {user.role.display_name}
                                    </Badge>
                                )}
                            </span>
                        }
                        description="Marca cada accion que deba poder ejecutar."
                        action={
                            <Button type="submit" loading={processing}>
                                Guardar permisos
                            </Button>
                        }
                    />

                    {permissionMatrix ? (
                        <PermissionMatrix
                            modules={permissionMatrix}
                            selected={data.permissions}
                            onChange={(permissions) => setData('permissions', permissions)}
                            baseline={userPermissions}
                        />
                    ) : (
                        <p className="text-[0.8125rem] text-[var(--muted)]">No se pudo cargar el catalogo de permisos.</p>
                    )}
                </Card>
            </form>
        </AppLayout>
    );
}
