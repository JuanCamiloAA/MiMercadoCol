<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.index.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:180'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'display_name.required' => 'Ponle un nombre al rol.',
            'color.regex' => 'El color debe venir en formato #RRGGBB.',
        ];
    }
}
