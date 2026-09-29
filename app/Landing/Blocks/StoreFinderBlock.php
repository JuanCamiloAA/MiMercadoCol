<?php

namespace App\Landing\Blocks;

/**
 * El buscador de sede cercana. Obligatorio: es la puerta de entrada a todas las tiendas. Las
 * sedes las trae `LandingRenderer`: solo las que pueden vender hoy.
 */
class StoreFinderBlock extends LandingBlock
{
    public function type(): string
    {
        return 'store_finder';
    }

    public function label(): string
    {
        return 'Buscador de tiendas';
    }

    public function icon(): string
    {
        return 'MapPin';
    }

    public function help(): string
    {
        return 'Las sedes que venden en línea, ordenadas por cercanía si el visitante comparte su ubicación.';
    }

    public function unique(): bool
    {
        return true;
    }

    public function deletable(): bool
    {
        return false;
    }

    public function hideWarning(): ?string
    {
        return 'Sin el buscador nadie puede elegir tienda desde la landing, y «Cambiar de tienda» en las tiendas lleva aquí.';
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 200],
            'default_city' => ['type' => 'text', 'label' => 'Ciudad por defecto', 'max' => 60, 'help' => 'La que se filtra al entrar; vacío muestra todas.'],
            'show_hours' => ['type' => 'toggle', 'label' => 'Mostrar horario y «Abierta ahora»'],
            'show_distance' => ['type' => 'toggle', 'label' => 'Mostrar la distancia'],
            'empty_text' => ['type' => 'text', 'label' => 'Texto cuando no hay tiendas', 'max' => 120],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Encuentra tu tienda',
            'text' => 'Comparte tu ubicación o busca por ciudad o barrio. Cada tienda tiene sus precios y su domicilio.',
            'default_city' => '',
            'show_hours' => true,
            'show_distance' => true,
            'empty_text' => 'Todavía no hay tiendas en línea aquí.',
        ];
    }
}
