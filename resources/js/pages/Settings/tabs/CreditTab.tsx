import { Input } from '@/components/ui/Field';
import { SaveBar, TabHeader } from '../SettingField';
import { useSettingsForm } from '../form';
import type { SettingKey, TabProps } from '../types';

export const CREDIT_KEYS = ['credit.block_on_overdue_days'] as const satisfies readonly SettingKey[];

/** Ajustes > Credito: a cuantos dias de mora se bloquea el credito de un cliente. */
export function CreditTab({ settings, defaults, readOnly, onDirtyChange }: TabProps) {
    const { form, data, errors, set, save, reset } = useSettingsForm(CREDIT_KEYS, settings, onDirtyChange);

    return (
        <div className="flex max-w-[34rem] flex-col gap-5">
            <TabHeader scope="Empresa" onFactory={readOnly ? undefined : () => reset(defaults)} />

            <Input
                label="Bloquear el crédito tras"
                type="number"
                min={1}
                max={120}
                required
                disabled={readOnly}
                value={data['credit.block_on_overdue_days']}
                onChange={(event) => set('credit.block_on_overdue_days', Number(event.target.value))}
                error={errors['credit.block_on_overdue_days']}
                suffix="días"
                hint="Una tarea diaria bloquea a quien tenga facturas vencidas hace más de este plazo."
            />

            {!readOnly && (
                <SaveBar dirty={form.isDirty} processing={form.processing} onSave={() => save(route('settings.credit.update'))} label="Guardar el crédito" />
            )}
        </div>
    );
}
