import { useForm } from '@inertiajs/react';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';

interface UserFormProps {
    mode: 'create' | 'edit';
    user: {
        id: number;
        name: string;
        last_name: string | null;
        email: string;
        document: string | null;
        phone: string | null;
        job_title: string | null;
        role: string | null;
        store_ids: number[];
    } | null;
    roles: { value: string; label: string; color: string }[];
    stores: { value: number; label: string }[];
}

export default function UserForm({ mode, user, roles, stores }: UserFormProps) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: user?.name ?? '',
        last_name: user?.last_name ?? '',
        email: user?.email ?? '',
        document: user?.document ?? '',
        phone: user?.phone ?? '',
        job_title: user?.job_title ?? '',
        role: user?.role ?? (roles[0]?.value ?? ''),
        store_ids: user?.store_ids ?? [],
    });

    const toggleStore = (id: number) => {
        setData('store_ids', data.store_ids.includes(id) ? data.store_ids.filter((value) => value !== id) : [...data.store_ids, id]);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (mode === 'create') {
            post(route('users.store'));
        } else if (user) {
            put(route('users.update', user.id));
        }
    };

    return (
        <AppLayout title={mode === 'create' ? 'Nuevo usuario' : 'Editar usuario'}>
            <PageHeader
                title={mode === 'create' ? 'Nuevo usuario' : `Editar a ${user?.name}`}
                description={
                    mode === 'create'
                        ? 'Al crearla se genera una contrasena temporal que se muestra una sola vez.'
                        : 'Cambiar el rol reemplaza los permisos de la cuenta por los de la plantilla.'
                }
                actions={
                    <ButtonLink href={route('users.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Datos de la persona" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input
                            label="Nombre"
                            required
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            error={errors.name}
                        />
                        <Input
                            label="Apellido"
                            value={data.last_name}
                            onChange={(event) => setData('last_name', event.target.value)}
                            error={errors.last_name}
                        />
                        <Input
                            label="Correo electronico"
                            type="email"
                            required
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                            error={errors.email}
                            containerClassName="sm:col-span-2"
                        />
                        <Input
                            label="Documento"
                            value={data.document}
                            onChange={(event) => setData('document', event.target.value)}
                            error={errors.document}
                            hint="Sirve para entrar con documento en vez de correo."
                        />
                        <Input
                            label="Telefono"
                            value={data.phone}
                            onChange={(event) => setData('phone', event.target.value)}
                            error={errors.phone}
                        />
                        <Input
                            label="Cargo"
                            value={data.job_title}
                            onChange={(event) => setData('job_title', event.target.value)}
                            error={errors.job_title}
                            containerClassName="sm:col-span-2"
                        />
                    </div>
                </Card>

                <Card>
                    <CardHeader title="Acceso" description="El rol define los permisos iniciales; luego se pueden ajustar uno a uno." />

                    <div className="flex flex-col gap-4">
                        <Select
                            label="Rol"
                            required
                            options={roles.map((role) => ({ value: role.value, label: role.label }))}
                            value={data.role}
                            onChange={(event) => setData('role', event.target.value)}
                            error={errors.role}
                        />

                        <div className="flex flex-col gap-2">
                            <span className="text-[0.8125rem] font-medium text-[var(--text)]">Sedes donde puede operar</span>
                            <div className="flex flex-col gap-2 rounded-control border border-[var(--line)] p-3">
                                {stores.map((store) => (
                                    <Checkbox
                                        key={store.value}
                                        label={store.label}
                                        checked={data.store_ids.includes(store.value)}
                                        onChange={() => toggleStore(store.value)}
                                    />
                                ))}
                            </div>
                            {errors.store_ids && <p className="text-[0.75rem] text-[var(--danger)]">{errors.store_ids}</p>}
                        </div>

                        <Button type="submit" size="lg" loading={processing} className="self-start">
                            {mode === 'create' ? 'Crear el usuario' : 'Guardar cambios'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
