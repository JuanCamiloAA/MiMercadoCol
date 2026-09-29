<?php

namespace App\Landing\Blocks;

/**
 * Las empresas presentes. Las trae `LandingRenderer`: empresas activas con al menos una sede
 * vendible y `show_on_landing` encendido.
 */
class CompaniesShowcaseBlock extends LandingBlock
{
    public function type(): string
    {
        return 'companies_showcase';
    }

    public function label(): string
    {
        return 'Tiendas presentes';
    }

    public function icon(): string
    {
        return 'Buildings';
    }

    public function help(): string
    {
        return 'Los mercados que venden en la plataforma. Cada empresa puede ocultarse desde Plataforma > Empresas.';
    }

    public function unique(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 200],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Mercados que ya venden aquí',
            'text' => 'Tiendas de barrio de verdad, con su gente y sus precios.',
        ];
    }
}
