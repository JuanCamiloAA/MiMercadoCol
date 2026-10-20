import { useForm, usePage } from '@inertiajs/react';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import type { FormEvent } from 'react';
import { PlanHiddenNote } from '@/components/membership/PlanTree';
import { PermissionMatrix } from '@/components/PermissionMatrix';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Textarea } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import type { PageProps } from '@/types';

interface RoleData {
    id: number;
    name: string;
    display_name: string;
    description: string | null;
    color: string;
    is_system: boolean;
}

interface RoleFormProps {
    mode: 'create' | 'edit';
    role: RoleData | null;
    rolePermissions: string[];
}

const COLORS = ['#0E6E4E', '#1D6FA5', '#8F4705', '#B3261E', '#565E64', '#5B3A9B'];

export default function RoleForm({ mode, role, rolePermissions }: RoleFormProps) {
    const { permissionMatrix, permissionMatrixHidden } = usePage<PageProps>().props;

    const { data, setData, post, put, processing, errors } = useForm({
        display_name: role?.display_name ?? '',
        name: role?.name ?? '',
        description: role?.description ?? '',
        color: role?.color ?? COLORS[0],
        permissions: rolePermissions,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (mode === 'create') {
            post(route('roles.store'));
        } else if (role) {
            put(route('roles.update', role.id));
        }
    };

    return (
        <AppLayout title={mode === 'create' ? 'Nuevo rol' : `Editar ${role?.display_name}`}>
            <PageHeader
                title={mode === 'create' ? 'Nuevo rol' : `Editar «${role?.display_name}»`}
                description="El rol es una plantilla: al guardar decides a quien le aplicas la diferencia."
                actions={
                    <ButtonLink href={route('roles.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-[320px_1fr]">
                <Card className="h-fit">
                    <CardHeader title="Identificacion" />

                    <div className="flex flex-col gap-4">
                        <Input
                            label="Nombre visible"
                            required
                            value={data.display_name}
                            onChange={(event) => setData('display_name', event.target.value)}
                            error={errors.display_name}
                            placeholder="Supervisor de tienda"
                        />

                        {mode === 'create' && (
                            <Input
                                label="Identificador tecnico"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                error={errors.name}
                                hint="Se genera del nombre visible si lo dejas vacio."
                                placeholder="supervisor_tienda"
                            />
                        )}

                        <Textarea
                            label="Descripcion"
                            value={data.description}
                            onChange={(event) => setData('description', event.target.value)}
                            error={errors.description}
                            placeholder="Que hace quien tiene este rol"
                        />

                        <div className="flex flex-col gap-1.5">
                            <span className="text-[0.8125rem] font-medium text-[var(--text)]">Color</span>
                            <div className="flex flex-wrap gap-2">
                                {COLORS.map((color) => (
                                    <button
                                        key={color}
                                        type="button"
                                        onClick={() => setData('color', color)}
                                        aria-label={`Color ${color}`}
                                        className={cn(
                                            'size-8 rounded-control border-2 transition-transform',
                                            data.color === color ? 'scale-110 border-[var(--text)]' : 'border-transparent',
                                        )}
                                        style={{ backgroundColor: color }}
                                    />
                                ))}
                            </div>
                            {errors.color && <p className="text-[0.75rem] text-[var(--danger)]">{errors.color}</p>}
                        </div>
                    </div>
                </Card>

                <Card>
                    <CardHeader
                        title="Permisos de la plantilla"
                        description="Marca lo que este rol deberia poder hacer."
                        action={
                            <Button type="submit" loading={processing}>
                                {mode === 'create' ? 'Crear el rol' : 'Guardar cambios'}
                            </Button>
                        }
                    />

                    {permissionMatrix ? (
                        <PermissionMatrix
                            modules={permissionMatrix}
                            selected={data.permissions}
                            onChange={(permissions) => setData('permissions', permissions)}
                            baseline={rolePermissions}
                            footer={<PlanHiddenNote hidden={permissionMatrixHidden ?? 0} />}
                        />
                    ) : (
                        <p className="text-[0.8125rem] text-[var(--muted)]">No se pudo cargar el catalogo de permisos.</p>
                    )}
                </Card>
            </form>
        </AppLayout>
    );
}
