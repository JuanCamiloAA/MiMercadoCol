import { CaretRightIcon } from '@phosphor-icons/react';
import { StoreIcon } from '@/components/storefront/icons';
import { cn } from '@/lib/cn';
import { TargetLink, TONES, text, type BlockProps, type Target } from './shared';

/** Franja con un llamado y su botón: «Pide por WhatsApp», «Crea tu cuenta». */
export function CtaBandBlock({ data }: BlockProps) {
    const title = text(data.title);

    if (title === '') {
        return null;
    }

    const tone = text(data.tone) || 'soft';
    const target = data.target as Target | null;
    const label = text(data.button_label);

    return (
        <div className={cn('flex flex-col gap-3 rounded-[var(--store-radius)] p-4 sm:flex-row sm:items-center md:p-5', TONES[tone] ?? TONES.soft)}>
            <span
                className={cn(
                    'grid size-12 shrink-0 place-items-center rounded-full',
                    tone === 'soft' ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'bg-[color-mix(in_srgb,currentColor_15%,transparent)]',
                )}
            >
                <StoreIcon name={text(data.icon) || 'Megaphone'} size={24} weight="fill" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-[18px] font-extrabold leading-tight md:text-[20px]" style={{ fontFamily: 'var(--store-display)' }}>
                    {title}
                </p>
                {text(data.text) !== '' && <p className="mt-0.5 text-[14px] opacity-85">{text(data.text)}</p>}
            </div>
            {label !== '' && target?.href && (
                <TargetLink
                    target={target}
                    className={cn(
                        'inline-flex h-12 shrink-0 items-center justify-center gap-1.5 rounded-full px-6 text-[14.5px] font-extrabold',
                        tone === 'soft' ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'bg-[var(--surface)] text-[var(--text)]',
                    )}
                >
                    {label}
                    <CaretRightIcon size={15} weight="bold" />
                </TargetLink>
            )}
        </div>
    );
}
