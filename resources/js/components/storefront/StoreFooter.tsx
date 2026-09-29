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
import { ThemeToggle } from '@/components/ui/ThemeToggle';
import type { StoreShell } from '@/types/storefront';
import { StoreLink, useHasExternalImages } from './store-context';

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
export function StoreFooter({ shell }: { shell: StoreShell }) {
    const footer = shell.settings.footer;
    const legal = footer.legal;
    const external = useHasExternalImages();
    const name = shell.theme.display_name || shell.settings.company.name || '';

    return (
        <footer className="mt-10 border-t border-[var(--line)] bg-[var(--surface)] text-[var(--text)]">
            <div className="mx-auto grid w-full max-w-[1280px] gap-8 px-4 py-8 md:grid-cols-[minmax(0,1.3fr)_repeat(3,minmax(0,1fr))] lg:px-6">
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

                    {footer.social.length > 0 && (
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
                    )}
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
                <div className="mx-auto flex w-full max-w-[1280px] flex-col gap-3 px-4 pb-6 text-[12px] text-[var(--muted)] md:flex-row md:items-center md:justify-between lg:px-6">
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

            <div className="border-t border-[var(--line)] bg-[var(--surface-2)]">
                <div className="mx-auto flex w-full max-w-[1280px] flex-col gap-2 px-4 py-4 text-[12px] leading-relaxed text-[var(--muted)] md:flex-row md:items-center md:justify-between lg:px-6">
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
        </footer>
    );
}
