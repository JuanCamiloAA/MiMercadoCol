<?php

namespace App\Http\Requests\Storefront\Settings;

/**
 * Que request valida cada clave de `storefront_settings`. Una clave nueva va aqui, en
 * `StorefrontSetting::KEYS` y con su defecto en `StorefrontManager::defaultSettings()`.
 */
final class SettingRequests
{
    /** @var array<string, class-string<StorefrontSettingRequest>> */
    public const MAP = [
        'delivery' => DeliverySettingRequest::class,
        'coverage' => CoverageSettingRequest::class,
        'slots' => SlotsSettingRequest::class,
        'payments' => PaymentsSettingRequest::class,
        'catalog' => CatalogSettingRequest::class,
        'texts' => TextsSettingRequest::class,
        'seo' => SeoSettingRequest::class,
        'topbar' => TopbarSettingRequest::class,
        'header' => HeaderSettingRequest::class,
        'nav' => NavSettingRequest::class,
        'mobile_nav' => MobileNavSettingRequest::class,
        'card' => CardSettingRequest::class,
        'cart_bar' => CartBarSettingRequest::class,
        'category_page' => CategoryPageSettingRequest::class,
        'product_page' => ProductPageSettingRequest::class,
        'search' => SearchSettingRequest::class,
        'footer' => FooterSettingRequest::class,
    ];

    /**
     * Resuelve y valida el request de una clave. Si no pasa, sale la excepcion de validacion
     * y el editor muestra los errores.
     */
    public static function validate(string $key): StorefrontSettingRequest
    {
        abort_unless(isset(self::MAP[$key]), 404, 'Ese ajuste no existe.');

        return app(self::MAP[$key]);
    }
}
