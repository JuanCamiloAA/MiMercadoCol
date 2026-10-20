<?php

namespace App\Landing\Blocks;

/** Encabezado: logo, enlaces, «Ingresar como empresa» y «Contratar». Obligatorio. */
class HeaderBlock extends LandingBlock
{
    public function type(): string
    {
        return 'header';
    }

    public function label(): string
    {
        return 'Encabezado';
    }

    public function icon(): string
    {
        return 'Browser';
    }

    public function help(): string
    {
        return 'El logo, los enlaces de arriba y el acceso de las empresas. Con sesión de personal, el botón dice «Ir al panel».';
    }

    public function unique(): bool
    {
        return true;
    }

    public function deletable(): bool
    {
        return false;
    }

    public function hideable(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            'logo' => ['type' => 'image', 'label' => 'Logo', 'preview' => 'logo', 'help' => 'Si no hay, se usa el de la marca de la plataforma (Ajustes) y, sin él, su nombre.'],
            'logo_dark' => ['type' => 'image', 'label' => 'Logo para fondo oscuro', 'preview' => 'logo-dark', 'help' => 'Para quien ve la página en modo oscuro. Si no hay, el de la marca de la plataforma y, sin él, el de arriba.'],
            'brand_name' => ['type' => 'text', 'label' => 'Nombre', 'max' => 40, 'help' => 'Vacío: el nombre de la plataforma.'],
            'links' => self::repeater('Enlaces', 'enlace', [
                'label' => ['type' => 'text', 'label' => 'Texto', 'max' => 24],
                'url' => ['type' => 'url', 'label' => 'Destino', 'help' => '#tiendas, #planes, /contratar…'],
            ], 5),
            'login_label' => ['type' => 'text', 'label' => 'Botón de acceso de empresas', 'max' => 30],
            'cta_label' => ['type' => 'text', 'label' => 'Botón destacado', 'max' => 24],
            'cta_url' => ['type' => 'url', 'label' => 'Destino del botón destacado'],
        ];
    }

    public function defaults(): array
    {
        return [
            'logo' => null,
            'logo_dark' => null,
            'brand_name' => '',
            'links' => [
                ['label' => 'Tiendas', 'url' => '#tiendas'],
                ['label' => 'Cómo funciona', 'url' => '#como-funciona'],
                ['label' => 'Planes', 'url' => '#planes'],
            ],
            'login_label' => 'Ingresar como empresa',
            'cta_label' => 'Contratar',
            'cta_url' => '/contratar',
        ];
    }

    /** El encabezado no lleva la pestaña «Diseño»: es fijo al desplazar y una envoltura le quitaria lo fijo. */
    public function supportsAppearance(): bool
    {
        return false;
    }
}
