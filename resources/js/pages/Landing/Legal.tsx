import { Head, Link } from '@inertiajs/react';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import { BrandStyle } from '@/components/brand/BrandStyle';
import type { LandingSite } from '@/components/landing/LandingBlocks';
import { useTheme } from '@/hooks/use-theme';

interface LegalProps {
    site: LandingSite;
    title: string;
    body: string;
}

/**
 * Términos y condiciones, y tratamiento de datos personales (Ley 1581 de 2012). El texto se
 * edita en Plataforma > Landing > Ajustes.
 */
export default function Legal({ site, title, body }: LegalProps) {
    useTheme('light');

    return (
        <div className="min-h-screen bg-[var(--bg)] px-4 py-10 text-[var(--text)]">
            <Head title={`${title} · ${site.name}`} />
            <BrandStyle />

            <article className="mx-auto w-full max-w-[46rem] rounded-card border border-[var(--line)] bg-[var(--surface)] p-6 md:p-10">
                <Link href="/" className="mb-6 inline-flex items-center gap-1.5 text-[0.875rem] font-medium text-[var(--accent)] hover:underline">
                    <ArrowLeftIcon size={16} /> {site.name}
                </Link>
                <h1 className="text-[1.75rem] leading-tight md:text-[2.125rem]">{title}</h1>
                <div className="mt-6 whitespace-pre-line text-[1rem] leading-relaxed text-[var(--text)]">{body}</div>
            </article>
        </div>
    );
}
