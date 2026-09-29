<?php

namespace App\Services\Membership;

/**
 * Las funciones que un plan puede incluir. Solo lo que DE VERDAD se hace cumplir: una funcion
 * que ninguna ruta revisa no se crea (misma regla que los permisos).
 */
final class MembershipFeatures
{
    public const ONLINE_STORE = 'online_store';

    public const PROMOTIONS = 'promotions';

    public const MULTI_WAREHOUSE = 'multi_warehouse';

    public const CATALOG_IMPORT = 'catalog_import';

    /** Clave => [nombre, donde se hace cumplir]. */
    public const CATALOGUE = [
        self::ONLINE_STORE => [
            'label' => 'Tienda en línea',
            'description' => 'La tienda web de cada sede y su editor; aparece en la landing.',
        ],
        self::PROMOTIONS => [
            'label' => 'Promociones y cupones',
            'description' => 'Precios especiales, lleva N paga M, combos y cupones.',
        ],
        self::MULTI_WAREHOUSE => [
            'label' => 'Varias bodegas por sede',
            'description' => 'Bodegas de almacenaje además de la principal, con traslados.',
        ],
        self::CATALOG_IMPORT => [
            'label' => 'Importar catálogo externo',
            'description' => 'Traer artículos por código de barras desde Open Food Facts.',
        ],
    ];

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::CATALOGUE as $key => $feature) {
            $options[] = ['value' => $key, 'label' => $feature['label'], 'description' => $feature['description']];
        }

        return $options;
    }

    /**
     * @param  array<int, mixed>|null  $features
     * @return list<string>
     */
    public static function sanitize(?array $features): array
    {
        return array_values(array_intersect(array_keys(self::CATALOGUE), array_map('strval', $features ?? [])));
    }
}
