<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Subir una imagen de marca: isotipo, logo para fondo claro o logo para fondo oscuro.
 *
 * SVG no se acepta: puede traer `<script>` y sin un saneador seria XSS almacenado.
 */
class StoreBrandImageRequest extends FormRequest
{
    public const MIN_MARK = 256;

    /** Cuanto puede apartarse el isotipo de un cuadrado. */
    public const SQUARE_TOLERANCE = 0.05;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'];

        if ($this->route('slot') === 'mark') {
            $rules[] = 'dimensions:min_width='.self::MIN_MARK.',min_height='.self::MIN_MARK;
        }

        return ['file' => $rules];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->route('slot') !== 'mark' || $validator->errors()->has('file')) {
                    return;
                }

                $size = @getimagesize((string) $this->file('file')?->getRealPath());

                if (! $size || $size[0] <= 0 || $size[1] <= 0) {
                    return;
                }

                if (abs($size[0] - $size[1]) / max($size[0], $size[1]) > self::SQUARE_TOLERANCE) {
                    $validator->errors()->add('file', "El isotipo tiene que ser cuadrado y este mide {$size[0]} × {$size[1]} px.");
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
            'file.required' => 'Elige una imagen.',
            'file.file' => 'Elige una imagen.',
            'file.mimes' => 'La imagen tiene que ser PNG, JPG o WebP. SVG no se acepta.',
            'file.max' => 'La imagen pesa más de 2 MB.',
            'file.dimensions' => 'El isotipo debe medir al menos '.self::MIN_MARK.' × '.self::MIN_MARK.' px.',
        ];
    }
}
