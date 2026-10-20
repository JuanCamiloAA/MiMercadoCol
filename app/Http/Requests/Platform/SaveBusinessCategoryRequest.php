<?php

namespace App\Http\Requests\Platform;

use App\Models\BusinessCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear o editar una clasificacion de empresa.
 */
class SaveBusinessCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $this->input('name')) ?? '');

        $this->merge(['name' => $name, 'slug' => Str::slug($name)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var BusinessCategory|null $category */
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:60'],
            'slug' => ['required', 'string', 'max:70', Rule::unique('business_categories', 'slug')->ignore($category?->id)],
            // Solo los que la landing sabe pintar: se importan uno por uno.
            'icon' => ['required', 'string', Rule::in(config('landing_icons'))],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * Sin una activa no se podria crear ni contratar una empresa.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var BusinessCategory|null $category */
                $category = $this->route('category');

                if ($category?->is_active && ! $this->boolean('is_active') && ! BusinessCategory::query()->active()->whereKeyNot($category->id)->exists()) {
                    $validator->errors()->add('is_active', 'Es la única clasificación activa: sin ella nadie podría crear ni contratar una empresa.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre de la clasificación.',
            'slug.required' => 'Escribe el nombre de la clasificación.',
            'slug.unique' => 'Ya hay una clasificación con ese nombre.',
            'icon.in' => 'Elige un ícono de la lista.',
        ];
    }
}
