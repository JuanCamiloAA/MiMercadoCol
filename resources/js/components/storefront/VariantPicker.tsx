import { cn } from '@/lib/cn';
import type { FamilyAttribute, FamilyVariant } from '@/types/storefront';

type Position = 1 | 2;

const valueOf = (variant: FamilyVariant, position: Position) => (position === 1 ? variant.value_1_id : variant.value_2_id);

/**
 * Escoger una presentación: por cada característica sus valores, como botones, muestras de color
 * o lista, según diga la empresa.
 *
 * Un valor sin existencia sale tachado y no se puede elegir. Con dos características, lo tachado
 * depende de lo ya elegido en la otra: la talla 16 puede estar en negro y no en azul. El precio y
 * la existencia de cada presentación los trae el servidor; aquí solo se cruzan los valores.
 */
export function VariantPicker({
    attributes,
    variants,
    selectedId,
    onSelect,
    compact = false,
}: {
    attributes: FamilyAttribute[];
    variants: FamilyVariant[];
    selectedId: number | null;
    onSelect: (variant: FamilyVariant) => void;
    compact?: boolean;
}) {
    const selected = variants.find((variant) => variant.id === selectedId) ?? variants[0] ?? null;

    if (!selected) {
        return null;
    }

    /** La presentación que queda al tocar un valor: con el valor de la otra característica que ya estaba elegido. */
    const target = (position: Position, valueId: number) => {
        const other: Position = position === 1 ? 2 : 1;
        const otherValue = valueOf(selected, other);

        return variants.find((variant) => valueOf(variant, position) === valueId && (attributes.length < 2 || valueOf(variant, other) === otherValue)) ?? null;
    };

    return (
        <div className="flex flex-col gap-4">
            {attributes.map((attribute) => {
                const position = attribute.position;
                const current = attribute.values.find((value) => value.id === valueOf(selected, position));

                return (
                    <fieldset key={attribute.id}>
                        <legend className={cn('mb-2 font-semibold', compact ? 'text-[13.5px]' : 'text-[14.5px]')}>
                            {attribute.name}: <span className="font-normal text-[var(--muted)]">{current?.value ?? 'Elige'}</span>
                        </legend>

                        {attribute.display === 'select' ? (
                            <select
                                aria-label={attribute.name}
                                value={current?.id ?? ''}
                                onChange={(event) => {
                                    const variant = target(position, Number(event.target.value));

                                    if (variant) {
                                        onSelect(variant);
                                    }
                                }}
                                className="h-12 w-full max-w-[320px] rounded-[var(--store-radius-sm)] border border-[var(--line)] bg-[var(--surface)] px-3 text-[15px]"
                            >
                                {attribute.values.map((value) => {
                                    const variant = target(position, value.id);
                                    const out = !variant;

                                    return (
                                        <option key={value.id} value={value.id} disabled={out}>
                                            {value.value}
                                            {out ? ' — no disponible' : ''}
                                        </option>
                                    );
                                })}
                            </select>
                        ) : (
                            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={attribute.name}>
                                {attribute.values.map((value) => {
                                    const variant = target(position, value.id);
                                    const out = !variant;
                                    const on = current?.id === value.id;
                                    const swatch = attribute.display === 'swatches';

                                    return (
                                        <button
                                            key={value.id}
                                            type="button"
                                            role="radio"
                                            aria-checked={on}
                                            aria-disabled={out || undefined}
                                            aria-label={swatch ? `${value.value}${out ? ', no disponible' : ''}` : undefined}
                                            title={swatch ? value.value : undefined}
                                            onClick={() => variant && !out && onSelect(variant)}
                                            className={cn(
                                                'relative transition-colors',
                                                swatch
                                                    ? cn(
                                                          'grid size-11 place-items-center rounded-full border-2 p-0.5',
                                                          on ? 'border-[var(--accent)]' : 'border-transparent hover:border-[var(--line)]',
                                                          out && 'cursor-not-allowed opacity-40',
                                                      )
                                                    : cn(
                                                          'min-h-[44px] min-w-[44px] rounded-[var(--store-radius-sm)] border px-3.5 text-[14.5px] font-semibold tabular-nums',
                                                          on
                                                              ? 'border-2 border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                              : out
                                                                ? 'cursor-not-allowed border-dashed border-[var(--line)] text-[var(--muted)] line-through'
                                                                : 'border-[var(--line)] bg-[var(--surface)] hover:border-[var(--accent)]',
                                                      ),
                                            )}
                                        >
                                            {swatch ? (
                                                value.image ? (
                                                    <img src={value.image} alt="" className="size-full rounded-full object-cover" />
                                                ) : (
                                                    <span className="size-full rounded-full border border-black/10" style={{ backgroundColor: value.color_hex ?? '#ccc' }} />
                                                )
                                            ) : (
                                                <>
                                                    {value.value}
                                                    {out && <span className="sr-only"> (no disponible)</span>}
                                                </>
                                            )}
                                            {swatch && out && <span aria-hidden className="absolute inset-x-1 top-1/2 h-0.5 -rotate-45 bg-[var(--muted)]" />}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </fieldset>
                );
            })}
        </div>
    );
}
