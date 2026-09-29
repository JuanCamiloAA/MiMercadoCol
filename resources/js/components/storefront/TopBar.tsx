import { useEffect, useState } from 'react';
import { cn } from '@/lib/cn';
import type { StoreStructure } from '@/types/storefront';
import { StoreIcon } from './icons';
import { StoreLink } from './store-context';

const TONES: Record<StoreStructure['topbar']['tone'], string> = {
    brand_dark: 'bg-[var(--brand-dark)] text-white',
    accent: 'bg-[var(--accent)] text-[var(--accent-ink)]',
    neutral: 'bg-[var(--surface-2)] text-[var(--text)]',
    deal: 'bg-[var(--deal)] text-[var(--deal-ink)]',
};

function Message({ message }: { message: StoreStructure['topbar']['messages'][number] }) {
    return (
        <StoreLink href={message.href} className={cn('inline-flex min-w-0 items-center gap-1.5', message.href && 'hover:underline')}>
            {message.icon && <StoreIcon name={message.icon} size={15} weight="bold" className="shrink-0" />}
            <span className="truncate">{message.text}</span>
        </StoreLink>
    );
}

/**
 * La barra delgada de arriba: domicilio, horario, la próxima franja. Los textos llegan con sus
 * variables ya resueltas desde la configuración publicada; un mensaje con una variable sin
 * valor ni siquiera llega («gratis desde $ 0» no existe).
 *
 * En computador caben todos a la vez; en celular rotan, salvo que quien mira prefiera menos
 * movimiento: entonces se queda quieta en el primero.
 */
export function TopBar({ topbar }: { topbar: StoreStructure['topbar'] }) {
    const [index, setIndex] = useState(0);
    const messages = topbar.messages;
    const rotates = topbar.rotate_seconds > 0 && messages.length > 1;

    useEffect(() => {
        if (!rotates || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const timer = setInterval(() => setIndex((current) => (current + 1) % messages.length), topbar.rotate_seconds * 1000);

        return () => clearInterval(timer);
    }, [messages.length, rotates, topbar.rotate_seconds]);

    if (!topbar.enabled || messages.length === 0) {
        return null;
    }

    return (
        <div className={cn('text-[12.5px] font-semibold', TONES[topbar.tone] ?? TONES.brand_dark)}>
            <div className="mx-auto flex h-9 w-full max-w-[1280px] items-center justify-center px-4 lg:px-6">
                {/* Celular: uno a la vez. */}
                <p className="flex min-w-0 justify-center md:hidden" aria-live="off">
                    <Message key={index} message={messages[index % messages.length]} />
                </p>

                {/* Computador: todos, separados. */}
                <ul className="hidden min-w-0 items-center justify-center gap-5 md:flex">
                    {messages.slice(0, 4).map((message, position) => (
                        <li key={position} className="flex min-w-0 items-center gap-5">
                            {position > 0 && <span className="size-1 shrink-0 rounded-full bg-current opacity-50" aria-hidden />}
                            <Message message={message} />
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
