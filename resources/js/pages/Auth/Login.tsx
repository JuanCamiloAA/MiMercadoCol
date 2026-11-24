import { Link, useForm, usePage } from '@inertiajs/react';
import { EyeIcon, EyeSlashIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input } from '@/components/ui/Field';
import AuthLayout from '@/layouts/AuthLayout';
import { cn } from '@/lib/cn';
import type { PageProps } from '@/types';

type LoginMode = 'email' | 'document';

interface LoginProps {
    canResetPassword: boolean;
    status: string | null;
}

export default function Login({ canResetPassword, status }: LoginProps) {
    const [showPassword, setShowPassword] = useState(false);
    const brandName = usePage<PageProps>().props.brand?.display_name ?? 'La Canasta';

    const { data, setData, post, processing, errors, reset } = useForm({
        login_mode: 'email' as LoginMode,
        email: '',
        document: '',
        password: '',
        remember: false as boolean,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    const switchMode = (mode: LoginMode) => {
        setData((current) => ({ ...current, login_mode: mode }));
    };

    return (
        <AuthLayout
            title="Entrar"
            heading={`Entrar a ${brandName}`}
            subheading="Usa tu correo o tu documento para ingresar"
            footer={
                canResetPassword ? (
                    <Link href={route('password.request')} className="font-medium text-[var(--accent)] hover:underline">
                        Olvidé mi contraseña
                    </Link>
                ) : undefined
            }
        >
            {status && (
                <div className="mb-4 rounded-control border border-[var(--accent)]/30 bg-[var(--accent-soft)] px-3 py-2 text-[0.8125rem] text-[var(--accent)]">
                    {status}
                </div>
            )}

            <div className="mb-4 grid grid-cols-2 gap-1 rounded-control bg-[var(--surface-2)] p-1">
                {(
                    [
                        { value: 'email', label: 'Correo' },
                        { value: 'document', label: 'Documento' },
                    ] as { value: LoginMode; label: string }[]
                ).map((tab) => (
                    <button
                        key={tab.value}
                        type="button"
                        onClick={() => switchMode(tab.value)}
                        className={cn(
                            'h-9 rounded-chip text-[0.8125rem] font-medium transition-colors',
                            data.login_mode === tab.value
                                ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm'
                                : 'text-[var(--muted)] hover:text-[var(--text)]',
                        )}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            <form onSubmit={submit} className="flex flex-col gap-4">
                {data.login_mode === 'email' ? (
                    <Input
                        label="Correo electrónico"
                        type="email"
                        autoComplete="username"
                        autoFocus
                        required
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        error={errors.email}
                        placeholder="nombre@lacanasta.co"
                    />
                ) : (
                    <Input
                        label="Número de documento"
                        type="text"
                        inputMode="numeric"
                        autoComplete="username"
                        autoFocus
                        required
                        value={data.document}
                        onChange={(event) => setData('document', event.target.value)}
                        error={errors.document}
                        placeholder="1020304050"
                    />
                )}

                <Input
                    label="Contraseña"
                    type={showPassword ? 'text' : 'password'}
                    autoComplete="current-password"
                    required
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                    suffix={
                        <button
                            type="button"
                            onClick={() => setShowPassword((visible) => !visible)}
                            aria-label={showPassword ? 'Ocultar la contraseña' : 'Mostrar la contraseña'}
                            className="pointer-events-auto text-[var(--muted)] transition-colors hover:text-[var(--text)]"
                        >
                            {showPassword ? <EyeSlashIcon size={18} /> : <EyeIcon size={18} />}
                        </button>
                    }
                />

                <Checkbox
                    label="Recordarme en este equipo"
                    checked={data.remember}
                    onChange={(event) => setData('remember', event.target.checked)}
                />

                <Button type="submit" size="lg" block loading={processing}>
                    Entrar
                </Button>
            </form>
        </AuthLayout>
    );
}
