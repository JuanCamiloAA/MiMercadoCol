import { Head, Link, router } from '@inertiajs/react';
import { CheckCircleIcon, CircleNotchIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { BrandStyle } from '@/components/brand/BrandStyle';
import type { LandingSite } from '@/components/landing/LandingBlocks';
import { useTheme } from '@/hooks/use-theme';
import { money } from '@/lib/format';

interface SignupState {
    reference: string;
    status: 'pending' | 'paid' | 'failed' | 'expired';
    ready: boolean;
    can_enter: boolean;
    company_name: string;
    amount: number;
    failure_reason: string | null;
}

/**
 * La vuelta del checkout. Consulta cada pocos segundos hasta que el pago se confirma y la cuenta
 * queda creada; en el navegador que contrató, entra solo.
 */
export default function SignupStatus({ site, signup: initial }: { site: LandingSite; signup: SignupState }) {
    useTheme('light');
    const [signup, setSignup] = useState(initial);

    useEffect(() => {
        if (signup.status !== 'pending' && !(signup.status === 'paid' && !signup.ready)) {
            return;
        }

        const timer = window.setInterval(() => {
            fetch(route('signup.status', signup.reference), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : null))
                .then((next: SignupState | null) => next && setSignup(next))
                .catch(() => undefined);
        }, 4000);

        return () => window.clearInterval(timer);
    }, [signup.reference, signup.status, signup.ready]);

    // Lista y en el navegador que contrató: entra sola.
    useEffect(() => {
        if (signup.ready && signup.can_enter) {
            router.get(route('signup.status', signup.reference), { entrar: 1 });
        }
    }, [signup.ready, signup.can_enter, signup.reference]);

    return (
        <div className="flex min-h-screen items-center justify-center bg-[var(--bg)] px-4 text-[var(--text)]">
            <Head title={`Tu contratación · ${site.name}`} />
            <BrandStyle />

            <div className="w-full max-w-[30rem] rounded-card border border-[var(--line)] bg-[var(--surface)] p-7 text-center">
                {signup.ready ? (
                    <>
                        <CheckCircleIcon size={44} weight="fill" className="mx-auto text-[var(--accent)]" />
                        <h1 className="mt-4 text-[1.375rem]">¡{signup.company_name} ya está lista!</h1>
                        <p className="mt-2 text-[var(--muted)]">
                            {signup.can_enter ? 'Te estamos llevando a tu panel…' : 'Entra con el correo y la contraseña que elegiste.'}
                        </p>
                        {!signup.can_enter && (
                            <a href={site.login_url} className="mt-5 inline-flex h-11 items-center rounded-control bg-[var(--accent)] px-5 font-semibold text-[var(--accent-ink)]">
                                Ingresar
                            </a>
                        )}
                    </>
                ) : signup.status === 'failed' || signup.status === 'expired' ? (
                    <>
                        <WarningCircleIcon size={44} className="mx-auto text-[var(--danger)]" />
                        <h1 className="mt-4 text-[1.375rem]">{signup.status === 'expired' ? 'La contratación venció' : 'El pago no pasó'}</h1>
                        <p className="mt-2 text-[var(--muted)]">{signup.failure_reason ?? 'No se hizo ningún cobro. Puedes intentarlo de nuevo.'}</p>
                        <Link href={site.signup_url} className="mt-5 inline-flex h-11 items-center rounded-control bg-[var(--accent)] px-5 font-semibold text-[var(--accent-ink)]">
                            Volver a intentar
                        </Link>
                    </>
                ) : (
                    <>
                        <CircleNotchIcon size={44} className="mx-auto animate-spin text-[var(--accent)]" />
                        <h1 className="mt-4 text-[1.375rem]">Estamos confirmando tu pago</h1>
                        <p className="mt-2 text-[var(--muted)]">
                            {money(signup.amount)} · referencia <span className="font-mono">{signup.reference}</span>
                        </p>
                        <p className="mt-2 text-[0.875rem] text-[var(--muted)]">Con PSE o Nequi puede tardar unos minutos. No cierres esta página.</p>
                    </>
                )}
            </div>
        </div>
    );
}
