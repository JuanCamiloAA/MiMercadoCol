import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { text, type BlockProps } from './shared';

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

/** Texto libre: condiciones del domicilio, un aviso de temporada. */
export function RichTextBlock({ data }: BlockProps) {
    const body = text(data.body);
    const title = text(data.title);

    if (body.trim() === '' && title.trim() === '') {
        return null;
    }

    return (
        <div className={cn('rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] p-5', data.align === 'center' && 'text-center')}>
            {title !== '' && <h2 className="mb-2 text-[19px] md:text-[22px]">{title}</h2>}
            {body !== '' && <div className="flex flex-col gap-1.5 text-[14px] leading-relaxed text-[var(--muted)]">{renderMarkdown(body)}</div>}
        </div>
    );
}
