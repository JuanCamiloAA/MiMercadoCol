<?php

namespace App\Http\Requests;

use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->route('promotion') ? 'promotions.index.edit' : 'promotions.index.create';

        return $this->user()?->can($permission) ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(Promotion::TYPE_LABELS))],
            'label' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'max:20'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'applies_online' => ['boolean'],
            'applies_pos' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],

            'percent' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'buy' => ['nullable', 'integer', 'min:2', 'max:99'],
            'pay' => ['nullable', 'integer', 'min:1', 'max:98'],
            'bundle_price' => ['nullable', 'integer', 'min:1'],

            'targets' => ['required', 'array', 'min:1'],
            'targets.*.target_type' => ['required', Rule::in([PromotionTarget::TYPE_PRODUCT, PromotionTarget::TYPE_CATEGORY, PromotionTarget::TYPE_FAMILY])],
            'targets.*.target_id' => ['required', 'integer', 'min:1'],
            'targets.*.value' => ['nullable', 'integer', 'min:0'],
            'targets.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('type');
            $targets = $this->input('targets', []);

            // Cada tipo pide lo suyo: sin esto se guardarian promociones que no hacen nada.
            match ($type) {
                Promotion::TYPE_SPECIAL_PRICE => $this->checkSpecialPrice($validator, $targets),
                Promotion::TYPE_PERCENT_OFF => $this->checkPercent($validator),
                Promotion::TYPE_N_FOR_M => $this->checkNForM($validator, $targets),
                Promotion::TYPE_BUNDLE => $this->checkBundle($validator, $targets),
                default => null,
            };

            if (! $this->boolean('applies_online') && ! $this->boolean('applies_pos')) {
                $validator->errors()->add('applies_online', 'Elige al menos un canal: tienda en linea o mostrador.');
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     */
    protected function checkSpecialPrice(Validator $validator, array $targets): void
    {
        foreach ($targets as $index => $target) {
            if (! in_array($target['target_type'] ?? null, [PromotionTarget::TYPE_PRODUCT, PromotionTarget::TYPE_FAMILY], true)) {
                $validator->errors()->add("targets.{$index}.target_type", 'Un precio especial se fija por articulo, no por categoria.');

                continue;
            }

            if (! isset($target['value']) || (int) $target['value'] <= 0) {
                $validator->errors()->add("targets.{$index}.value", 'Escribe el precio promocional del articulo.');
            }
        }
    }

    protected function checkPercent(Validator $validator): void
    {
        if ((float) $this->input('percent') <= 0) {
            $validator->errors()->add('percent', 'Escribe el porcentaje de descuento.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     */
    protected function checkNForM(Validator $validator, array $targets): void
    {
        $buy = (int) $this->input('buy');
        $pay = (int) $this->input('pay');

        if ($buy <= 0 || $pay <= 0) {
            $validator->errors()->add('buy', 'Escribe cuantas unidades lleva y cuantas paga.');

            return;
        }

        if ($pay >= $buy) {
            $validator->errors()->add('pay', 'Las que paga tienen que ser menos que las que lleva.');
        }

        foreach ($targets as $index => $target) {
            if (! in_array($target['target_type'] ?? null, [PromotionTarget::TYPE_PRODUCT, PromotionTarget::TYPE_FAMILY], true)) {
                $validator->errors()->add("targets.{$index}.target_type", 'El lleva N paga M se arma con articulos, no con categorias.');
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     */
    protected function checkBundle(Validator $validator, array $targets): void
    {
        if ((int) $this->input('bundle_price') <= 0) {
            $validator->errors()->add('bundle_price', 'Escribe el precio del combo.');
        }

        if (count($targets) < 2) {
            $validator->errors()->add('targets', 'Un combo necesita al menos dos articulos.');
        }

        foreach ($targets as $index => $target) {
            if (($target['target_type'] ?? null) !== PromotionTarget::TYPE_PRODUCT) {
                $validator->errors()->add("targets.{$index}.target_type", 'Un combo se arma con articulos, no con categorias.');
            }
        }
    }

    /**
     * Datos de la promocion, con lo propio de cada tipo ya guardado en `config`.
     *
     * @return array<string, mixed>
     */
    public function promotionData(): array
    {
        $type = (string) $this->input('type');

        $config = match ($type) {
            Promotion::TYPE_PERCENT_OFF => ['percent' => (float) $this->input('percent')],
            Promotion::TYPE_N_FOR_M => ['buy' => (int) $this->input('buy'), 'pay' => (int) $this->input('pay')],
            Promotion::TYPE_BUNDLE => ['price' => (int) $this->input('bundle_price')],
            default => [],
        };

        return [
            'name' => $this->string('name')->toString(),
            'type' => $type,
            'label' => $this->input('label') ?: null,
            'color' => $this->input('color') ?: null,
            'starts_at' => $this->input('starts_at') ?: null,
            'ends_at' => $this->input('ends_at') ?: null,
            'applies_online' => $this->boolean('applies_online'),
            'applies_pos' => $this->boolean('applies_pos'),
            'priority' => (int) ($this->input('priority') ?? 0),
            'config' => $config,
            'is_active' => $this->boolean('is_active'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function targets(): array
    {
        return array_values($this->input('targets', []));
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre a la promocion.',
            'type.required' => 'Elige el tipo de promocion.',
            'targets.required' => 'Elige a que articulos o categorias aplica.',
            'ends_at.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
        ];
    }
}
