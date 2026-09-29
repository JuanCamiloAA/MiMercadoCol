<?php

namespace App\Http\Requests\Settings;

use App\Settings\SettingsSchema;
use Illuminate\Validation\Validator;

/**
 * Ajustes > Caja: tema, tamano, disposicion, riel, atajos y sonidos.
 */
class UpdatePosSettingsRequest extends SettingsTabRequest
{
    protected function tab(): string
    {
        return SettingsSchema::TAB_POS;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $rail = $this->input('pos.rail');

                if ($validator->errors()->has('pos.rail') || ! is_array($rail)) {
                    return;
                }

                $sorted = array_values($rail);
                $expected = SettingsSchema::RAIL_ITEMS;
                sort($sorted);
                sort($expected);

                if ($sorted !== $expected) {
                    $validator->errors()->add('pos.rail', 'El riel tiene que llevar Vender, Apertura y Cierre, cada uno una vez.');
                }
            },
            function (Validator $validator) {
                $shortcuts = $this->input('pos.shortcuts');

                if ($validator->errors()->has('pos.shortcuts') || ! is_array($shortcuts)) {
                    return;
                }

                $actions = SettingsSchema::SHORTCUT_ACTIONS;

                foreach ($actions as $action => $label) {
                    $key = $shortcuts[$action] ?? null;

                    if (! is_string($key) || ! in_array($key, SettingsSchema::SHORTCUT_KEYS, true)) {
                        $validator->errors()->add('pos.shortcuts', "Elige una tecla de F1 a F12 para {$label}.");

                        return;
                    }
                }

                if (array_diff_key($shortcuts, $actions) !== []) {
                    $validator->errors()->add('pos.shortcuts', 'Los atajos traen una acción que la caja no conoce.');

                    return;
                }

                $seen = [];

                foreach ($actions as $action => $label) {
                    $key = $shortcuts[$action];

                    if (isset($seen[$key])) {
                        $validator->errors()->add('pos.shortcuts', "La tecla {$key} está en dos acciones: {$seen[$key]} y {$label}.");

                        return;
                    }

                    $seen[$key] = $label;
                }
            },
        ];
    }

    public function values(): array
    {
        $values = parent::values();
        $values['pos.rail'] = array_values($values['pos.rail'] ?? SettingsSchema::RAIL_ITEMS);
        $values['pos.shortcuts'] = array_intersect_key($values['pos.shortcuts'] ?? [], SettingsSchema::SHORTCUT_ACTIONS);

        return $values;
    }
}
