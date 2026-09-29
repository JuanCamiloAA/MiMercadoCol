import { MegaphoneIcon } from '@phosphor-icons/react';
import { cn } from '@/lib/cn';
import { TargetLink, TONES, text, type BlockProps, type Target } from './shared';

/** Una franja angosta con un aviso: un horario especial, una temporada. */
export function AnnouncementBlock({ data }: BlockProps) {
    const message = text(data.text);

    if (message.trim() === '') {
        return null;
    }

    const tone = text(data.tone) || 'accent';
    const link = data.link as Target | null;

    return (
        <TargetLink
            target={link}
            className={cn(
                'flex items-center justify-center gap-2 rounded-[var(--store-radius)] px-4 py-3 text-center text-[14px] font-semibold',
                tone === 'warn' ? TONES.warn : tone === 'deal' ? TONES.deal : 'bg-[var(--accent-soft)] text-[var(--accent)]',
                link?.href && 'hover:underline',
            )}
        >
            <MegaphoneIcon size={18} weight="fill" className="shrink-0" />
            {message}
        </TargetLink>
    );
}
