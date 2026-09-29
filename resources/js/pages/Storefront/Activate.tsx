import { Link, router, useForm } from '@inertiajs/react';
import { EnvelopeSimpleIcon } from '@phosphor-icons/react';
import type { FormEvent } from 'react';
import StoreLayout from '@/layouts/StoreLayout';

interface ActivateProps {
    store: { id: number; name: string } | null;
    maskedEmail: string;
    expiresAt: string;
}

/**
 * Activar la cuenta en línea de un cliente que ya existía en la caja, con el código que le
 * llegó al correo de su ficha.
 */
export default function StorefrontActivate({ store, maskedEmail }: ActivateProps) {
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('storefront.register.activate'));
    };

    return (
        <StoreLayout title="Activar cuenta" current="account">
            <div className="mx-auto max-w-[420px] py-6 md:py-12">
                <span className="grid size-12 place-items-center rounded-full bg-[var(--accent-soft)] text-[var(--accent)]">
                    <EnvelopeSimpleIcon size={24} />
                </span>
                <h1 className="mt-4 text-[22px] leading-tight md:text-[26px]">Revisa tu correo</h1>
                <p className="mt-1 text-[13.5px] text-[var(--muted)]">
                    Ya eras cliente de {store?.name ? `la sede ${store.name}` : 'la tienda'}. Te enviamos un código de 6 números a{' '}
                    <strong className="text-[var(--text)]">{maskedEmail}</strong>, el correo de tu ficha. Vence en 30 minutos.
                </p>

                <form onSubmit={submit} className="mt-5 flex flex-col gap-4">
                    <div>
                        <label className="mb-1 block text-[12.5px] font-medium">Código</label>
                        <input
                            type="text"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            autoFocus
                            maxLength={6}
                            value={data.code}
                            onChange={(event) => setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))}
                            className="h-14 w-full rounded-[12px] border border-[var(--line)] bg-[var(--surface)] px-3 text-center text-[24px] tracking-[0.4em] tabular-nums focus:border-[var(--accent)] focus:outline-none"
                        />
                        {errors.code && <p className="mt-1 text-[12px] font-medium text-[var(--danger)]">{errors.code}</p>}
                    </div>

                    <button
                        type="submit"
                        disabled={processing || data.code.length !== 6}
                        className="flex h-12 w-full items-center justify-center rounded-[13px] bg-[var(--accent)] text-[15px] font-semibold text-[var(--accent-ink)] disabled:opacity-60"
                    >
                        Activar mi cuenta
                    </button>
                </form>

                <p className="mt-5 text-center text-[12.5px] text-[var(--muted)]">
                    ¿No te llegó?{' '}
                    <button type="button" onClick={() => router.post(route('storefront.register.resend'))} className="font-semibold text-[var(--accent)]">
                        Enviar otro código
                    </button>
                </p>
                <p className="mt-2 text-center text-[12.5px] text-[var(--muted)]">
                    ¿Ese correo ya no es tuyo? Acércate a la sede para actualizarlo.{' '}
                    <Link href={route('storefront.home')} className="font-medium text-[var(--accent)]">
                        Volver a la tienda
                    </Link>
                </p>
            </div>
        </StoreLayout>
    );
}
