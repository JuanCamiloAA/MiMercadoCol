<?php

namespace App\Http\Requests\Settings;

use App\Settings\SettingsSchema;
use Illuminate\Validation\Validator;

/**
 * Ajustes > Tiquete, editando toda la empresa. Vale para cada sede que no tenga su excepcion.
 */
class UpdateReceiptSettingsRequest extends SettingsTabRequest
{
    use ChecksReceiptHeader;

    protected function tab(): string
    {
        return SettingsSchema::TAB_RECEIPT;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($problem = $this->fieldsProblem($this->input('receipt.fields'))) {
                    $validator->errors()->add('receipt.fields', $problem);
                }
            },
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['receipt.header', 'receipt.paper_width'])) {
                    return;
                }

                $header = $this->input('receipt.header');
                $paper = (int) $this->input('receipt.paper_width');

                if ($problem = $this->headerProblem($header, $paper)) {
                    $validator->errors()->add('receipt.header', $problem);

                    return;
                }

                // Cada sede imprime con su papel y su encabezado: los propios, o los que hereda
                // de lo que se esta guardando.
                foreach ($this->storesWithOverrides() as ['store' => $store, 'overrides' => $overrides]) {
                    $storePaper = (int) ($overrides['receipt.paper_width'] ?? $paper);
                    $storeHeader = array_key_exists('receipt.header', $overrides) ? $overrides['receipt.header'] : $header;

                    if ($problem = $this->headerProblem($storeHeader, $storePaper, $store->name)) {
                        $validator->errors()->add('receipt.header', $problem);

                        return;
                    }
                }
            },
        ];
    }

    public function values(): array
    {
        $values = parent::values();
        $values['receipt.header'] = (string) ($values['receipt.header'] ?? '');
        $values['receipt.fields'] = array_values(array_intersect(SettingsSchema::RECEIPT_FIELDS, $values['receipt.fields'] ?? []));

        return $values;
    }
}
