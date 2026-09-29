<?php

namespace App\Http\Requests\Storefront\Settings;

use App\Services\Storefront\StorefrontStructure;
use Closure;
use Illuminate\Validation\Rule;

/** Barra superior: mensajes rotativos con variables de la configuracion publicada. */
class TopbarSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.enabled' => ['required', 'boolean'],
            'value.tone' => ['required', Rule::in(array_keys(StorefrontStructure::TOPBAR_TONES))],
            'value.rotate_seconds' => ['required', 'integer', 'in:0,3,4,5,6,8,10,15'],
            'value.messages' => ['present', 'array', 'max:6'],
            'value.messages.*.icon' => ['nullable', Rule::in(config('storefront_icons'))],
            'value.messages.*.text' => [
                'required', 'string', 'max:90',
                function (string $attribute, mixed $value, Closure $fail) {
                    $unknown = StorefrontStructure::unknownVariables((string) $value);

                    if ($unknown !== []) {
                        $fail('La variable {'.$unknown[0].'} no existe. Usa {'.implode('}, {', StorefrontStructure::TOPBAR_VARIABLES).'}.');
                    }
                },
            ],
            'value.messages.*.target' => $this->targetRule(),
        ];
    }

    public function value(): array
    {
        $value = parent::value();
        $value['messages'] = array_map(fn (array $message) => [
            'icon' => $message['icon'] ?? null,
            'text' => trim((string) $message['text']),
            'target' => $this->target($message['target'] ?? null),
        ], array_values($value['messages'] ?? []));

        return $value;
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'value.messages.*.text.required' => 'Cada mensaje necesita su texto.',
            'value.messages.max' => 'Caben hasta seis mensajes.',
        ]);
    }
}
