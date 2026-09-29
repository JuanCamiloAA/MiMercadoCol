import { CaretDownIcon } from '@phosphor-icons/react';
import { useId, useState } from 'react';
import { cn } from '@/lib/cn';
import { text, type BlockProps } from './shared';

/** Preguntas frecuentes, una abierta a la vez. */
export function FaqBlock({ data }: BlockProps) {
    const items = (data.items as { question: string; answer: string }[] | undefined) ?? [];
    const [open, setOpen] = useState<number | null>(0);
    const id = useId();

    if (items.length === 0) {
        return null;
    }

    return (
        <div className="rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] p-4 md:p-5">
            {text(data.title) !== '' && <h2 className="mb-2 text-[19px] md:text-[22px]">{text(data.title)}</h2>}

            <ul className="flex flex-col divide-y divide-[var(--line)]">
                {items.map((item, index) => (
                    <li key={index}>
                        <h3 className="text-[14.5px]" style={{ fontFamily: 'var(--store-font)', fontWeight: 700 }}>
                            <button
                                type="button"
                                onClick={() => setOpen(open === index ? null : index)}
                                aria-expanded={open === index}
                                aria-controls={`${id}-${index}`}
                                className="flex min-h-[48px] w-full items-center justify-between gap-3 py-2.5 text-left"
                            >
                                {item.question}
                                <CaretDownIcon size={16} weight="bold" className={cn('shrink-0 text-[var(--muted)] transition-transform', open === index && 'rotate-180')} />
                            </button>
                        </h3>
                        {open === index && item.answer && (
                            <p id={`${id}-${index}`} className="pb-3 text-[14px] leading-relaxed text-[var(--muted)]">
                                {item.answer}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
