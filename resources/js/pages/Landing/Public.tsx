import { Head } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { BrandStyle } from '@/components/brand/BrandStyle';
import { LandingBlocks, type LandingBlockView, type LandingSite } from '@/components/landing/LandingBlocks';
import { useFlash } from '@/hooks/use-flash';
import { useTheme } from '@/hooks/use-theme';

interface LandingPublicProps {
    site: LandingSite;
    panel: { name: string; url: string } | null;
    blocks: LandingBlockView[];
    isPreview: boolean;
}

/**
 * La landing de la plataforma (`/`). La vista previa del editor pinta este mismo componente con
 * el borrador, dentro de un `iframe`.
 */
export default function LandingPublic({ site, panel, blocks, isPreview }: LandingPublicProps) {
    useTheme('light');
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

            <LandingBlocks blocks={blocks} site={site} panel={panel} isPreview={isPreview} />
        </div>
    );
}
