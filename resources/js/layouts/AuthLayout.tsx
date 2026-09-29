import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BrandMark } from '@/components/brand/BrandMark';
import { BrandStyle } from '@/components/brand/BrandStyle';
import { useFlash } from '@/hooks/use-flash';
import { useSurface } from '@/hooks/use-surface';
import { useTheme } from '@/hooks/use-theme';
import { panelSurface } from '@/lib/brand';
import type { PageProps } from '@/types';

interface AuthLayoutProps {
    title: string;
    heading: string;
    subheading?: string;
    children: ReactNode;
    footer?: ReactNode;
}

/**
 * Pantallas de ingreso. Llevan la marca de la unica empresa activa; con varias, la neutra.
 */
export default function AuthLayout({ title, heading, subheading, children, footer }: AuthLayoutProps) {
    const { brand } = usePage<PageProps>().props;
    const surface = panelSurface(brand ?? null);

    const { resolved } = useTheme(surface.default_theme, !surface.allow_theme_toggle);
    useSurface('panel');
    useFlash();

    const name = brand?.display_name ?? 'La Canasta';
    const logo = resolved === 'dark' ? (brand?.logo_dark_url ?? brand?.logo_light_url) : (brand?.logo_light_url ?? brand?.logo_dark_url);

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-[var(--bg)] px-4 py-10">
            <Head title={title} />
            <BrandStyle />

            <div className="w-full max-w-[25rem]">
                <div className="mb-6 flex flex-col items-center gap-3 text-center">
                    {logo ? (
                        <img src={logo} alt={name} className="h-12 max-w-[15rem] object-contain" />
                    ) : (
                        <BrandMark className="size-12 rounded-card" iconClassName="size-7" strokeWidth={2.2} />
                    )}
                    <div>
                        <h1 className="text-[1.375rem] leading-tight text-[var(--text)]">{heading}</h1>
                        {subheading && <p className="mt-1 text-[0.8125rem] text-[var(--muted)]">{subheading}</p>}
                    </div>
                </div>

                <div className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-6 shadow-sm">{children}</div>

                {footer && <div className="mt-4 text-center text-[0.8125rem] text-[var(--muted)]">{footer}</div>}

                <p className="mt-8 text-center text-[0.75rem] text-[var(--muted)]">
                    {brand?.tagline ? `${name} · ${brand.tagline}` : name}
                </p>
            </div>
        </div>
    );
}
