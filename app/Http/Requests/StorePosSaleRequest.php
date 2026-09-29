<?php

namespace App\Http\Requests;

use App\Http\Controllers\Pos\AuthorizationController;
use App\Models\SalePayment;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.sale.create') ?? false;
    }

    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'items' => ['required', 'array', 'min:1'],
            // Cada linea es un articulo o un servicio, nunca los dos (se revisa en `withValidator`).
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'items.*.service_id' => [
                'nullable', 'integer',
                Rule::exists('services', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')),
            ],
            // Los articulos que de verdad lleva el servicio en esta venta. Uno del maestro que no
            // venga es un articulo quitado. Los precios los pone el servidor.
            'items.*.components' => ['nullable', 'array', 'max:60'],
            'items.*.components.*.product_id' => ['required', 'integer'],
            'items.*.components.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.components.*.discount' => ['nullable', 'integer', 'min:0'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'items.*.discount' => ['nullable', 'integer', 'min:0'],
            // El cajero puede decidir no aplicarle la promocion a una linea.
            'items.*.skip_promotion' => ['nullable', 'boolean'],
            // Hasta cuatro pagos: uno por medio. El reparto se combina, no se repite.
            'payments' => ['required', 'array', 'min:1', 'max:'.count($this->paymentMethods())],
            'payments.*.method' => ['required', 'distinct', Rule::in($this->paymentMethods())],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.received_amount' => ['nullable', 'integer', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'note' => ['nullable', 'string', 'max:160'],

            // La caja en la que se esta cobrando. Viaja con la venta porque en el mostrador se
            // puede estar vendiendo en una que abrio otro, y la plata tiene que entrar al
            // cajon correcto: resolverla de nuevo en el servidor podria dar otra.
            'register_session_id' => [
                'nullable', 'integer',
                Rule::exists('register_sessions', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'open')),
            ],

            // A quien se le atribuye la venta. Sin esto, es quien abrio la caja.
            'seller_employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'active')),
            ],
        ];
    }

    /**
     * Medios que admite el cobro. El cambio por devolucion solo lo trae `ExchangePosSaleRequest`.
     *
     * @return list<string>
     */
    protected function paymentMethods(): array
    {
        return SalePayment::POS_METHODS;
    }

    /**
     * Datos de la venta con el supervisor que autorizo, si hay una autorizacion vigente.
     *
     * @return array<string, mixed>
     */
    public function saleData(): array
    {
        $data = $this->validated();
        $needsCredit = collect($data['payments'] ?? [])->contains(fn ($payment) => ($payment['method'] ?? null) === SalePayment::METHOD_CREDIT);
        $action = $needsCredit ? 'credit' : 'discount';

        $data['approver'] = AuthorizationController::approverFor($this, $action);

        // Una venta puede necesitar varias autorizaciones: descuento y agregar un articulo a un
        // servicio, por ejemplo. Cada una con quien la dio.
        $data['approvers'] = collect(array_keys(AuthorizationController::ACTIONS))
            ->mapWithKeys(fn (string $name) => [$name => AuthorizationController::approverFor($this, $name)])
            ->filter()
            ->all();

        return $data;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $product = ! empty($item['product_id']);
                $service = ! empty($item['service_id']);

                if ($product === $service) {
                    $validator->errors()->add("items.{$index}", 'Cada linea del tiquete es un articulo o un servicio.');
                }

                if ($product && isset($item['components'])) {
                    $validator->errors()->add("items.{$index}", 'Solo un servicio lleva articulos adentro.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required' => 'El tiquete no tiene articulos.',
            'items.*.quantity.gt' => 'Las cantidades deben ser mayores que cero.',
            'payments.required' => 'Falta registrar el cobro.',
            'payments.max' => 'Un cobro admite como maximo un pago por medio.',
            'payments.*.method.distinct' => 'Hay dos pagos con el mismo medio.',
            'payments.*.amount.min' => 'Un pago en cero no se registra: quitalo del cobro.',
            'register_session_id.exists' => 'Esa caja ya no esta abierta. Recarga el punto de venta.',
            'seller_employee_id.exists' => 'Ese vendedor no existe o ya no esta activo.',
        ];
    }
}
