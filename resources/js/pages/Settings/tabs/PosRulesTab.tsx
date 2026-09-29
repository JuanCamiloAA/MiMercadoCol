import { Input, Select } from '@/components/ui/Field';
import { SaveBar, TabHeader } from '../SettingField';
import { useSettingsForm } from '../form';
import type { SettingKey, TabProps } from '../types';

export const POS_RULES_KEYS = ['pos.allow_discount_percent', 'pos.cash_rounding', 'returns.max_days', 'returns.store_credit_days'] as const satisfies readonly SettingKey[];

/**
 * Ajustes > Reglas de caja: cuanto descuento da el cajero sin supervisor, a cuanto se redondea
 * el efectivo y las reglas de las devoluciones de venta. Son reglas de plata: tienen su propio
 * permiso, aparte de la marca y de la caja.
 */
export function PosRulesTab({ settings, defaults, readOnly, onDirtyChange, options }: TabProps) {
    const { form, data, errors, set, save, reset } = useSettingsForm(POS_RULES_KEYS, settings, onDirtyChange);

    return (
        <div className="flex max-w-[34rem] flex-col gap-5">
            <TabHeader scope="Empresa" onFactory={readOnly ? undefined : () => reset(defaults)} />

            <Input
                label="Descuento máximo permitido"
                type="number"
                min={0}
                max={100}
                required
                disabled={readOnly}
                value={data['pos.allow_discount_percent']}
                onChange={(event) => set('pos.allow_discount_percent', Number(event.target.value))}
                error={errors['pos.allow_discount_percent']}
                suffix="%"
                hint="Por encima de este tope, el cobro pide el PIN de un supervisor. Lo que rebaja una promoción no cuenta aquí."
            />

            <Select
                label="Redondeo del efectivo"
                required
                disabled={readOnly}
                options={options['pos.cash_rounding'] ?? []}
                value={data['pos.cash_rounding']}
                onChange={(event) => set('pos.cash_rounding', Number(event.target.value))}
                error={errors['pos.cash_rounding']}
                hint="Solo aplica cuando todo se paga en efectivo; el ajuste queda impreso en el tiquete."
            />

            <div className="border-t border-[var(--line)] pt-4">
                <p className="text-[0.875rem] font-semibold">Devoluciones de venta</p>
                <p className="text-[0.78125rem] text-[var(--muted)]">Aplican en la caja y en la tienda en línea.</p>
            </div>

            <Input
                label="Plazo para devolver"
                type="number"
                min={1}
                max={365}
                required
                disabled={readOnly}
                value={data['returns.max_days']}
                onChange={(event) => set('returns.max_days', Number(event.target.value))}
                error={errors['returns.max_days']}
                suffix="días"
                hint="Contados desde la venta en caja o desde la entrega del pedido en línea. Fuera del plazo, en caja se pide el PIN de un supervisor; en la tienda en línea ya no se puede solicitar."
            />

            <Input
                label="Vigencia del saldo a favor"
                type="number"
                min={0}
                max={1825}
                required
                disabled={readOnly}
                value={data['returns.store_credit_days']}
                onChange={(event) => set('returns.store_credit_days', Number(event.target.value))}
                error={errors['returns.store_credit_days']}
                suffix="días"
                hint="Cuánto dura el saldo que se le da al cliente por una devolución. 0: no vence."
            />

            {!readOnly && (
                <SaveBar dirty={form.isDirty} processing={form.processing} onSave={() => save(route('settings.pos_rules.update'))} label="Guardar las reglas" />
            )}
        </div>
    );
}
