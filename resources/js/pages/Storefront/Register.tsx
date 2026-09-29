import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { openLogin } from '@/components/storefront/login-dialog';
import StoreLayout from '@/layouts/StoreLayout';

interface RegisterProps {
    store: { id: number; name: string } | null;
    documentTypes: string[];
    termsUrl: string;
    privacyUrl: string;
}

/**
 * Registro de cliente en la tienda de la sede elegida. Si el documento ya es cliente de la caja,
 * el servidor manda un código al correo de su ficha antes de activar la cuenta.
 */
export default function StorefrontRegister({ store, documentTypes, termsUrl, privacyUrl }: RegisterProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        document_type: documentTypes[0] ?? 'CC',
        document_number: '',
        name: '',
        email: '',
        phone: '',
        address: '',
        neighborhood: '',
        password: '',
        password_confirmation: '',
        accept_terms: false as boolean,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('storefront.register.store'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    const alreadyHasAccount = errors.document_number?.startsWith('Ya tienes cuenta');

    return (
        <StoreLayout title="Crear cuenta" current="account">
            <div className="mx-auto max-w-[520px] py-6 md:py-10">
                <h1 className="text-[22px] leading-tight md:text-[26px]">Crea tu cuenta</h1>
                <p className="mt-1 text-[13.5px] text-[var(--muted)]">Para comprar en {store?.name ? `la sede ${store.name}` : 'esta tienda'}. Tu cuenta es con este mercado.</p>

                <form onSubmit={submit} className="mt-5 grid gap-4 sm:grid-cols-2">
                    <Field label="Tipo de documento">
                        <select
                            value={data.document_type}
                            onChange={(event) => setData('document_type', event.target.value)}
                            className="h-12 w-full rounded-[12px] border border-[var(--line)] bg-[var(--surface)] px-3 text-[15px] focus:border-[var(--accent)] focus:outline-none"
                        >
                            {documentTypes.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Número de documento" error={errors.document_number}>
                        <TextInput value={data.document_number} onChange={(value) => setData('document_number', value)} inputMode="numeric" mono />
                    </Field>
                    {alreadyHasAccount && (
                        <button
                            type="button"
                            onClick={() => openLogin({ document: data.document_number })}
                            aria-haspopup="dialog"
                            className="text-left text-[13px] font-semibold text-[var(--accent)] sm:col-span-2"
                        >
                            Ir a ingresar →
                        </button>
                    )}
                    <Field label="Nombre completo" error={errors.name} wide>
                        <TextInput value={data.name} onChange={(value) => setData('name', value)} autoComplete="name" />
                    </Field>
                    <Field label="Correo" error={errors.email}>
                        <TextInput type="email" value={data.email} onChange={(value) => setData('email', value)} autoComplete="email" />
                    </Field>
                    <Field label="Celular" error={errors.phone}>
                        <TextInput type="tel" value={data.phone} onChange={(value) => setData('phone', value)} autoComplete="tel" />
                    </Field>
                    <Field label="Dirección" error={errors.address}>
                        <TextInput value={data.address} onChange={(value) => setData('address', value)} autoComplete="street-address" />
                    </Field>
                    <Field label="Barrio" error={errors.neighborhood}>
                        <TextInput value={data.neighborhood} onChange={(value) => setData('neighborhood', value)} />
                    </Field>
                    <Field label="Contraseña" error={errors.password}>
                        <TextInput type="password" value={data.password} onChange={(value) => setData('password', value)} autoComplete="new-password" />
                    </Field>
                    <Field label="Repite la contraseña">
                        <TextInput type="password" value={data.password_confirmation} onChange={(value) => setData('password_confirmation', value)} autoComplete="new-password" />
                    </Field>

                    <label className="flex items-start gap-2.5 text-[13px] sm:col-span-2">
                        <input
                            type="checkbox"
                            checked={data.accept_terms}
                            onChange={(event) => setData('accept_terms', event.target.checked)}
                            className="mt-0.5 size-[18px] shrink-0 accent-[var(--accent)]"
                        />
                        <span>
                            Acepto los{' '}
                            <a href={termsUrl} target="_blank" rel="noreferrer" className="font-medium text-[var(--accent)] underline">
                                términos
                            </a>{' '}
                            y autorizo el{' '}
                            <a href={privacyUrl} target="_blank" rel="noreferrer" className="font-medium text-[var(--accent)] underline">
                                tratamiento de mis datos personales
                            </a>{' '}
                            (Ley 1581 de 2012).
                        </span>
                    </label>
                    {errors.accept_terms && <p className="-mt-2 text-[12px] font-medium text-[var(--danger)] sm:col-span-2">{errors.accept_terms}</p>}

                    <button
                        type="submit"
                        disabled={processing}
                        className="flex h-12 w-full items-center justify-center rounded-[13px] bg-[var(--accent)] text-[15px] font-semibold text-[var(--accent-ink)] disabled:opacity-60 sm:col-span-2"
                    >
                        Crear mi cuenta
                    </button>
                </form>

                <p className="mt-5 text-center text-[12.5px] text-[var(--muted)]">
                    ¿Ya tienes cuenta?{' '}
                    <button type="button" onClick={() => openLogin()} aria-haspopup="dialog" className="font-semibold text-[var(--accent)]">
                        Ingresa
                    </button>
                </p>
            </div>
        </StoreLayout>
    );
}

function Field({ label, error, wide, children }: { label: string; error?: string; wide?: boolean; children: ReactNode }) {
    return (
        <div className={wide ? 'sm:col-span-2' : undefined}>
            <label className="mb-1 block text-[12.5px] font-medium">{label}</label>
            {children}
            {error && <p className="mt-1 text-[12px] font-medium text-[var(--danger)]">{error}</p>}
        </div>
    );
}

function TextInput({
    value,
    onChange,
    type = 'text',
    inputMode,
    autoComplete,
    mono,
}: {
    value: string;
    onChange: (value: string) => void;
    type?: string;
    inputMode?: 'numeric' | 'text';
    autoComplete?: string;
    mono?: boolean;
}) {
    return (
        <input
            type={type}
            value={value}
            inputMode={inputMode}
            autoComplete={autoComplete}
            onChange={(event) => onChange(event.target.value)}
            className={`h-12 w-full rounded-[12px] border border-[var(--line)] bg-[var(--surface)] px-3 text-[15px] focus:border-[var(--accent)] focus:outline-none ${mono ? 'tabular-nums' : ''}`}
        />
    );
}
