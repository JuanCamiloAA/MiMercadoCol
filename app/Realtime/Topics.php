<?php

namespace App\Realtime;

use App\Models\CashMovement;
use App\Models\CatalogImport;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Models\CustomerPayment;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Models\ProductLot;
use App\Models\ProductStock;
use App\Models\Promotion;
use App\Models\PurchaseOrder;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\Store;
use App\Models\StoreCreditMovement;
use App\Models\StorefrontSuggestionDismissal;
use App\Models\NewsletterSubscription;
use App\Models\StoreSettingOverride;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\Warehouse;

/**
 * De que habla cada cambio y que escucha cada pantalla.
 *
 * Un tema es una familia de datos («inventario», «ventas»), no una tabla: lo que viaja por el
 * socket es «cambio el inventario de la sede 3», y cada pantalla decide si eso es lo que muestra.
 * Agregar un modelo al tiempo real es una linea en `MODELS`; agregar una pantalla, una en `ROUTES`.
 */
final class Topics
{
    /** Modelo => tema. Lo que no esta aqui no se transmite. */
    public const MODELS = [
        Product::class => 'products',
        ProductFamily::class => 'products',
        Category::class => 'products',
        ProductAttribute::class => 'products',
        ProductAttributeValue::class => 'products',
        ProductStock::class => 'inventory',
        StockMovement::class => 'inventory',
        ProductLot::class => 'inventory',
        Sale::class => 'sales',
        CustomerReturn::class => 'returns',
        Order::class => 'orders',
        PurchaseOrder::class => 'purchases',
        SupplierReturn::class => 'purchases',
        StockTransfer::class => 'transfers',
        StockTransferRequest::class => 'transfers',
        Customer::class => 'customers',
        CustomerBalance::class => 'customers',
        CustomerPayment::class => 'customers',
        StoreCreditMovement::class => 'customers',
        Supplier::class => 'suppliers',
        Promotion::class => 'promotions',
        Coupon::class => 'promotions',
        Service::class => 'services',
        Register::class => 'registers',
        RegisterSession::class => 'registers',
        CashMovement::class => 'registers',
        Employee::class => 'employees',
        User::class => 'users',
        Role::class => 'users',
        Setting::class => 'settings',
        StoreSettingOverride::class => 'settings',
        Store::class => 'stores',
        Warehouse::class => 'stores',
        PaymentMethod::class => 'payments',
        PaymentTerm::class => 'payments',
        PaymentTransaction::class => 'payments',
        StorefrontSuggestionDismissal::class => 'storefront',
        // De toda la empresa: un correo es uno solo aunque llegue por una sede.
        NewsletterSubscription::class => 'newsletter',
        CatalogImport::class => 'imports',
    ];

    /**
     * Los temas cuyas pantallas son de una sede: un cambio en otra sede no le interesa a quien
     * mira la suya. Los demas (clientes, empleados, catalogo) son de toda la empresa.
     */
    public const STORE_SCOPED = ['inventory', 'sales', 'returns', 'orders', 'purchases', 'registers', 'storefront'];

    /**
     * Prefijo del nombre de la ruta => temas que muestra. Gana el prefijo mas largo. Una pantalla
     * que no esta aqui no se refresca sola (el editor de la tienda, por ejemplo, lleva su propio
     * borrador y recargarlo debajo del que edita le cambiaria lo que esta escribiendo).
     */
    public const ROUTES = [
        'dashboard' => ['sales', 'orders', 'inventory', 'registers', 'products', 'customers', 'returns'],
        'pos' => ['products', 'inventory', 'promotions', 'services', 'registers', 'sales', 'customers', 'returns', 'payments', 'settings'],
        'products' => ['products', 'inventory', 'suppliers', 'promotions'],
        'categories' => ['products'],
        'attributes' => ['products'],
        'catalog' => ['imports', 'products'],
        'inventory' => ['inventory', 'products', 'transfers', 'purchases'],
        'transfers' => ['transfers', 'inventory', 'products'],
        'transfer_requests' => ['transfers', 'inventory', 'products'],
        'purchases' => ['purchases', 'inventory', 'products', 'suppliers'],
        'supplier_returns' => ['purchases', 'inventory', 'suppliers'],
        'suppliers' => ['suppliers', 'products'],
        'sales' => ['sales', 'returns', 'customers'],
        'customer_returns' => ['returns', 'sales', 'inventory'],
        'orders' => ['orders', 'inventory', 'products', 'customers', 'payments'],
        'customers' => ['customers', 'sales', 'returns'],
        'credit' => ['customers', 'sales'],
        'registers' => ['registers', 'sales'],
        'reports' => ['sales', 'orders', 'inventory', 'returns'],
        'promotions' => ['promotions', 'products'],
        'coupons' => ['promotions'],
        'services' => ['services', 'products'],
        'storefront.catalog' => ['products', 'inventory', 'sales', 'promotions', 'services', 'purchases', 'transfers', 'storefront'],
        'storefront.newsletter' => ['newsletter'],
        'employees' => ['employees', 'stores'],
        'users' => ['users', 'stores', 'employees'],
        'roles' => ['users'],
        'stores' => ['stores'],
        'settings' => ['settings', 'payments'],
        'payment_methods' => ['payments'],
        'payment_terms' => ['payments'],
    ];

    public static function forModel(object $model): ?string
    {
        return self::MODELS[$model::class] ?? null;
    }

    public static function isStoreScoped(string $topic): bool
    {
        return in_array($topic, self::STORE_SCOPED, true);
    }

    /**
     * Lo que escucha la pantalla de esa ruta.
     *
     * @return list<string>
     */
    public static function forRoute(?string $name): array
    {
        if ($name === null || $name === '') {
            return [];
        }

        $best = null;

        foreach (array_keys(self::ROUTES) as $prefix) {
            if (($name === $prefix || str_starts_with($name, $prefix.'.')) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? [] : self::ROUTES[$best];
    }
}
