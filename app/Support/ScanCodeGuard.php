<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Service;

/**
 * El escaner de la caja no puede tener dos respuestas.
 *
 * Un codigo (SKU o codigo de barras de un articulo; codigo o codigo de barras de un servicio;
 * codigo de referencia de un articulo con subarticulos) apunta a una sola cosa dentro de la
 * empresa. Lo usan los formularios y las importaciones de articulos, servicios y familias, en
 * todos los sentidos. El codigo de una familia no cobra nada: en caja ofrece elegir el subarticulo.
 */
final class ScanCodeGuard
{
    /**
     * Por que ese codigo no le sirve a un servicio, o null si esta libre.
     */
    public static function forService(int $companyId, ?string $value, ?int $ignoreServiceId = null): ?string
    {
        $value = self::clean($value);

        if ($value === null) {
            return null;
        }

        $product = Product::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('sku', $value)->orWhere('barcode', $value))
            ->first(['id', 'name', 'sku']);

        if ($product) {
            return "El código {$value} ya es del artículo {$product->name}: el escáner de la caja no sabría cuál cobrar.";
        }

        if ($family = self::family($companyId, $value)) {
            return "El código {$value} ya es del artículo con subartículos {$family->name}.";
        }

        $service = Service::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->when($ignoreServiceId, fn ($query, $id) => $query->whereKeyNot($id))
            ->where(fn ($query) => $query->where('code', $value)->orWhere('barcode', $value))
            ->first(['id', 'name']);

        return $service ? "El código {$value} ya es del servicio {$service->name}." : null;
    }

    /**
     * Por que ese SKU o codigo de barras no le sirve a un articulo porque es de un servicio.
     */
    public static function forProduct(int $companyId, ?string $value): ?string
    {
        $value = self::clean($value);

        if ($value === null) {
            return null;
        }

        $service = Service::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('code', $value)->orWhere('barcode', $value))
            ->first(['id', 'name']);

        if ($service) {
            return "El código {$value} ya es del servicio {$service->name}: el escáner de la caja no sabría cuál cobrar.";
        }

        $family = self::family($companyId, $value);

        return $family ? "El código {$value} ya es del artículo con subartículos {$family->name}." : null;
    }

    /**
     * Por que ese codigo de referencia no le sirve a una familia: es de un articulo, de un
     * servicio o de otra familia.
     */
    public static function forFamily(int $companyId, ?string $value, ?int $ignoreFamilyId = null): ?string
    {
        $value = self::clean($value);

        if ($value === null) {
            return null;
        }

        $product = Product::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('sku', $value)->orWhere('barcode', $value))
            ->first(['id', 'name']);

        if ($product) {
            return "El código {$value} ya es del artículo {$product->name}.";
        }

        $service = Service::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('code', $value)->orWhere('barcode', $value))
            ->first(['id', 'name']);

        if ($service) {
            return "El código {$value} ya es del servicio {$service->name}.";
        }

        $family = self::family($companyId, $value, $ignoreFamilyId);

        return $family ? "El código {$value} ya es del artículo con subartículos {$family->name}." : null;
    }

    /** Regla de validacion para el codigo de referencia de una familia. */
    public static function familyRule(int $companyId, ?int $ignoreFamilyId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($companyId, $ignoreFamilyId) {
            $message = self::forFamily($companyId, is_scalar($value) ? (string) $value : null, $ignoreFamilyId);

            if ($message !== null) {
                $fail($message);
            }
        };
    }

    /** Regla de validacion para un FormRequest de servicios. */
    public static function serviceRule(int $companyId, ?int $ignoreServiceId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($companyId, $ignoreServiceId) {
            $message = self::forService($companyId, is_scalar($value) ? (string) $value : null, $ignoreServiceId);

            if ($message !== null) {
                $fail($message);
            }
        };
    }

    /** Regla de validacion para un FormRequest de articulos. */
    public static function productRule(int $companyId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($companyId) {
            $message = self::forProduct($companyId, is_scalar($value) ? (string) $value : null);

            if ($message !== null) {
                $fail($message);
            }
        };
    }

    private static function family(int $companyId, string $value, ?int $ignoreFamilyId = null): ?ProductFamily
    {
        return ProductFamily::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('code', $value)
            ->when($ignoreFamilyId, fn ($query, $id) => $query->whereKeyNot($id))
            ->first(['id', 'name']);
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
