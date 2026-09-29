import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import type { SettingKey, SettingsValues } from './types';

/**
 * `{'brand.accent': x}` a `{brand: {accent: x}}`.
 *
 * El servidor valida `brand.accent` como `brand[accent]`: una clave con punto literal no la
 * encontraria. Por eso cada pestana manda sus datos anidados.
 */
export function nest(flat: Record<string, unknown>): Record<string, Record<string, unknown>> {
    const nested: Record<string, Record<string, unknown>> = {};

    for (const [key, value] of Object.entries(flat)) {
        const dot = key.indexOf('.');
        const prefix = key.slice(0, dot);

        nested[prefix] = { ...(nested[prefix] ?? {}), [key.slice(dot + 1)]: value };
    }

    return nested;
}

export function pick<K extends SettingKey>(source: SettingsValues, keys: readonly K[]): Pick<SettingsValues, K> {
    return Object.fromEntries(keys.map((key) => [key, source[key]])) as Pick<SettingsValues, K>;
}

/**
 * El formulario de una pestana: sus claves, su ruta y su aviso de cambios sin guardar.
 *
 * Los datos se guardan planos (asi los errores del servidor, que llegan como `brand.accent`,
 * caen en su campo) y se anidan justo antes de enviar.
 */
export function useSettingsForm<K extends SettingKey>(
    keys: readonly K[],
    initial: SettingsValues,
    onDirtyChange: (dirty: boolean) => void,
) {
    const form = useForm<Pick<SettingsValues, K>>(pick(initial, keys));

    useEffect(() => {
        onDirtyChange(form.isDirty);
    }, [form.isDirty, onDirtyChange]);

    const set = <Key extends K>(key: Key, value: SettingsValues[Key]) => {
        form.setData((current) => ({ ...current, [key]: value }));
        form.clearErrors(key);
    };

    const save = (url: string, extra: Record<string, unknown> = {}) => {
        form.transform((data) => ({ ...nest(data as Record<string, unknown>), ...extra }) as unknown as Pick<SettingsValues, K>);
        form.put(url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    /** Deja el borrador con los valores de fabrica. No guarda: eso lo decide quien edita. */
    const reset = (defaults: SettingsValues) => {
        form.setData((current) => ({ ...current, ...pick(defaults, keys) }));
        form.clearErrors();
    };

    return { form, data: form.data, errors: form.errors as Partial<Record<string, string>>, set, save, reset };
}
