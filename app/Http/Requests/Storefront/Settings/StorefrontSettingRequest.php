<?php

namespace App\Http\Requests\Storefront\Settings;

use App\Storefront\Blocks\BlockData;
use App\Storefront\Targets;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un ajuste de la tienda (una clave de `storefront_settings`).
 *
 * Cada clave tiene su request con su forma exacta: listas cerradas, longitudes y destinos
 * validos. Lo que llega con basura no se guarda a medias: o entra completo o no entra. El
 * valor limpio lo da `value()`, que es lo unico que se guarda.
 *
 * El mismo request sirve al editor de una sede y al de la plantilla de la plataforma: en la
 * plantilla (`context() === 'template'`) no se aceptan destinos a categorias por id.
 */
abstract class StorefrontSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function valueRules(): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(['value' => ['required', 'array']], $this->valueRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.required' => 'No llegó nada para guardar.',
            'value.*.in' => 'Esa opción no existe.',
            'value.*.max' => 'Es demasiado largo.',
        ];
    }

    /** En que editor se esta: el de una sede o el de la plantilla. */
    public function context(): string
    {
        return str_starts_with($this->route()?->getName() ?? '', 'platform.store_templates.')
            ? BlockData::CONTEXT_TEMPLATE
            : BlockData::CONTEXT_STORE;
    }

    /**
     * El valor ya validado, con su forma exacta. Por defecto, lo validado bajo `value`.
     *
     * @return array<string, mixed>
     */
    public function value(): array
    {
        return (array) $this->validated('value');
    }

    /** Los destinos que admite un enlace en este editor. */
    protected function targetKinds(bool $whatsapp = true): array
    {
        $kinds = $this->context() === BlockData::CONTEXT_TEMPLATE ? Targets::TEMPLATE_KINDS : Targets::KINDS;

        return $whatsapp ? $kinds : array_values(array_diff($kinds, [Targets::KIND_WHATSAPP]));
    }

    /**
     * Un destino valido (o vacio, si es opcional).
     *
     * @return list<mixed>
     */
    protected function targetRule(bool $required = false, bool $whatsapp = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'array',
            function (string $attribute, mixed $value, Closure $fail) use ($whatsapp) {
                if ($value === null || $value === [] || (is_array($value) && ($value['value'] ?? '') === '' && ($value['kind'] ?? '') === '')) {
                    return;
                }

                if (Targets::sanitize($value, $this->targetKinds($whatsapp)) === null) {
                    $fail(($value['kind'] ?? null) === Targets::KIND_CATEGORY && $this->context() === BlockData::CONTEXT_TEMPLATE
                        ? 'Una plantilla no puede enlazar a una categoría de una empresa: usa una búsqueda o una página.'
                        : 'Ese destino no es válido. Elige una página, una categoría o un enlace que empiece por https://.');
                }
            },
        ];
    }

    /** Limpia un destino validado para guardarlo. */
    protected function target(mixed $value, bool $whatsapp = true): ?array
    {
        return Targets::sanitize($value, $this->targetKinds($whatsapp));
    }
}
