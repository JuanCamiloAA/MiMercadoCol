import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import AuthLayout from '@/layouts/AuthLayout';

interface ForgotPasswordProps {
    status: string | null;
}

export default function ForgotPassword({ status }: ForgotPasswordProps) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('password.email'));
    };

    return (
        <AuthLayout
            title="Recuperar la contrasena"
            heading="Recuperar la contrasena"
            subheading="Te enviamos un enlace al correo de tu cuenta"
            footer={
                <Link href={route('login')} className="font-medium text-[var(--accent)] hover:underline">
                    Volver a entrar
                </Link>
            }
        >
            {status && (
                <div className="mb-4 rounded-control border border-[var(--accent)]/30 bg-[var(--accent-soft)] px-3 py-2 text-[0.8125rem] text-[var(--accent)]">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <Input
                    label="Correo electronico"
                    type="email"
                    autoFocus
                    required
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    error={errors.email}
                    placeholder="nombre@lacanasta.co"
                />

                <Button type="submit" size="lg" block loading={processing}>
                    Enviar el enlace
                </Button>
            </form>
        </AuthLayout>
    );
}
