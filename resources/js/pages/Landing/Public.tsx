import { Head } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { BrandStyle } from '@/components/brand/BrandStyle';
import { LandingBlocks, type LandingBlockView, type LandingSite, type LandingTheme } from '@/components/landing/LandingBlocks';
import { useFlash } from '@/hooks/use-flash';
import { useTheme } from '@/hooks/use-theme';

interface LandingPublicProps {
    site: LandingSite;
    panel: { name: string; url: string } | null;
    blocks: LandingBlockView[];
    isPreview: boolean;
    /** En la vista previa: el modo que el editor está revisando (Claro | Oscuro). */
    previewScheme?: 'light' | 'dark' | null;
}

/**
 * La landing de la plataforma (`/`). La vista previa del editor pinta este mismo componente con
 * el borrador, dentro de un `iframe`.
 */
export default function LandingPublic({ site, panel, blocks, isPreview, previewScheme = null }: LandingPublicProps) {
    // Nace clara y quien la visita la cambia con el selector. En la vista previa manda el modo que
    // revisa el editor, sin tocar lo que escogió quien mira.
    const theme: LandingTheme = { fallback: previewScheme ?? 'light', locked: previewScheme !== null };
    useTheme(theme.fallback, theme.locked);
    useFlash();

    // En la vista previa los enlaces no navegan: el marco se quedaria en otra pagina.
    const stopLinks = (event: MouseEvent<HTMLDivElement>) => {
        if (isPreview && (event.target as HTMLElement).closest('a')) {
            event.preventDefault();
        }
    };

    return (
        <div className="min-h-screen bg-[var(--bg)] text-[var(--text)]" onClickCapture={stopLinks}>
            <Head title={site.seo_title}>
                {site.seo_description && <meta name="description" content={site.seo_description} />}
                <meta property="og:title" content={site.seo_title} />
                {site.seo_description && <meta property="og:description" content={site.seo_description} />}
                {site.seo_image_url && <meta property="og:image" content={site.seo_image_url} />}
                {site.favicon_url && <link rel="icon" href={site.favicon_url} />}
            </Head>
            <BrandStyle />

            <LandingBlocks blocks={blocks} site={site} panel={panel} isPreview={isPreview} theme={theme} />
        </div>
    );
}
