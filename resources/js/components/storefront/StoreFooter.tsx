import {
    BankIcon,
    CreditCardIcon,
    DeviceMobileIcon,
    EnvelopeIcon,
    FacebookLogoIcon,
    InstagramLogoIcon,
    MapPinIcon,
    MoneyIcon,
    PhoneIcon,
    ReceiptIcon,
    TiktokLogoIcon,
    WhatsappLogoIcon,
    XLogoIcon,
    YoutubeLogoIcon,
    type Icon,
} from '@phosphor-icons/react';
import { type CSSProperties, type FormEvent, useState } from 'react';
import { ThemeToggle } from '@/components/ui/ThemeToggle';
import { cn } from '@/lib/cn';
import { csrfHeaders, errorMessage } from '@/lib/http';
import type { StoreShell } from '@/types/storefront';
import { StoreLink, useHasExternalImages, usePreviewMode } from './store-context';

const SOCIAL: Record<string, Icon> = {
    facebook: FacebookLogoIcon,
    instagram: InstagramLogoIcon,
    tiktok: TiktokLogoIcon,
    youtube: YoutubeLogoIcon,
    x: XLogoIcon,
};

const PAYMENTS: Record<string, Icon> = {
    cash: MoneyIcon,
    card: CreditCardIcon,
    transfer: BankIcon,
    nequi: DeviceMobileIcon,
    daviplata: DeviceMobileIcon,
    pse: BankIcon,
    credit: ReceiptIcon,
};

/**
 * El pie de la tienda: columnas de enlaces, contacto, redes, medios de pago y textos.
 *
 * Abajo va siempre la franja legal, y su contenido no se configura: quien vende se identifica
 * con los datos de la empresa (razón social, NIT, dirección, teléfono y correo) y enlaza sus
 * términos, su política de datos y sus PQRS, como pide el comercio electrónico en Colombia.
 */
/**
 * El color del pie. Oscuro o de la marca cambian el texto, las líneas y las superficies de adentro
 * con variables (como el fondo de una sección): un tema anidado le pondría encima el de fábrica.
 */
const TONES: Record<string, { className: string; style?: CSSProperties }> = {
    light: { className: 'border-t border-[var(--line)] bg-[var(--surface)] text-[var(--text)]' },
    dark: {
        className: 'bg-[#14171A] text-white',
        style: {
            '--text': '#FFFFFF',
            '--muted': 'rgba(255, 255, 255, 0.72)',
            '--line': 'rgba(255, 255, 255, 0.16)',
            '--line-2': 'rgba(255, 255, 255, 0.26)',
            '--surface': 'rgba(255, 255, 255, 0.06)',
            '--surface-2': 'rgba(255, 255, 255, 0.1)',
            '--accent': 'color-mix(in srgb, var(--accent) 55%, white)',
        } as CSSProperties,
    },
    brand: {
        className: 'bg-[var(--accent)] text-[var(--accent-ink)]',
        style: {
            '--text': 'var(--accent-ink)',
            '--muted': 'color-mix(in srgb, var(--accent-ink) 76%, transparent)',
            '--line': 'color-mix(in srgb, var(--accent-ink) 22%, transparent)',
            '--line-2': 'color-mix(in srgb, var(--accent-ink) 32%, transparent)',
            '--surface': 'color-mix(in srgb, var(--accent-ink) 8%, transparent)',
            '--surface-2': 'color-mix(in srgb, var(--accent-ink) 12%, transparent)',
        } as CSSProperties,
    },
};

export function StoreFooter({ shell }: { shell: StoreShell }) {
    const footer = shell.settings.footer;
    const legal = footer.legal;
    const external = useHasExternalImages();
    const name = shell.theme.display_name || shell.settings.company.name || '';
    const tone = TONES[footer.tone ?? 'light'] ?? TONES.light;

    // «Una línea»: el nombre, las redes y la franja legal, sin columnas.
    if (footer.style === 'minimal') {
        return (
            <footer className={cn('mt-10', tone.className)} style={tone.style}>
                {footer.newsletter && (
                    <div className="mx-auto w-full max-w-[var(--store-max,1280px)] px-4 pt-8 lg:px-[var(--store-gutter,1.5rem)]">
                        <NewsletterForm />
                    </div>
                )}
                <div className="mx-auto flex w-full max-w-[var(--store-max,1280px)] flex-wrap items-center justify-between gap-4 px-4 py-6 lg:px-[var(--store-gutter,1.5rem)]">
                    <p className="text-[16px] font-extrabold" data-store-wordmark="">
                        {name}
                    </p>
                    <SocialLinks footer={footer} />
                    {shell.theme.allow_scheme_toggle && (
                        <ThemeToggle fallback={shell.theme.scheme === 'dark' ? 'dark' : shell.theme.scheme === 'auto' ? 'system' : 'light'} compact />
                    )}
                </div>
                <LegalStrip legal={legal} />
            </footer>
        );
    }

    return (
        <footer className={cn('mt-10', tone.className)} style={tone.style}>
            {footer.newsletter && (
                <div className="mx-auto w-full max-w-[var(--store-max,1280px)] border-b border-[var(--line)] px-4 py-8 lg:px-[var(--store-gutter,1.5rem)]">
                    <NewsletterForm />
                </div>
            )}
            <div className="mx-auto grid w-full max-w-[var(--store-max,1280px)] gap-8 px-4 py-8 md:grid-cols-[minmax(0,1.3fr)_repeat(3,minmax(0,1fr))] lg:px-[var(--store-gutter,1.5rem)]">
                <div className="flex flex-col gap-3">
                    <p className="text-[18px] font-extrabold" style={{ fontFamily: 'var(--store-display)' }}>
                        {name}
                    </p>
                    {shell.theme.tagline && <p className="text-[13.5px] text-[var(--muted)]">{shell.theme.tagline}</p>}

                    <ul className="flex flex-col gap-1.5 text-[13.5px]">
                        {footer.contact.address && (
                            <li className="flex items-start gap-2">
                                <MapPinIcon size={17} className="mt-0.5 shrink-0 text-[var(--accent)]" />
                                {footer.contact.address}
                            </li>
                        )}
                        {footer.contact.phone && (
                            <li>
                                <a href={`tel:${footer.contact.phone.replace(/\s+/g, '')}`} className="flex items-center gap-2 hover:underline">
                                    <PhoneIcon size={17} className="shrink-0 text-[var(--accent)]" />
                                    {footer.contact.phone}
                                </a>
                            </li>
                        )}
                        {footer.contact.whatsapp_href && (
                            <li>
                                <a href={footer.contact.whatsapp_href} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 hover:underline">
                                    <WhatsappLogoIcon size={17} className="shrink-0 text-[var(--accent)]" />
                                    {footer.contact.whatsapp}
                                </a>
                            </li>
                        )}
                        {footer.contact.email && (
                            <li>
                                <a href={`mailto:${footer.contact.email}`} className="flex items-center gap-2 break-all hover:underline">
                                    <EnvelopeIcon size={17} className="shrink-0 text-[var(--accent)]" />
                                    {footer.contact.email}
                                </a>
                            </li>
                        )}
                    </ul>

                    <SocialLinks footer={footer} />
                </div>

                {footer.columns.map((column) => (
                    <div key={column.title}>
                        <h2 className="mb-2.5 text-[14px] uppercase tracking-wide text-[var(--muted)]">{column.title}</h2>
                        <ul className="flex flex-col gap-1">
                            {column.links.map((link) => (
                                <li key={link.label + link.href}>
                                    <StoreLink href={link.href} className="inline-flex min-h-[32px] items-center text-[14px] hover:text-[var(--accent)] hover:underline">
                                        {link.label}
                                    </StoreLink>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}

                {footer.payment_logos.length > 0 && (
                    <div>
                        <h2 className="mb-2.5 text-[14px] uppercase tracking-wide text-[var(--muted)]">Medios de pago</h2>
                        <ul className="flex flex-wrap gap-1.5">
                            {footer.payment_logos.map((logo) => {
                                const PaymentIcon = PAYMENTS[logo.key] ?? CreditCardIcon;

                                return (
                                    <li key={logo.key} className="flex items-center gap-1.5 rounded-[8px] border border-[var(--line)] bg-[var(--surface-2)] px-2 py-1 text-[12px] font-semibold">
                                        <PaymentIcon size={15} />
                                        {logo.label}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}
            </div>

            {(footer.legal_text || external || shell.theme.allow_scheme_toggle) && (
                <div className="mx-auto flex w-full max-w-[var(--store-max,1280px)] flex-col gap-3 px-4 pb-6 text-[12px] text-[var(--muted)] md:flex-row md:items-center md:justify-between lg:px-[var(--store-gutter,1.5rem)]">
                    <div className="flex flex-col gap-1">
                        {footer.legal_text && <p className="max-w-[760px] whitespace-pre-line">{footer.legal_text}</p>}
                        {external && footer.attribution && (
                            <p>
                                <a href={footer.attribution.url} target="_blank" rel="noopener noreferrer" className="underline">
                                    {footer.attribution.label}
                                </a>
                            </p>
                        )}
                    </div>
                    {/* El cliente cambia claro/oscuro aquí abajo, nunca en el encabezado, y solo si la sede lo permite. */}
                    {shell.theme.allow_scheme_toggle && (
                        <ThemeToggle fallback={shell.theme.scheme === 'dark' ? 'dark' : shell.theme.scheme === 'auto' ? 'system' : 'light'} compact />
                    )}
                </div>
            )}

            <LegalStrip legal={legal} />
        </footer>
    );
}

function SocialLinks({ footer }: { footer: StoreShell['settings']['footer'] }) {
    if (footer.social.length === 0) {
        return null;
    }

    return (
        <ul className="flex gap-2" aria-label="Redes sociales">
            {footer.social.map((row) => {
                const SocialIcon = SOCIAL[row.network] ?? InstagramLogoIcon;

                return (
                    <li key={row.network + row.url}>
                        <a
                            href={row.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label={row.label}
                            className="grid size-10 place-items-center rounded-full border border-[var(--line)] hover:border-[var(--accent)] hover:text-[var(--accent)]"
                        >
                            <SocialIcon size={19} />
                        </a>
                    </li>
                );
            })}
        </ul>
    );
}

/** La franja legal: siempre, con los datos de la empresa. No se configura. */
function LegalStrip({ legal }: { legal: StoreShell['settings']['footer']['legal'] }) {
    return (
        <div className="border-t border-[var(--line)] bg-[var(--surface-2)]">
            <div className="mx-auto flex w-full max-w-[var(--store-max,1280px)] flex-col gap-2 px-4 py-4 text-[12px] leading-relaxed text-[var(--muted)] md:flex-row md:items-center md:justify-between lg:px-[var(--store-gutter,1.5rem)]">
                <p>
                    <strong className="text-[var(--text)]">{legal.name}</strong>
                    {legal.tax_id && <> · NIT {legal.tax_id}</>}
                    {legal.address && <> · {legal.address}</>}
                    {legal.phone && <> · Tel. {legal.phone}</>}
                    {legal.email && <> · {legal.email}</>}
                </p>
                <ul className="flex flex-wrap gap-x-4 gap-y-1 font-semibold">
                    {legal.terms_href && (
                        <li>
                            <StoreLink href={legal.terms_href} className="hover:text-[var(--text)] hover:underline">
                                Términos y condiciones
                            </StoreLink>
                        </li>
                    )}
                    {legal.privacy_href && (
                        <li>
                            <StoreLink href={legal.privacy_href} className="hover:text-[var(--text)] hover:underline">
                                Tratamiento de datos
                            </StoreLink>
                        </li>
                    )}
                    {legal.pqrs_href && (
                        <li>
                            <StoreLink href={legal.pqrs_href} className="hover:text-[var(--text)] hover:underline">
                                PQRS
                            </StoreLink>
                        </li>
                    )}
                </ul>
            </div>
        </div>
    );
}

/**
 * «Recibe las novedades»: el correo y la autorización de datos, que es obligatoria. Lo guarda el
 * servidor por empresa (un correo, una vez); aquí solo se manda y se dice cómo salió.
 */
function NewsletterForm() {
    const { interactive } = usePreviewMode();
    const [email, setEmail] = useState('');
    const [consent, setConsent] = useState(false);
    const [state, setState] = useState<{ status: 'idle' | 'sending' | 'done' | 'error'; message?: string }>({ status: 'idle' });

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (!interactive) {
            return;
        }

        setState({ status: 'sending' });

        try {
            const response = await fetch(route('storefront.newsletter.store'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                credentials: 'same-origin',
                body: JSON.stringify({ email, consent, website: '' }),
            });

            if (!response.ok) {
                setState({ status: 'error', message: await errorMessage(response, 'No pudimos guardar tu correo. Intenta de nuevo.') });

                return;
            }

            const body = (await response.json()) as { message: string };
            setState({ status: 'done', message: body.message });
            setEmail('');
            setConsent(false);
        } catch {
            setState({ status: 'error', message: 'No pudimos guardar tu correo. Revisa la conexión.' });
        }
    };

    return (
        <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] md:items-center">
            <div>
                <h2 className="text-[20px] leading-tight">Recibe las novedades</h2>
                <p className="mt-1 text-[13.5px] text-[var(--muted)]">Colecciones, ofertas y lanzamientos en tu correo. Te das de baja cuando quieras.</p>
            </div>
            {state.status === 'done' ? (
                <p className="rounded-[var(--store-radius-sm)] bg-[var(--surface-2)] px-4 py-3 text-[14px] font-semibold" role="status">
                    {state.message}
                </p>
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-2">
                    <div className="flex gap-2">
                        <label htmlFor="newsletter-email" className="sr-only">
                            Tu correo
                        </label>
                        <input
                            id="newsletter-email"
                            type="email"
                            required
                            maxLength={160}
                            value={email}
                            onChange={(event) => setEmail(event.target.value)}
                            placeholder="tu@correo.com"
                            className="h-11 min-w-0 flex-1 rounded-[var(--store-btn-radius,var(--store-radius-sm))] border border-[var(--line-2)] bg-[var(--surface)] px-3 text-[14px] text-[var(--text)] outline-none focus:border-[var(--accent)]"
                        />
                        <button
                            type="submit"
                            disabled={state.status === 'sending'}
                            className="h-11 shrink-0 rounded-[var(--store-btn-radius,var(--store-radius-sm))] bg-[var(--text)] px-4 text-[14px] font-bold text-[var(--bg)] hover:opacity-90 disabled:opacity-60"
                        >
                            {state.status === 'sending' ? 'Enviando…' : 'Suscribirme'}
                        </button>
                    </div>
                    <label className="flex items-start gap-2 text-[12px] text-[var(--muted)]">
                        <input type="checkbox" required checked={consent} onChange={(event) => setConsent(event.target.checked)} className="mt-0.5 size-4 shrink-0 accent-[var(--accent)]" />
                        Autorizo el tratamiento de mis datos para recibir comunicaciones de la tienda.
                    </label>
                    {state.status === 'error' && (
                        <p className="text-[12.5px] font-semibold text-[var(--deal)]" role="alert">
                            {state.message}
                        </p>
                    )}
                </form>
            )}
        </div>
    );
}
