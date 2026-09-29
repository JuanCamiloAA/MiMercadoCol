<?php

namespace App\Landing\Blocks;

/** La portada: mensaje principal, imagen y el botón al buscador. */
class HeroBlock extends LandingBlock
{
    public function type(): string
    {
        return 'hero';
    }

    public function label(): string
    {
        return 'Portada';
    }

    public function icon(): string
    {
        return 'Image';
    }

    public function help(): string
    {
        return 'Lo primero que se ve: título, texto, imagen y dos botones.';
    }

    public function fields(): array
    {
        return [
            'eyebrow' => ['type' => 'text', 'label' => 'Antetítulo', 'max' => 40],
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 90],
            'subtitle' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 240],
            'image' => ['type' => 'image', 'label' => 'Imagen'],
            'cta_label' => ['type' => 'text', 'label' => 'Botón principal', 'max' => 30],
            'cta_url' => ['type' => 'url', 'label' => 'Destino del botón principal'],
            'secondary_label' => ['type' => 'text', 'label' => 'Botón secundario', 'max' => 30],
            'secondary_url' => ['type' => 'url', 'label' => 'Destino del botón secundario'],
            'align' => ['type' => 'select', 'label' => 'Alineación', 'options' => [
                ['value' => 'left', 'label' => 'A la izquierda, con imagen al lado'],
                ['value' => 'center', 'label' => 'Centrado'],
            ]],
        ];
    }

    public function defaults(): array
    {
        return [
            'eyebrow' => 'Mercados de barrio en línea',
            'title' => 'Compra en el mercado de tu barrio, sin salir de casa',
            'subtitle' => 'Elige la tienda más cercana, arma tu pedido y recíbelo en la puerta. Con los precios de la tienda, sin intermediarios.',
            'image' => null,
            'cta_label' => 'Buscar mi tienda',
            'cta_url' => '#tiendas',
            'secondary_label' => 'Tengo un mercado',
            'secondary_url' => '/contratar',
            'align' => 'left',
        ];
    }
}
