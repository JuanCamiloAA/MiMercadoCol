<?php

namespace App\Http\Requests\Storefront\Settings;

use App\Services\Storefront\StorefrontStructure;
use Illuminate\Validation\Rule;

/** Pie de pagina. La franja legal no se configura: sale de la empresa. */
class FooterSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.columns' => ['present', 'array', 'max:4'],
            'value.columns.*.title' => ['required', 'string', 'max:30'],
            'value.columns.*.links' => ['present', 'array', 'max:8'],
            'value.columns.*.links.*.label' => ['required', 'string', 'max:40'],
            'value.columns.*.links.*.target' => $this->targetRule(required: true),
            'value.contact' => ['present', 'array'],
            'value.contact.phone' => ['nullable', 'string', 'max:30'],
            'value.contact.whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ ]+$/'],
            'value.contact.email' => ['nullable', 'email', 'max:120'],
            'value.contact.address' => ['nullable', 'string', 'max:160'],
            'value.social' => ['present', 'array', 'max:5'],
            'value.social.*.network' => ['required', 'distinct', Rule::in(array_keys(StorefrontStructure::SOCIAL))],
            'value.social.*.url' => ['required', 'url:https', 'max:200'],
            'value.payment_logos' => ['present', 'array'],
            'value.payment_logos.*' => ['distinct', Rule::in(array_keys(StorefrontStructure::PAYMENT_LOGOS))],
            'value.legal_text' => ['nullable', 'string', 'max:600'],
            'value.terms_target' => $this->targetRule(),
            'value.privacy_target' => $this->targetRule(),
            'value.pqrs_target' => $this->targetRule(),
        ];
    }

    public function value(): array
    {
        $value = parent::value();

        $value['columns'] = array_map(fn (array $column) => [
            'title' => trim((string) $column['title']),
            'links' => array_values(array_filter(array_map(fn (array $link) => [
                'label' => trim((string) $link['label']),
                'target' => $this->target($link['target'] ?? null),
            ], $column['links'] ?? []), fn (array $link) => $link['target'] !== null)),
        ], array_values($value['columns'] ?? []));

        $value['contact'] = [
            'phone' => $value['contact']['phone'] ?? null,
            'whatsapp' => $value['contact']['whatsapp'] ?? null,
            'email' => $value['contact']['email'] ?? null,
            'address' => $value['contact']['address'] ?? null,
        ];

        foreach (['terms_target', 'privacy_target', 'pqrs_target'] as $key) {
            $value[$key] = $this->target($value[$key] ?? null);
        }

        return $value;
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'value.social.*.url.url' => 'El enlace de la red tiene que empezar por https://.',
            'value.contact.whatsapp.regex' => 'El WhatsApp va solo con números, por ejemplo 3001234567.',
            'value.columns.*.title.required' => 'Cada columna necesita su título.',
            'value.columns.*.links.*.label.required' => 'Cada enlace necesita su texto.',
        ]);
    }
}
