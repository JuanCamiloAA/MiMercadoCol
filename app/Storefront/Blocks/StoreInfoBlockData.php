<?php

namespace App\Storefront\Blocks;

/**
 * Datos de la sede.
 *
 * No se reescriben aqui: salen de la ficha de la sede, para que cambiar un telefono en un solo
 * sitio lo cambie en la tienda de las tres sedes.
 */
class StoreInfoBlockData extends BlockData
{
    public function type(): string
    {
        return 'store_info';
    }

    public function label(): string
    {
        return 'Datos de la tienda';
    }

    public function icon(): string
    {
        return 'Storefront';
    }

    public function help(): string
    {
        return 'Dirección, teléfono y horario, tomados de la ficha de la sede.';
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 40],
            'show_map' => ['type' => 'toggle', 'label' => 'Mostrar el mapa'],
            'note' => ['type' => 'text', 'label' => 'Nota al pie', 'max' => 120],
        ];
    }

    public function defaults(): array
    {
        return ['title' => 'Dónde estamos', 'show_map' => false, 'note' => null];
    }

    public function summary(array $payload): string
    {
        return 'Datos de la tienda — de la ficha de la sede';
    }
}
