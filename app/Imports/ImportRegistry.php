<?php

namespace App\Imports;

/**
 * Las maestras que se pueden importar.
 *
 * Esta lista es la unica fuente: de aqui salen los permisos `<modulo>.index.import` del
 * catalogo y las rutas. Agregar una maestra es escribir su clase y sumarla aqui.
 */
class ImportRegistry
{
    /** @var array<string, class-string<MasterImport>> */
    public const IMPORTS = [
        'products' => ProductImport::class,
        'services' => ServiceImport::class,
        'categories' => CategoryImport::class,
        'suppliers' => SupplierImport::class,
        'customers' => CustomerImport::class,
        'employees' => EmployeeImport::class,
        'stores' => StoreImport::class,
        'registers' => RegisterImport::class,
    ];

    public static function for(string $module): MasterImport
    {
        $class = self::IMPORTS[$module] ?? null;

        abort_if($class === null, 404, 'Esa maestra no se puede importar.');

        return new $class;
    }

    /** @return list<string> */
    public static function modules(): array
    {
        return array_keys(self::IMPORTS);
    }

    public static function supports(string $module): bool
    {
        return isset(self::IMPORTS[$module]);
    }
}
