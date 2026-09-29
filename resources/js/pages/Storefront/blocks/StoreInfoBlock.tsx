import { ClockIcon, MapPinIcon, PhoneIcon } from '@phosphor-icons/react';
import { text, type BlockProps } from './shared';

/** Dirección, teléfono y horario, tomados de la ficha de la sede. */
export function StoreInfoBlock({ data }: BlockProps) {
    const store = data.store as { name: string; address: string | null; phone: string | null; opens_at: string; closes_at: string } | undefined;

    if (!store) {
        return null;
    }

    return (
        <div className="rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] p-4 md:p-5">
            {text(data.title) !== '' && <h2 className="mb-3 text-[19px] md:text-[22px]">{text(data.title)}</h2>}

            <ul className="flex flex-col gap-2.5 text-[14px] sm:flex-row sm:flex-wrap sm:gap-x-8">
                {store.address && (
                    <li className="flex items-center gap-2">
                        <MapPinIcon size={19} weight="fill" className="shrink-0 text-[var(--accent)]" />
                        {store.address}
                    </li>
                )}
                {store.phone && (
                    <li>
                        <a href={`tel:${store.phone.replace(/\s+/g, '')}`} className="flex items-center gap-2 hover:underline">
                            <PhoneIcon size={19} weight="fill" className="shrink-0 text-[var(--accent)]" />
                            {store.phone}
                        </a>
                    </li>
                )}
                <li className="flex items-center gap-2">
                    <ClockIcon size={19} weight="fill" className="shrink-0 text-[var(--accent)]" />
                    Abierto de {store.opens_at} a {store.closes_at}
                </li>
            </ul>

            {text(data.note) !== '' && <p className="mt-3 border-t border-[var(--line)] pt-2 text-[13px] text-[var(--muted)]">{text(data.note)}</p>}
        </div>
    );
}
