import { ArrowDownIcon, ArrowUpIcon, SpeakerHighIcon } from '@phosphor-icons/react';
import { playErrorSound, playScanSound } from '@/lib/sounds';
import { CheckRow, FieldBlock, SaveBar, Segmented, TabHeader } from '../SettingField';
import { useSettingsForm } from '../form';
import { PosPreview } from '../preview/PosPreview';
import { resolveTheme } from '../preview/vars';
import type { Option, RailKey, SettingKey, SettingsValues, ShortcutAction, TabProps } from '../types';

export const POS_KEYS = [
    'pos.default_theme',
    'pos.allow_theme_toggle',
    'pos.ui_scale',
    'pos.ticket_side',
    'pos.catalog_view',
    'pos.tile_size',
    'pos.total_size',
    'pos.rail',
    'pos.shortcuts',
    'pos.sounds',
] as const satisfies readonly SettingKey[];

/**
 * Ajustes > Caja: como se ve y se maneja el punto de venta. El permiso de quien ve cada boton
 * sigue mandando: aqui solo se ordena y se decide.
 */
export function PosTab({ settings, defaults, readOnly, onDirtyChange, options, brandAssets, previewTokens, posSample }: TabProps) {
    const { form, data, errors, set, save, reset } = useSettingsForm(POS_KEYS, settings, onDirtyChange);

    const segmented = <Key extends (typeof POS_KEYS)[number]>(key: Key, label: string) => (
        <Segmented
            label={label}
            value={data[key] as string | number}
            options={options[key] as Option[]}
            onChange={(value) => set(key, value as SettingsValues[Key])}
            disabled={readOnly}
        />
    );

    const rail = data['pos.rail'];
    const railLabels = options['pos.rail'] ?? [];
    const shortcutLabels = options['pos.shortcuts'] ?? [];
    const shortcuts = data['pos.shortcuts'];

    const moveRail = (index: number, delta: number) => {
        const next = [...rail];
        const target = index + delta;
        [next[index], next[target]] = [next[target], next[index]];
        set('pos.rail', next);
    };

    // Lo mismo que rechaza el servidor, avisado mientras se elige.
    const duplicates = new Set(
        Object.values(shortcuts).filter((key, index, all) => all.indexOf(key) !== index),
    );

    const viewHint: Record<string, string> = Object.fromEntries((options['pos.catalog_view'] ?? []).map((option) => [option.value, option.hint ?? '']));

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:grid-cols-[minmax(0,27rem)_minmax(0,1fr)]">
            <div className="flex flex-col gap-5">
                <TabHeader scope="Empresa · todas las cajas" onFactory={readOnly ? undefined : () => reset(defaults)} />

                <FieldBlock label="Tema inicial de la caja" error={errors['pos.default_theme']}>
                    {segmented('pos.default_theme', 'Tema inicial de la caja')}
                    <CheckRow
                        checked={data['pos.allow_theme_toggle']}
                        onChange={(value) => set('pos.allow_theme_toggle', value)}
                        disabled={readOnly}
                        label="Mostrar el interruptor de tema en la caja"
                        hint="Apagado, todas las cajas usan el tema inicial."
                    />
                </FieldBlock>

                <FieldBlock label="Tamaño" error={errors['pos.ui_scale']} hint="Para pantallas táctiles o que se miran de lejos. Agranda todo, también el cobro.">
                    {segmented('pos.ui_scale', 'Tamaño de la caja')}
                </FieldBlock>

                <FieldBlock label="Lado del tiquete" error={errors['pos.ticket_side']}>
                    {segmented('pos.ticket_side', 'Lado del tiquete')}
                </FieldBlock>

                <FieldBlock label="Catálogo" error={errors['pos.catalog_view']} hint={viewHint[data['pos.catalog_view']] || undefined}>
                    {segmented('pos.catalog_view', 'Vista del catálogo')}
                </FieldBlock>

                {data['pos.catalog_view'] === 'grid' && (
                    <FieldBlock label="Tamaño de las teclas" error={errors['pos.tile_size']}>
                        {segmented('pos.tile_size', 'Tamaño de las teclas')}
                    </FieldBlock>
                )}

                <FieldBlock label="Tamaño del total" error={errors['pos.total_size']}>
                    {segmented('pos.total_size', 'Tamaño del total')}
                </FieldBlock>

                <FieldBlock label="Riel de la caja" error={errors['pos.rail']} hint="El orden de los botones de la izquierda. Cada quien ve solo los que su permiso le deja abrir.">
                    <ul className="flex flex-col gap-1 rounded-card border border-[var(--line)] bg-[var(--surface)] p-1.5">
                        {rail.map((item: RailKey, index) => (
                            <li key={item} className="flex items-center gap-2 rounded-chip px-2 py-1">
                                <span className="w-5 text-center font-mono text-[0.75rem] text-[var(--muted)] tabular">{index + 1}</span>
                                <span className="flex-1 text-[0.8125rem] font-medium">{railLabels.find((option) => option.value === item)?.label ?? item}</span>
                                {!readOnly && (
                                    <>
                                        <button
                                            type="button"
                                            disabled={index === 0}
                                            onClick={() => moveRail(index, -1)}
                                            aria-label="Subir"
                                            className="grid size-8 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--surface-2)] disabled:opacity-30"
                                        >
                                            <ArrowUpIcon size={14} />
                                        </button>
                                        <button
                                            type="button"
                                            disabled={index === rail.length - 1}
                                            onClick={() => moveRail(index, 1)}
                                            aria-label="Bajar"
                                            className="grid size-8 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--surface-2)] disabled:opacity-30"
                                        >
                                            <ArrowDownIcon size={14} />
                                        </button>
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                </FieldBlock>

                <FieldBlock
                    label="Atajos de teclado"
                    error={errors['pos.shortcuts'] ?? (duplicates.size > 0 ? `La tecla ${[...duplicates][0]} está en dos acciones.` : undefined)}
                    hint="Solo teclas F: una letra chocaría con lo que se escribe en el buscador. El cobro repartido conserva sus propios atajos."
                >
                    <div className="grid gap-1.5 sm:grid-cols-2">
                        {(shortcutLabels as Option<ShortcutAction>[]).map((action) => (
                            <label
                                key={action.value}
                                className="flex items-center justify-between gap-2 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2.5 py-1.5"
                            >
                                <span className="text-[0.78125rem] font-medium">{action.label}</span>
                                <select
                                    value={shortcuts[action.value]}
                                    disabled={readOnly}
                                    onChange={(event) => set('pos.shortcuts', { ...shortcuts, [action.value]: event.target.value })}
                                    className={
                                        'h-8 w-16 rounded-chip border bg-[var(--surface)] px-1.5 font-mono text-[0.78125rem] focus:outline-none ' +
                                        (duplicates.has(shortcuts[action.value]) ? 'border-[var(--danger)] text-[var(--danger)]' : 'border-[var(--line)] text-[var(--text)]')
                                    }
                                >
                                    {(options['pos.shortcut_keys'] ?? []).map((key) => (
                                        <option key={key.value} value={key.value}>
                                            {key.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ))}
                    </div>
                </FieldBlock>

                <FieldBlock label="Sonidos" error={errors['pos.sounds']}>
                    <CheckRow
                        checked={data['pos.sounds']}
                        onChange={(value) => set('pos.sounds', value)}
                        disabled={readOnly}
                        label="Sonar al escanear"
                        hint="Un pitido cuando el lector encuentra el artículo y otro grave cuando no."
                    />
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={playScanSound}
                            className="inline-flex h-8 items-center gap-1.5 rounded-chip border border-[var(--line)] px-2.5 text-[0.78125rem] font-medium hover:bg-[var(--surface-2)]"
                        >
                            <SpeakerHighIcon size={14} />
                            Probar escaneo
                        </button>
                        <button
                            type="button"
                            onClick={playErrorSound}
                            className="inline-flex h-8 items-center gap-1.5 rounded-chip border border-[var(--line)] px-2.5 text-[0.78125rem] font-medium hover:bg-[var(--surface-2)]"
                        >
                            <SpeakerHighIcon size={14} />
                            Probar error
                        </button>
                    </div>
                </FieldBlock>

                {!readOnly && (
                    <SaveBar dirty={form.isDirty} processing={form.processing} onSave={() => save(route('settings.pos.update'))} label="Guardar la caja" />
                )}
            </div>

            <div className="flex flex-col gap-2 lg:sticky lg:top-20 lg:self-start">
                <p className="text-[0.78125rem] text-[var(--muted)]">Vista previa · caja al {data['pos.ui_scale']} %</p>
                <div className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-4">
                    <PosPreview
                        draft={data}
                        theme={resolveTheme(data['pos.default_theme'])}
                        brand={settings}
                        mark={brandAssets.mark}
                        tokens={previewTokens}
                        sample={posSample}
                        railLabels={railLabels}
                        shortcutLabels={shortcutLabels}
                    />
                </div>
            </div>
        </div>
    );
}
