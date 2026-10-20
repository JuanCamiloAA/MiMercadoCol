import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { TargetLink, text, type BlockProps, type Target } from './shared';

/** Markdown acotado: negrita, listas y enlaces. Nada de HTML crudo. */
export function renderMarkdown(body: string): ReactNode {
    return body.split('\n').map((line, index) => {
        const bullet = line.trimStart().startsWith('- ');
        const content = bullet ? line.trimStart().slice(2) : line;

        const parts: ReactNode[] = [];
        const pattern = /(\*\*[^*]+\*\*|\[[^\]]+\]\([^)]+\))/g;
        let last = 0;

        for (const match of content.matchAll(pattern)) {
            const token = match[0];
            const at = match.index ?? 0;

            if (at > last) {
                parts.push(content.slice(last, at));
            }

            if (token.startsWith('**')) {
                parts.push(<strong key={`${index}-${at}`}>{token.slice(2, -2)}</strong>);
            } else {
                const label = token.slice(1, token.indexOf(']'));
                const href = token.slice(token.indexOf('(') + 1, -1);
                // Solo enlaces de verdad: nada de `javascript:` en un texto de la tienda.
                const safe = /^(https?:\/\/|\/(?!\/)|mailto:|tel:)/i.test(href) ? href : '#';
                parts.push(
                    <a key={`${index}-${at}`} href={safe} className="font-semibold text-[var(--accent)] hover:underline">
                        {label}
                    </a>,
                );
            }

            last = at + token.length;
        }

        if (last < content.length) {
            parts.push(content.slice(last));
        }

        if (bullet) {
            return (
                <li key={index} className="ml-5 list-disc">
                    {parts}
                </li>
            );
        }

        return content.trim() === '' ? <br key={index} /> : <p key={index}>{parts}</p>;
    });
}

/**
 * Texto libre: condiciones del domicilio, un aviso de temporada. Tres estilos: la tarjeta de
 * siempre (`plain`), un título enorme en mayúsculas al centro (`bold`) y uno editorial con letra
 * de revista (`serif`), los dos sin tarjeta.
 */
export function RichTextBlock({ data }: BlockProps) {
    const body = text(data.body);
    const title = text(data.title);
    const eyebrow = text(data.eyebrow);
    const buttonLabel = text(data.button_label);
    const style = text(data.style) || 'plain';

    if (body.trim() === '' && title.trim() === '') {
        return null;
    }

    const button = buttonLabel ? (
        <TargetLink
            target={data.target as Target | null}
            className="mt-4 inline-flex h-11 items-center rounded-[var(--store-btn-radius,var(--store-radius-sm))] bg-[var(--accent)] px-6 text-[14px] font-bold text-[var(--accent-ink)] hover:brightness-110"
        >
            {buttonLabel}
        </TargetLink>
    ) : null;

    if (style === 'bold' || style === 'serif') {
        const bold = style === 'bold';

        return (
            <div className={cn('mx-auto flex max-w-[52rem] flex-col py-4 md:py-8', bold || data.align === 'center' ? 'items-center text-center' : 'items-start')}>
                {eyebrow && <p className="mb-3 text-[12px] font-bold uppercase tracking-[0.18em] text-[var(--muted)]">{eyebrow}</p>}
                {title !== '' && (
                    <h2
                        className={cn(bold ? 'text-[34px] uppercase leading-[0.95] tracking-tight md:text-[64px]' : 'text-[30px] leading-[1.1] md:text-[46px]')}
                        style={bold ? undefined : { fontFamily: "'Playfair Display', 'Cormorant Garamond', Georgia, serif", fontWeight: 600 }}
                    >
                        {title}
                    </h2>
                )}
                {body !== '' && (
                    <div className={cn('mt-4 flex flex-col gap-1.5 text-[var(--muted)]', bold ? 'max-w-[36rem] text-[15px] md:text-[17px]' : 'max-w-[40rem] text-[16px] md:text-[18px]', 'leading-relaxed')}>{renderMarkdown(body)}</div>
                )}
                {button}
            </div>
        );
    }

    return (
        <div className={cn('rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] p-5', data.align === 'center' && 'text-center')}>
            {eyebrow && <p className="mb-1 text-[11.5px] font-bold uppercase tracking-[0.14em] text-[var(--muted)]">{eyebrow}</p>}
            {title !== '' && <h2 className="mb-2 text-[19px] md:text-[22px]">{title}</h2>}
            {body !== '' && <div className="flex flex-col gap-1.5 text-[14px] leading-relaxed text-[var(--muted)]">{renderMarkdown(body)}</div>}
            {button}
        </div>
    );
}
