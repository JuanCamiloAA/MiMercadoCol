<?php

namespace App\Http\Requests\Settings;

use App\Models\Store;
use App\Services\SettingsService;
use App\Settings\SettingsSchema;
use App\Support\TenantContext;
use Illuminate\Validation\Validator;

/**
 * Ajustes > Tiquete, editando una sede.
 *
 * Llegan todos los valores y, en `own`, cuales son propios de la sede. Lo que no esta en `own`
 * queda sin excepcion y la sede vuelve a usar el de la empresa. Asi un encabezado vacio puede
 * ser propio («esta sede no lleva encabezado») sin confundirse con «usar el de la empresa».
 */
class UpdateStoreReceiptSettingsRequest extends SettingsTabRequest
{
    use ChecksReceiptHeader;

    protected function tab(): string
    {
        return SettingsSchema::TAB_RECEIPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $own = $this->ownKeys();
        $rules = ['own' => ['present', 'array'], 'own.*' => ['string']];

        foreach (SettingsSchema::rulesFor(SettingsSchema::TAB_RECEIPT) as $key => $keyRules) {
            // Solo se valida lo que la sede va a guardar como propio.
            $rules[$key] = in_array($key, $own, true) ? $keyRules : ['nullable'];
        }

        return $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $unknown = array_diff($this->ownKeys(), SettingsSchema::keysFor(SettingsSchema::TAB_RECEIPT));

                if ($unknown !== []) {
                    $validator->errors()->add('own', 'Solo los ajustes del tiquete pueden ser propios de una sede.');
                }
            },
            function (Validator $validator) {
                if (in_array('receipt.fields', $this->ownKeys(), true) && ($problem = $this->fieldsProblem($this->input('receipt.fields')))) {
                    $validator->errors()->add('receipt.fields', $problem);
                }
            },
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['receipt.header', 'receipt.paper_width'])) {
                    return;
                }

                $own = $this->ownKeys();
                $company = app(SettingsService::class)->all(TenantContext::requireCompanyId());

                $paper = in_array('receipt.paper_width', $own, true)
                    ? (int) $this->input('receipt.paper_width')
                    : (int) $company['receipt.paper_width'];

                $header = in_array('receipt.header', $own, true)
                    ? $this->input('receipt.header')
                    : $company['receipt.header'];

                /** @var Store $store */
                $store = $this->route('store');

                if ($problem = $this->headerProblem($header, $paper, $store->name)) {
                    $validator->errors()->add('receipt.header', $problem);
                }
            },
        ];
    }

    /**
     * Valor por clave para `setForStore`: el propio, o null para quitar la excepcion.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $own = $this->ownKeys();
        $validated = $this->validated();
        $values = [];

        foreach (SettingsSchema::keysFor(SettingsSchema::TAB_RECEIPT) as $key) {
            if (! in_array($key, $own, true)) {
                $values[$key] = null;

                continue;
            }

            [$prefix, $name] = explode('.', $key, 2);
            $value = $validated[$prefix][$name] ?? null;

            $values[$key] = match ($key) {
                'receipt.header' => (string) ($value ?? ''),
                'receipt.fields' => array_values(array_intersect(SettingsSchema::RECEIPT_FIELDS, (array) $value)),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    protected function ownKeys(): array
    {
        $own = $this->input('own', []);

        return is_array($own) ? array_values(array_filter($own, 'is_string')) : [];
    }
}
