import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import AuthLayout from '@/layouts/AuthLayout';

interface ChangePasswordProps {
    forced: boolean;
}

export default function ChangePassword({ forced }: ChangePasswordProps) {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('password.change.update'), {
            onFinish: () => reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title="Cambiar la contraseña"
            heading={forced ? 'Cambia tu contraseña temporal' : 'Cambiar mi contraseña'}
            subheading={
                forced
                    ? 'Por seguridad, define una contraseña propia antes de continuar'
                    : 'Escribe tu contraseña actual y la nueva'
            }
            footer={
                forced ? (
                    <Link href={route('logout')} method="post" as="button" className="font-medium text-[var(--muted)] hover:underline">
                        Cerrar sesión
                    </Link>
                ) : (
                    <Link href={route('dashboard')} className="font-medium text-[var(--accent)] hover:underline">
                        Volver al inicio
                    </Link>
                )
            }
        >
            <form onSubmit={submit} className="flex flex-col gap-4">
                <Input
                    label="Contraseña actual"
                    type="password"
                    autoFocus
                    required
                    autoComplete="current-password"
                    value={data.current_password}
                    onChange={(event) => setData('current_password', event.target.value)}
                    error={errors.current_password}
                />

                <Input
                    label="Contraseña nueva"
                    type="password"
                    required
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                    hint="Mínimo 8 caracteres"
                />

                <Input
                    label="Repite la contraseña nueva"
                    type="password"
                    required
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onChange={(event) => setData('password_confirmation', event.target.value)}
                    error={errors.password_confirmation}
                />

                <Button type="submit" size="lg" block loading={processing}>
                    Guardar
                </Button>
            </form>
        </AuthLayout>
    );
}
