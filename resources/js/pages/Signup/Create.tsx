import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, CheckCircleIcon, CheckIcon } from '@phosphor-icons/react';
import { type FormEvent, type ReactNode, useState } from 'react';
import { BrandStyle } from '@/components/brand/BrandStyle';
import type { LandingCycle, LandingPlan, LandingSite } from '@/components/landing/LandingBlocks';
import { landingIcon } from '@/components/landing/landing-icons';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input, Textarea } from '@/components/ui/Field';
import { useFlash } from '@/hooks/use-flash';
import { useTheme } from '@/hooks/use-theme';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';

interface SignupCreateProps {
    site: LandingSite;
    plans: LandingPlan[];
    cycles: LandingCycle[];
    selectedPlanId: number | null;
    selectedCycleId: number | null;
    gatewayEnabled: boolean;
    /** Las clasificaciones activas: alimentos, textiles, belleza… */
    categories: { id: number; name: string; icon: string }[];
}

type Mode = 'account' | 'contact';

/**
 * Contratar la plataforma. Quien llena el formulario elige: crear la cuenta ya (pagando en la
 * pasarela, o en prueba gratis si el plan la tiene) o que lo contacten.
 */
export default function SignupCreate({ site, plans, cycles, selectedPlanId, selectedCycleId, gatewayEnabled, categories }: SignupCreateProps) {
    useTheme('light');
    useFlash();

    const form = useForm({
        membership_plan_id: selectedPlanId ?? plans[0]?.id ?? 0,
        billing_cycle_id: selectedCycleId ?? cycles[0]?.id ?? 0,
        company_name: '',
        tax_id: '',
        city: '',
        business_category_id: null as number | null,
        company_phone: '',
        company_email: '',
        admin_name: '',
        admin_last_name: '',
        admin_email: '',
        admin_document: '',
        admin_password: '',
        admin_password_confirmation: '',
        estimated_stores: '',
        message: '',
        accept_terms: false as boolean,
        website: '',
    });
    const { data, setData, errors } = form;
    const plan = plans.find((item) => item.id === data.membership_plan_id) ?? plans[0];
    const cycle = cycles.find((item) => item.id === data.billing_cycle_id) ?? cycles[0];
    const trial = (plan?.trial_days ?? 0) > 0;
    // Crear la cuenta en el acto: con prueba gratis siempre; pagando, solo si hay pasarela.
    const canCreate = trial || gatewayEnabled;
    const [mode, setMode] = useState<Mode>(canCreate ? 'account' : 'contact');
    const [sent, setSent] = useState(false);
    const activeMode: Mode = canCreate ? mode : 'contact';

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (activeMode === 'account') {
            form.post(route('signup.store'), { preserveScroll: true });

            return;
        }

        form.transform((values) => ({
            source: 'signup',
            company_name: values.company_name,
            tax_id: values.tax_id,
            city: values.city,
            business_category_id: values.business_category_id,
            contact_name: `${values.admin_name} ${values.admin_last_name}`.trim(),
            email: values.admin_email || values.company_email,
            phone: values.company_phone,
            estimated_stores: values.estimated_stores,
            membership_plan_id: values.membership_plan_id,
            billing_cycle_id: values.billing_cycle_id,
            message: values.message,
            website: values.website,
        }));
        form.post(route('signup.contact'), { preserveScroll: true, onSuccess: () => setSent(true) });
    };

    const price = plan && cycle ? plan.prices[String(cycle.id)] ?? 0 : 0;

    return (
        <div className="min-h-screen bg-[var(--bg)] px-4 py-8 text-[var(--text)]">
            <Head title={`Contratar · ${site.name}`} />
            <BrandStyle />

            <div className="mx-auto w-full max-w-[64rem]">
                <Link href="/" className="mb-6 inline-flex items-center gap-1.5 text-[0.875rem] font-medium text-[var(--accent)] hover:underline">
                    <ArrowLeftIcon size={16} /> {site.name}
                </Link>
                <h1 className="text-[2rem] leading-tight">Contrata {site.name}</h1>
                <p className="mt-2 text-[var(--muted)]">Caja, inventario y tienda en línea para tu negocio.</p>

                {sent ? (
                    <div className="mt-8 flex items-start gap-3 rounded-card border border-[var(--accent)] bg-[var(--accent-soft)] p-6 text-[var(--accent)]">
                        <CheckCircleIcon size={28} weight="fill" className="shrink-0" />
                        <div>
                            <p className="text-[1.125rem] font-semibold">Recibimos tu solicitud.</p>
                            <p className="mt-1">Te contactamos en menos de un día hábil al correo que dejaste.</p>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={submit} className="mt-8 grid gap-6 lg:grid-cols-[1fr_22rem]">
                        <div className="flex flex-col gap-6">
                            <Panel title="1. Plan">
                                {cycles.length > 1 && (
                                    <div className="mb-4 flex flex-wrap gap-1 rounded-control bg-[var(--surface-2)] p-1">
                                        {cycles.map((item) => (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onClick={() => setData('billing_cycle_id', item.id)}
                                                className={cn(
                                                    'rounded-[0.5rem] px-3 py-1.5 text-[0.8125rem] font-semibold',
                                                    item.id === data.billing_cycle_id ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm' : 'text-[var(--muted)]',
                                                )}
                                            >
                                                {item.name}
                                                {item.discount_percent > 0 && <span className="ml-1 opacity-75">−{item.discount_percent} %</span>}
                                            </button>
                                        ))}
                                    </div>
                                )}
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    {plans.map((item) => (
                                        <button
                                            key={item.id}
                                            type="button"
                                            onClick={() => setData('membership_plan_id', item.id)}
                                            className={cn(
                                                'flex flex-col rounded-card border p-4 text-left transition-colors',
                                                item.id === data.membership_plan_id ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] bg-[var(--surface)] hover:border-[var(--line-2)]',
                                            )}
                                        >
                                            <span className="font-semibold">{item.name}</span>
                                            <span className="mt-1 font-mono text-[1.125rem] font-semibold tabular">{cycle ? money(item.prices[String(cycle.id)] ?? 0) : '—'}</span>
                                            {item.trial_days > 0 && <span className="mt-1 text-[0.75rem] font-semibold text-[var(--accent)]">{item.trial_days} días gratis</span>}
                                        </button>
                                    ))}
                                </div>
                                {errors.membership_plan_id && <p className="mt-2 text-[0.8125rem] font-medium text-[var(--danger)]">{errors.membership_plan_id}</p>}
                            </Panel>

                            <Panel title="2. Tu negocio">
                                <fieldset>
                                    <legend className="mb-2 text-[0.875rem] font-medium">
                                        ¿Qué tipo de comercio es?
                                        {activeMode === 'account' && <span className="ml-0.5 text-[var(--danger)]">*</span>}
                                    </legend>
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Tipo de comercio">
                                        {categories.map((category) => {
                                            const CategoryIcon = landingIcon(category.icon);
                                            const selected = data.business_category_id === category.id;

                                            return (
                                                <button
                                                    key={category.id}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={selected}
                                                    onClick={() => setData('business_category_id', category.id)}
                                                    className={cn(
                                                        'flex items-center gap-2 rounded-control border px-3 py-2.5 text-left text-[0.8125rem] font-medium transition-colors',
                                                        selected ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] bg-[var(--surface)] hover:border-[var(--line-2)]',
                                                    )}
                                                >
                                                    <CategoryIcon size={20} className="shrink-0" weight={selected ? 'fill' : 'regular'} />
                                                    <span className="leading-tight">{category.name}</span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {errors.business_category_id && <p className="mt-2 text-[0.8125rem] font-medium text-[var(--danger)]">{errors.business_category_id}</p>}
                                    <p className="mt-2 text-[0.78125rem] text-[var(--muted)]">Con esto te ubicamos en la página de inicio, junto a los comercios de tu tipo.</p>
                                </fieldset>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Input label="Nombre del negocio" required={activeMode === 'account'} value={data.company_name} onChange={(e) => setData('company_name', e.target.value)} error={errors.company_name} containerClassName="sm:col-span-2" />
                                    <Input label="NIT" required={activeMode === 'account'} value={data.tax_id} onChange={(e) => setData('tax_id', e.target.value)} error={errors.tax_id} />
                                    <Input label="Ciudad" required={activeMode === 'account'} value={data.city} onChange={(e) => setData('city', e.target.value)} error={errors.city} />
                                    <Input label="Teléfono" value={data.company_phone} onChange={(e) => setData('company_phone', e.target.value)} error={errors.company_phone} />
                                    <Input label="Correo del negocio" type="email" required={activeMode === 'account'} value={data.company_email} onChange={(e) => setData('company_email', e.target.value)} error={errors.company_email} />
                                    <Input label="¿Cuántas sedes tienes?" inputMode="numeric" value={data.estimated_stores} onChange={(e) => setData('estimated_stores', e.target.value.replace(/\D/g, ''))} error={errors.estimated_stores} />
                                </div>
                            </Panel>

                            <Panel title="3. Quien administra">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Input label="Nombre" required value={data.admin_name} onChange={(e) => setData('admin_name', e.target.value)} error={errors.admin_name} />
                                    <Input label="Apellido" value={data.admin_last_name} onChange={(e) => setData('admin_last_name', e.target.value)} error={errors.admin_last_name} />
                                    <Input label="Correo (con él entras)" type="email" required value={data.admin_email} onChange={(e) => setData('admin_email', e.target.value)} error={errors.admin_email} />
                                    <Input label="Documento" value={data.admin_document} onChange={(e) => setData('admin_document', e.target.value)} error={errors.admin_document} />
                                    {activeMode === 'account' && (
                                        <>
                                            <Input label="Contraseña" type="password" autoComplete="new-password" required value={data.admin_password} onChange={(e) => setData('admin_password', e.target.value)} error={errors.admin_password} />
                                            <Input label="Repite la contraseña" type="password" autoComplete="new-password" required value={data.admin_password_confirmation} onChange={(e) => setData('admin_password_confirmation', e.target.value)} />
                                        </>
                                    )}
                                </div>
                                <Textarea label="¿Algo que debamos saber?" value={data.message} onChange={(e) => setData('message', e.target.value)} rows={3} error={errors.message} />
                            </Panel>
                        </div>

                        <aside className="flex h-fit flex-col gap-4 rounded-card border border-[var(--line)] bg-[var(--surface)] p-5 lg:sticky lg:top-6">
                            <p className="text-[0.8125rem] font-semibold uppercase tracking-wide text-[var(--muted)]">Resumen</p>
                            {plan && cycle && (
                                <div>
                                    <p className="text-[1.125rem] font-semibold">
                                        {plan.name} · {cycle.name}
                                    </p>
                                    <p className="font-mono text-[1.75rem] font-semibold text-[var(--accent)] tabular">{money(price)}</p>
                                    <ul className="mt-2 flex flex-col gap-1 text-[0.875rem]">
                                        {plan.highlights.map((highlight) => (
                                            <li key={highlight} className="flex items-center gap-1.5">
                                                <CheckIcon size={14} weight="bold" className="text-[var(--accent)]" /> {highlight}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <div className="flex flex-col gap-2" role="radiogroup" aria-label="Cómo seguir">
                                {canCreate && (
                                    <ModeOption
                                        selected={activeMode === 'account'}
                                        onSelect={() => setMode('account')}
                                        title={trial ? `Empezar prueba gratis (${plan?.trial_days} días)` : 'Pagar ahora y activar mi cuenta'}
                                        hint={trial ? 'Tu cuenta queda lista ya; pagas cuando termine la prueba.' : 'Pagas en la página segura de la pasarela y entras de una.'}
                                    />
                                )}
                                <ModeOption selected={activeMode === 'contact'} onSelect={() => setMode('contact')} title="Prefiero que me contacten" hint="Te escribimos y te mostramos la plataforma." />
                            </div>

                            <Checkbox
                                checked={data.accept_terms}
                                onChange={(e) => setData('accept_terms', e.target.checked)}
                                label={
                                    <span>
                                        Acepto los{' '}
                                        <a href={site.terms_url} target="_blank" rel="noreferrer" className="font-medium text-[var(--accent)] underline">
                                            términos
                                        </a>{' '}
                                        y el{' '}
                                        <a href={site.privacy_url} target="_blank" rel="noreferrer" className="font-medium text-[var(--accent)] underline">
                                            tratamiento de datos
                                        </a>
                                        .
                                    </span>
                                }
                            />
                            {errors.accept_terms && <p className="text-[0.8125rem] font-medium text-[var(--danger)]">{errors.accept_terms}</p>}

                            <input type="text" name="website" tabIndex={-1} autoComplete="off" value={data.website} onChange={(e) => setData('website', e.target.value)} className="absolute left-[-9999px] h-px w-px opacity-0" aria-hidden />

                            <Button type="submit" loading={form.processing} disabled={activeMode === 'account' && !data.accept_terms}>
                                {activeMode === 'contact' ? 'Enviar solicitud' : trial ? 'Crear mi cuenta' : 'Ir a pagar'}
                            </Button>
                        </aside>
                    </form>
                )}
            </div>
        </div>
    );
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-3 rounded-card border border-[var(--line)] bg-[var(--surface)] p-5">
            <h2 className="text-[1.0625rem] font-semibold">{title}</h2>
            {children}
        </section>
    );
}

function ModeOption({ selected, onSelect, title, hint }: { selected: boolean; onSelect: () => void; title: string; hint: string }) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onSelect}
            className={cn('rounded-control border px-3 py-2.5 text-left', selected ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] hover:border-[var(--line-2)]')}
        >
            <span className={cn('block text-[0.875rem] font-semibold', selected && 'text-[var(--accent)]')}>{title}</span>
            <span className="text-[0.78125rem] text-[var(--muted)]">{hint}</span>
        </button>
    );
}
