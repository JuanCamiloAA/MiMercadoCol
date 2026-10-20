import { Input } from '@/components/ui/Field';
import { SaveBar, TabHeader } from '../SettingField';
import { useSettingsForm } from '../form';
import type { SettingKey, TabProps } from '../types';

export const CATALOG_KEYS = [
    'catalog.max_images',
    'storefront_catalog.expiring_days',
    'storefront_catalog.overstock_days',
    'storefront_catalog.overstock_min_factor',
    'storefront_catalog.low_margin_percent',
] as const satisfies readonly SettingKey[];

/** Los umbrales de Tienda en línea, con sus topes del servidor (`SettingsSchema`). */
const STOREFRONT_THRESHOLDS: { key: (typeof CATALOG_KEYS)[number]; label: string; min: number; max: number; suffix: string; hint: string }[] = [
    {
        key: 'storefront_catalog.expiring_days',
        label: 'Lote por vencer',
        min: 1,
        max: 90,
        suffix: 'días',
        hint: 'Un lote con saldo que vence en estos días o menos se marca y se sugiere publicar con promoción.',
    },
    {
        key: 'storefront_catalog.overstock_days',
        label: 'Poca rotación',
        min: 7,
        max: 365,
        suffix: 'días',
        hint: 'Si lo que hay alcanza para más días que estos al ritmo de la caja, es inventario alto.',
    },
    {
        key: 'storefront_catalog.overstock_min_factor',
        label: 'Inventario alto sin ventas',
        min: 1,
        max: 20,
        suffix: 'veces el mínimo',
        hint: 'Sin ventas en 30 días, cuántas veces el mínimo hacen falta para sugerir publicarlo.',
    },
    {
        key: 'storefront_catalog.low_margin_percent',
        label: 'Margen bajo',
        min: 0,
        max: 90,
        suffix: '%',
        hint: 'Por debajo de este margen (sobre el precio de la tienda sin IVA) el filtro lo marca.',
    },
];

/** El tope que acepta el servidor (`CatalogImages::MAX_LIMIT`). */
const MAX_IMAGES = 10;

/**
 * Ajustes > Catálogo: cuántas fotos se le montan a cada artículo, artículo principal o servicio, y
 * los umbrales con que Tienda en línea sugiere y filtra.
 */
export function CatalogTab({ settings, defaults, readOnly, onDirtyChange }: TabProps) {
    const { form, data, errors, set, save, reset } = useSettingsForm(CATALOG_KEYS, settings, onDirtyChange);
    const value = data['catalog.max_images'];

    return (
        <div className="flex max-w-[34rem] flex-col gap-5">
            <TabHeader scope="Empresa" onFactory={readOnly ? undefined : () => reset(defaults)} />

            <Input
                label="Fotos por artículo"
                type="number"
                min={1}
                max={MAX_IMAGES}
                required
                disabled={readOnly}
                value={value}
                onChange={(event) => set('catalog.max_images', Number(event.target.value))}
                error={errors['catalog.max_images']}
                suffix={value === 1 ? 'foto' : 'fotos'}
                hint={`Vale para artículos, artículos con subartículos y servicios, desde que se crean. De 1 a ${MAX_IMAGES}. Con más de una, la tienda las muestra en carrusel en la ficha.`}
            />

            <p className="rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">
                Bajar el número no borra fotos: un artículo que ya tenga más las conserva y se siguen viendo en la tienda; solo no deja subir otra
                hasta quitar alguna.
            </p>

            <section className="flex flex-col gap-4 border-t border-[var(--line)] pt-5">
                <div>
                    <h3 className="text-[0.9375rem] font-semibold">Tienda en línea: sugerencias y filtros</h3>
                    <p className="mt-0.5 text-[0.8125rem] text-[var(--muted)]">Lo que el módulo Tienda en línea llama lote por vencer, poca rotación y margen bajo.</p>
                </div>
                {STOREFRONT_THRESHOLDS.map((threshold) => (
                    <Input
                        key={threshold.key}
                        label={threshold.label}
                        type="number"
                        min={threshold.min}
                        max={threshold.max}
                        required
                        disabled={readOnly}
                        value={data[threshold.key]}
                        onChange={(event) => set(threshold.key, Number(event.target.value))}
                        error={errors[threshold.key]}
                        suffix={threshold.suffix}
                        hint={threshold.hint}
                    />
                ))}
            </section>

            {!readOnly && (
                <SaveBar dirty={form.isDirty} processing={form.processing} onSave={() => save(route('settings.catalog.update'))} label="Guardar el catálogo" />
            )}
        </div>
    );
}
