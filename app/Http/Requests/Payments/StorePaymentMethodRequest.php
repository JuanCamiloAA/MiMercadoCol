<?php

namespace App\Http\Requests\Payments;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\PaymentGateway;
use App\Services\Payments\PaymentMethodAvailability;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear un medio de pago: nombre, tipo, cuenta de pasarela, como se ve, referencia, comision y
 * «cuando aparece». El comportamiento sale del tipo, asi que el tipo manda que se puede elegir.
 */
class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['gateway_account_id', 'fee_fixed'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in($this->kinds())],
            'gateway_account_id' => [
                Rule::requiredIf(fn () => in_array($this->input('kind'), PaymentMethod::GATEWAY_KINDS, true)),
                'nullable', 'integer',
                Rule::exists('payment_gateway_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'icon' => ['nullable', Rule::in(PaymentMethod::ICONS)],
            'display' => ['nullable', 'array'],
            'display.description_pos' => ['nullable', 'string', 'max:80'],
            'display.description_online' => ['nullable', 'string', 'max:120'],
            'display.instructions' => ['nullable', 'string', 'max:500'],
            'display.highlight' => ['nullable', 'boolean'],
            'requires_reference' => ['required', 'boolean'],
            'reference_label' => ['nullable', 'string', 'max:60'],
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:20'],
            'fee_fixed' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['required', 'boolean'],

            'availability' => ['nullable', 'array'],
            'availability.channels' => ['nullable', 'array'],
            'availability.channels.*' => ['string', Rule::in(PaymentMethod::CHANNELS)],
            'availability.stores' => ['nullable', 'array'],
            'availability.stores.*' => ['integer', Rule::exists('stores', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'availability.channel_stores' => ['nullable', 'array'],
            'availability.channel_stores.online' => ['nullable', 'array'],
            'availability.channel_stores.online.*' => ['integer', Rule::exists('stores', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'availability.registers' => ['nullable', 'array'],
            'availability.registers.*' => ['integer', Rule::exists('registers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'availability.schedule' => ['nullable', 'array', 'max:21'],
            'availability.schedule.*.days' => ['required', 'array', 'min:1'],
            'availability.schedule.*.days.*' => ['integer', 'between:0,6'],
            'availability.schedule.*.from' => ['required', 'date_format:H:i'],
            'availability.schedule.*.to' => ['required', 'regex:/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/'],
            'availability.valid_from' => ['nullable', 'date_format:Y-m-d'],
            'availability.valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:availability.valid_from'],
            'availability.min_amount' => ['nullable', 'integer', 'min:0'],
            'availability.max_amount' => ['nullable', 'integer', 'min:0'],
            'availability.delivery_modes' => ['nullable', 'array'],
            'availability.delivery_modes.*' => ['string', Rule::in(['delivery', 'pickup'])],
            'availability.customer' => ['nullable', 'array'],
            'availability.customer.registered' => ['nullable', 'boolean'],
            'availability.customer.credit_line' => ['nullable', 'boolean'],
            'availability.customer.store_credit' => ['nullable', 'boolean'],
            'availability.combinable' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Los tipos que se pueden elegir: los manuales y, si el plan trae pasarelas, los de pasarela.
     *
     * @return list<string>
     */
    protected function kinds(): array
    {
        return PaymentMethod::CONFIGURABLE_KINDS;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $kind = (string) $this->input('kind');
            $availability = (array) $this->input('availability', []);

            // El canal solo puede acotar lo que el tipo permite.
            foreach ((array) ($availability['channels'] ?? []) as $channel) {
                if (! in_array($channel, PaymentMethod::KIND_CHANNELS[$kind] ?? [], true)) {
                    $validator->errors()->add('availability.channels', 'Ese tipo de medio no se usa en '.($channel === PaymentMethod::CHANNEL_POS ? 'la caja' : 'la tienda en línea').'.');
                }
            }

            $min = $availability['min_amount'] ?? null;
            $max = $availability['max_amount'] ?? null;

            if (is_numeric($min) && is_numeric($max) && (int) $max > 0 && (int) $max < (int) $min) {
                $validator->errors()->add('availability.max_amount', 'El máximo no puede ser menor que el mínimo.');
            }

            foreach ((array) ($availability['schedule'] ?? []) as $index => $slot) {
                $from = PaymentMethodAvailability::time($slot['from'] ?? null);
                $to = PaymentMethodAvailability::time($slot['to'] ?? null);

                if ($from !== null && $from === $to) {
                    $validator->errors()->add("availability.schedule.{$index}.to", 'La franja tiene que durar algo.');
                }
            }

            if ($this->boolean('requires_reference') && trim((string) $this->input('reference_label')) === '') {
                $validator->errors()->add('reference_label', 'Di qué se anota: «Número de aprobación», «Últimos 4 dígitos»…');
            }

            if (in_array($kind, PaymentMethod::GATEWAY_KINDS, true)) {
                $this->validateGateway($validator, $kind);
            }
        });
    }

    protected function validateGateway(Validator $validator, string $kind): void
    {
        $company = TenantContext::company($this->user('web'));
        $entitlement = $company?->entitlement();

        if ($entitlement && ! $entitlement->allowsNode('payment_methods.gateways.*')) {
            $validator->errors()->add('kind', 'Tu plan no incluye cobrar por pasarela. Cámbialo en Ajustes > Membresía.');

            return;
        }

        if ($kind === PaymentMethod::KIND_GATEWAY_TERMINAL && $entitlement && ! $entitlement->allowsNode('payment_methods.terminals.*')) {
            $validator->errors()->add('kind', 'Tu plan no incluye datáfonos integrados. Cámbialo en Ajustes > Membresía.');

            return;
        }

        $account = PaymentGatewayAccount::query()->find($this->input('gateway_account_id'));

        if (! $account) {
            return;
        }

        $needed = match ($kind) {
            PaymentMethod::KIND_GATEWAY_CHECKOUT => [PaymentGateway::CAPABILITY_CHECKOUT],
            PaymentMethod::KIND_GATEWAY_LINK => [PaymentGateway::CAPABILITY_LINK, PaymentGateway::CAPABILITY_QR],
            default => [PaymentGateway::CAPABILITY_TERMINAL],
        };

        $registry = app(GatewayRegistry::class);

        if (! $registry->exists($account->provider) || array_intersect($needed, $registry->for($account->provider)->capabilities()) === []) {
            $validator->errors()->add('gateway_account_id', 'Esa pasarela no ofrece este tipo de cobro.');
        }
    }

    /**
     * Lo que se guarda: las reglas vacias no se guardan, asi «vacio = sin restriccion».
     *
     * @return array<string, mixed>
     */
    public function methodData(): array
    {
        $data = $this->safe()->only(['name', 'kind', 'gateway_account_id', 'icon', 'requires_reference', 'reference_label', 'fee_percent', 'fee_fixed', 'is_active']);
        $data['gateway_account_id'] = in_array($data['kind'] ?? null, PaymentMethod::GATEWAY_KINDS, true) ? ($data['gateway_account_id'] ?? null) : null;
        $data['fee_fixed'] = (int) ($data['fee_fixed'] ?? 0);
        $data['reference_label'] = $data['requires_reference'] ? trim((string) ($data['reference_label'] ?? '')) : (trim((string) ($data['reference_label'] ?? '')) ?: null);

        $display = (array) ($this->validated('display') ?? []);
        $data['display'] = array_filter([
            'description_pos' => trim((string) ($display['description_pos'] ?? '')) ?: null,
            'description_online' => trim((string) ($display['description_online'] ?? '')) ?: null,
            'instructions' => trim((string) ($display['instructions'] ?? '')) ?: null,
            'highlight' => (bool) ($display['highlight'] ?? false) ?: null,
        ], fn ($value) => $value !== null) ?: null;

        $data['availability'] = self::cleanAvailability((array) ($this->validated('availability') ?? []));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $availability
     * @return array<string, mixed>|null
     */
    public static function cleanAvailability(array $availability): ?array
    {
        $ints = fn ($values) => array_values(array_unique(array_map('intval', (array) $values)));
        $clean = [];

        foreach (['channels', 'delivery_modes'] as $key) {
            if (! empty($availability[$key])) {
                $clean[$key] = array_values(array_unique(array_map('strval', (array) $availability[$key])));
            }
        }

        foreach (['stores', 'registers'] as $key) {
            if (! empty($availability[$key])) {
                $clean[$key] = $ints($availability[$key]);
            }
        }

        if (! empty($availability['channel_stores']['online'])) {
            $clean['channel_stores'] = ['online' => $ints($availability['channel_stores']['online'])];
        }

        if (! empty($availability['schedule'])) {
            $clean['schedule'] = array_values(array_map(fn (array $slot) => [
                'days' => $ints($slot['days'] ?? []),
                'from' => PaymentMethodAvailability::time($slot['from'] ?? null) ?? '00:00',
                'to' => PaymentMethodAvailability::time($slot['to'] ?? null) ?? '24:00',
            ], (array) $availability['schedule']));
        }

        foreach (['valid_from', 'valid_until'] as $key) {
            if (! empty($availability[$key])) {
                $clean[$key] = (string) $availability[$key];
            }
        }

        foreach (['min_amount', 'max_amount'] as $key) {
            if (isset($availability[$key]) && (int) $availability[$key] > 0) {
                $clean[$key] = (int) $availability[$key];
            }
        }

        $customer = array_filter((array) ($availability['customer'] ?? []), fn ($value) => (bool) $value);

        if ($customer !== []) {
            $clean['customer'] = array_map(fn () => true, $customer);
        }

        if (array_key_exists('combinable', $availability) && $availability['combinable'] !== null && ! $availability['combinable']) {
            $clean['combinable'] = false;
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre que lee el cajero o el cliente.',
            'kind.in' => 'Ese tipo de medio no se puede crear.',
            'gateway_account_id.required' => 'Elige con qué cuenta de pasarela se cobra.',
            'gateway_account_id.exists' => 'Esa cuenta de pasarela no es de esta empresa.',
            'fee_percent.max' => 'La comisión va de 0 % a 20 %.',
            'availability.valid_until.after_or_equal' => 'La vigencia termina antes de empezar.',
            'availability.schedule.*.days.required' => 'Elige los días de la franja.',
            'availability.schedule.*.from.date_format' => 'Usa la hora como 07:00.',
            'availability.schedule.*.to.regex' => 'Usa la hora como 21:00.',
        ];
    }
}
