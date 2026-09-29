<?php

namespace App\Http\Requests\Services;

use App\Models\Service;
use Illuminate\Validation\Validator;

/**
 * Editar un servicio. Editar no basta para cambiar precios: el precio, el precio en linea, el
 * costo, la forma de cobro y el precio de cada articulo dentro del servicio piden
 * `services.show.edit_price`. Sin el permiso se pueden guardar los demas campos, pero un precio
 * distinto al que ya tiene se rechaza.
 */
class UpdateServiceRequest extends StoreServiceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.index.edit') ?? false;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->user()->can('services.show.edit_price')) {
                return;
            }

            /** @var Service $service */
            $service = $this->route('service');

            $changed = (int) $this->input('price', $service->price) !== (int) $service->price
                || $this->normalized($this->input('online_price')) !== $service->online_price
                || ($this->has('cost') && $this->user()->can('services.show.view_cost') && (int) $this->input('cost') !== (int) $service->cost)
                || ($this->boolean('open_price') !== (bool) $service->open_price)
                || $this->pricingModeChanged($service)
                || $this->componentPricesChanged($service);

            if ($changed) {
                $validator->errors()->add('price', 'No tienes permiso para cambiar precios.');
            }
        });
    }

    /** Sin articulos el servicio queda en precio cerrado, pida lo que pida el formulario. */
    private function pricingModeChanged(Service $service): bool
    {
        $components = (array) $this->input('components', []);
        $mode = $components === [] ? Service::PRICING_BUNDLED : (string) $this->input('pricing_mode', $service->pricing_mode);

        return $mode !== $service->pricing_mode && $service->components()->exists();
    }

    /**
     * Un precio fijo nuevo, uno distinto o uno quitado en un articulo que sigue en el servicio.
     * Quitar el articulo entero no es cambiar su precio: es cambiar lo que consume.
     */
    private function componentPricesChanged(Service $service): bool
    {
        $itemized = $this->input('pricing_mode') === Service::PRICING_ITEMIZED;
        $current = $service->isItemized()
            ? $service->components()->pluck('unit_price', 'product_id')->all()
            : [];

        foreach ((array) $this->input('components', []) as $component) {
            $productId = (int) ($component['product_id'] ?? 0);
            $incoming = $itemized ? $this->normalized($component['unit_price'] ?? null) : null;
            $before = array_key_exists($productId, $current) ? $this->normalized($current[$productId]) : null;

            if ($incoming !== $before) {
                return true;
            }
        }

        return false;
    }

    protected function ignoredServiceId(): ?int
    {
        return $this->route('service')?->id;
    }

    private function normalized(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
