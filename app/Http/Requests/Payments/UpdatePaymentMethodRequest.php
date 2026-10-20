<?php

namespace App\Http\Requests\Payments;

use App\Models\PaymentMethod;

/**
 * Editar un medio de pago. Uno del sistema (Efectivo, Saldo a favor) no cambia de tipo: el resto
 * del sistema cuenta con el.
 */
class UpdatePaymentMethodRequest extends StorePaymentMethodRequest
{
    protected function kinds(): array
    {
        /** @var PaymentMethod $method */
        $method = $this->route('method');

        return $method->is_system ? [$method->kind] : PaymentMethod::CONFIGURABLE_KINDS;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'kind.in' => 'Un medio del sistema no cambia de tipo.',
        ];
    }
}
