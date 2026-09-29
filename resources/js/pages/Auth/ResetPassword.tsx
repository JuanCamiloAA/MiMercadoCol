import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import AuthLayout from '@/layouts/AuthLayout';

interface ResetPasswordProps {
    email: string;
    token: string;
}

export default function ResetPassword({ email, token }: ResetPasswordProps) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('password.store'));
    };

    return (
        <AuthLayout title="Nueva contrasena" heading="Definir una contrasena nueva" subheading="Debe tener al menos 8 caracteres">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <Input
                    label="Correo electronico"
                    type="email"
                    required
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    error={errors.email}
                />

                <Input
                    label="Contrasena nueva"
                    type="password"
                    autoFocus
                    required
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                />

                <Input
                    label="Repite la contrasena"
                    type="password"
                    required
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onChange={(event) => setData('password_confirmation', event.target.value)}
                    error={errors.password_confirmation}
                />

                <Button type="submit" size="lg" block loading={processing}>
                    Guardar la contrasena
                </Button>
            </form>
        </AuthLayout>
    );
}
