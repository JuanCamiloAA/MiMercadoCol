<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceComponent;
use App\Support\ScanCodeGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Guardar, duplicar y borrar el maestro de servicios.
 *
 * Aqui se imponen las reglas que no dependen de la pantalla: sin componentes el servicio se
 * cobra a precio cerrado, con precio variable no se publica en linea y su precio fijo es cero.
 * Los componentes se reemplazan completos en cada guardado: la venta ya guardo su foto.
 */
class ServiceManager
{
    public function __construct(protected ImageService $images) {}

    /**
     * @param  array<string, mixed>  $data  Campos del servicio y `components`: list<{product_id, quantity, unit_price?, returnable_by_default}>
     */
    public function save(?Service $service, array $data, int $companyId, ?UploadedFile $image = null): Service
    {
        $components = array_values($data['components'] ?? []);
        unset($data['components'], $data['image']);

        $this->assertComponents($components, $companyId);

        if ($components === []) {
            $data['pricing_mode'] = Service::PRICING_BUNDLED;
        }

        if (! empty($data['open_price'])) {
            // El precio se digita en la caja: el del maestro no significa nada y no se publica.
            $data['price'] = 0;
            $data['online_price'] = null;
            $data['is_online_visible'] = false;
            $data['is_featured'] = false;
        }

        return DB::transaction(function () use ($service, $data, $components, $companyId, $image) {
            $service ??= new Service(['company_id' => $companyId]);
            $service->fill($data);
            $service->company_id = $companyId;
            $service->save();

            $service->components()->delete();
            $itemized = $service->isItemized();

            $families = Product::query()->withoutGlobalScopes()
                ->whereIn('id', array_map(fn ($component) => (int) $component['product_id'], $components))
                ->pluck('product_family_id', 'id');

            foreach ($components as $index => $component) {
                // El articulo completo: se guarda su familia y la presentacion por defecto. Se
                // cobra al precio de la que se elija al vender, asi que no lleva precio fijo.
                $familyId = ! empty($component['family']) ? $families->get((int) $component['product_id']) : null;

                ServiceComponent::create([
                    'service_id' => $service->id,
                    'product_id' => (int) $component['product_id'],
                    'product_family_id' => $familyId ? (int) $familyId : null,
                    'quantity' => round((float) $component['quantity'], 3),
                    // El precio fijo solo significa algo cobrando por articulos; en precio cerrado valen $0.
                    'unit_price' => $itemized && ! $familyId && isset($component['unit_price']) && $component['unit_price'] !== '' ? max(0, (int) $component['unit_price']) : null,
                    'returnable_by_default' => (bool) ($component['returnable_by_default'] ?? true),
                    'sort_order' => $index,
                ]);
            }

            if ($image && $image->isValid()) {
                $stored = $this->images->storeProductImage($image, $companyId, 'services');
                Storage::disk('public')->delete(array_filter([$service->image_path, $service->thumb_path]));
                $service->update(['image_path' => $stored['path'], 'thumb_path' => $stored['thumb_path']]);
            }

            return $service->refresh()->load('components.product');
        });
    }

    /**
     * Una copia inactiva, con el codigo con sufijo y sin codigo de barras: el escaner no puede
     * tener dos respuestas.
     */
    public function duplicate(Service $service): Service
    {
        $service->loadMissing('components');

        return DB::transaction(function () use ($service) {
            $copy = $service->replicate(['image_path', 'thumb_path']);
            $copy->code = $this->freeCode($service->company_id, $service->code);
            $copy->barcode = null;
            $copy->name = mb_substr($service->name.' (copia)', 0, 160);
            $copy->is_active = false;
            $copy->is_online_visible = false;
            $copy->is_featured = false;
            $copy->save();

            foreach ($service->components as $component) {
                ServiceComponent::create([
                    'service_id' => $copy->id,
                    'product_id' => $component->product_id,
                    'product_family_id' => $component->product_family_id,
                    'quantity' => $component->quantity,
                    'unit_price' => $component->unit_price,
                    'returnable_by_default' => $component->returnable_by_default,
                    'sort_order' => $component->sort_order,
                ]);
            }

            return $copy->load('components');
        });
    }

    /**
     * Borrado suave. El codigo y el codigo de barras se liberan para que se puedan volver a usar;
     * las ventas pasadas no pierden nada porque guardaron su propia foto del servicio.
     */
    public function delete(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $service->forceFill([
                'code' => mb_substr($service->code, 0, 28).'~'.$service->id,
                'barcode' => null,
                'is_active' => false,
                'is_online_visible' => false,
                'is_featured' => false,
            ])->save();

            $service->delete();
        });
    }

    /**
     * Articulos de la empresa, activos, sin repetir; cantidad positiva con hasta tres decimales,
     * entera si el articulo no se vende por peso.
     *
     * @param  list<array<string, mixed>>  $components
     */
    public function assertComponents(array $components, int $companyId): void
    {
        $ids = array_map(fn ($component) => (int) ($component['product_id'] ?? 0), $components);

        $products = Product::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $errors = [];
        $seen = [];
        $families = [];

        // Si un articulo completo y una de sus presentaciones puntuales van en el mismo servicio.
        $wholeFamilies = collect($components)
            ->filter(fn ($component) => ! empty($component['family']))
            ->map(fn ($component) => $products->get((int) ($component['product_id'] ?? 0))?->product_family_id)
            ->filter()
            ->all();

        foreach ($components as $index => $component) {
            $product = $products->get((int) ($component['product_id'] ?? 0));

            if ($product && empty($component['family']) && $product->product_family_id !== null && in_array($product->product_family_id, $wholeFamilies, true)) {
                $errors["components.{$index}.product_id"] = "{$product->name} ya entra como el artículo completo: quita uno de los dos.";

                continue;
            }
            $key = "components.{$index}";

            if (! $product || ! $product->is_active) {
                $errors["{$key}.product_id"] = 'Ese artículo no existe, está inactivo o no es de tu empresa.';

                continue;
            }

            if (isset($seen[$product->id])) {
                $errors["{$key}.product_id"] = "{$product->name} ya está en el servicio: suma la cantidad en un solo renglón.";

                continue;
            }

            $seen[$product->id] = true;

            // Un articulo completo va una sola vez, y no junto con una de sus presentaciones:
            // al vender no se sabria a cual de los dos corresponde la que se elija.
            if (! empty($component['family'])) {
                if ($product->product_family_id === null) {
                    $errors["{$key}.family"] = "{$product->name} no tiene subartículos: va como artículo puntual.";

                    continue;
                }

                if (isset($families[$product->product_family_id])) {
                    $errors["{$key}.product_id"] = 'Ese artículo ya está en el servicio.';

                    continue;
                }

                $families[$product->product_family_id] = true;
            }

            $quantity = (float) ($component['quantity'] ?? 0);

            if ($quantity <= 0 || round($quantity, 3) !== $quantity) {
                $errors["{$key}.quantity"] = "La cantidad de {$product->name} debe ser mayor que cero, con máximo tres decimales.";

                continue;
            }

            if (! $product->sold_by_weight && floor($quantity) !== $quantity) {
                $errors["{$key}.quantity"] = "{$product->name} se vende por unidad: la cantidad debe ser entera.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function freeCode(int $companyId, string $code): string
    {
        $base = mb_substr($code, 0, 34);

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";

            if (ScanCodeGuard::forService($companyId, $candidate) === null
                && ! Service::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return mb_substr($base, 0, 30).'-'.bin2hex(random_bytes(3));
    }
}
