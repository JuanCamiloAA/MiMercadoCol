<?php

namespace App\Http\Requests\Storefront\Settings;

use Illuminate\Validation\Rule;

/** Menu de categorias: chip de ofertas, items y menu grande. */
class NavSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.enabled' => ['required', 'boolean'],
            'value.show_deals_chip' => ['required', 'boolean'],
            'value.deals_label' => ['required', 'string', 'max:24'],
            'value.items' => ['present', 'array', 'max:14'],
            'value.items.*.label' => ['nullable', 'string', 'max:30'],
            'value.items.*.icon' => ['nullable', Rule::in(config('storefront_icons'))],
            'value.items.*.target' => $this->targetRule(required: true, whatsapp: false),
            'value.items.*.highlight' => ['boolean'],
            'value.show_all_categories' => ['required', 'boolean'],
            'value.mega_menu' => ['required', 'boolean'],
        ];
    }

    public function value(): array
    {
        $value = parent::value();
        $value['items'] = array_values(array_filter(array_map(fn (array $item) => [
            'label' => trim((string) ($item['label'] ?? '')),
            'icon' => $item['icon'] ?? null,
            'target' => $this->target($item['target'] ?? null, false),
            'highlight' => (bool) ($item['highlight'] ?? false),
        ], $value['items'] ?? []), fn (array $item) => $item['target'] !== null));

        return $value;
    }
}
