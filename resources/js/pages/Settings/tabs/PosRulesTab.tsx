import { Input, Select } from '@/components/ui/Field';
import { SaveBar, TabHeader } from '../SettingField';
import { useSettingsForm } from '../form';
import type { SettingKey, TabProps } from '../types';

export const POS_RULES_KEYS = [
    'pos.allow_discount_percent',
    'pos.cash_rounding',
    'returns.max_days',
    'returns.store_credit_days',
    'payments.online_hold_minutes',
    'payments.pos_link_minutes',
] as const satisfies readonly SettingKey[];

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

            <div className="border-t border-[var(--line)] pt-4">
                <p className="text-[0.875rem] font-semibold">Pagos por pasarela</p>
                <p className="text-[0.78125rem] text-[var(--muted)]">Los medios y las cuentas se configuran en Medios de pago.</p>
            </div>

            <Input
                label="Espera del pago en línea"
                type="number"
                min={5}
                max={1440}
                required
                disabled={readOnly}
                value={data['payments.online_hold_minutes']}
                onChange={(event) => set('payments.online_hold_minutes', Number(event.target.value))}
                error={errors['payments.online_hold_minutes']}
                suffix="minutos"
                hint="Mientras el cliente paga en la pasarela, el pedido aparta la mercancía y el saldo a favor. Si no paga en este tiempo, el pedido se cancela y se libera."
            />

            <Input
                label="Vigencia del QR o link de la caja"
                type="number"
                min={2}
                max={120}
                required
                disabled={readOnly}
                value={data['payments.pos_link_minutes']}
                onChange={(event) => set('payments.pos_link_minutes', Number(event.target.value))}
                error={errors['payments.pos_link_minutes']}
                suffix="minutos"
                hint="Después de este tiempo el cobro vence y la caja vuelve al tiquete."
            />

            {!readOnly && (
                <SaveBar dirty={form.isDirty} processing={form.processing} onSave={() => save(route('settings.pos_rules.update'))} label="Guardar las reglas" />
            )}
        </div>
    );
}
