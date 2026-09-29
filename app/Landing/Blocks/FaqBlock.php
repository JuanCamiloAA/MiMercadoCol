<?php

namespace App\Landing\Blocks;

/** Preguntas frecuentes. */
class FaqBlock extends LandingBlock
{
    public function type(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'Preguntas frecuentes';
    }

    public function icon(): string
    {
        return 'Question';
    }

    public function help(): string
    {
        return 'Preguntas y respuestas que se despliegan.';
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
            'items' => self::repeater('Preguntas', 'pregunta', [
                'question' => ['type' => 'text', 'label' => 'Pregunta', 'max' => 140],
                'answer' => ['type' => 'textarea', 'label' => 'Respuesta', 'max' => 600],
            ], 12),
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Preguntas frecuentes',
            'items' => [
                ['question' => '¿Quién me vende?', 'answer' => 'La tienda que elijas. Cada mercado pone sus precios, su domicilio y sus medios de pago.'],
                ['question' => '¿Necesito una cuenta por tienda?', 'answer' => 'Sí: tu cuenta es con cada mercado, porque ahí queda tu historial y, si te lo dan, tu crédito.'],
                ['question' => 'Tengo un mercado, ¿cómo empiezo?', 'answer' => 'Elige un plan en «Contratar». Algunos tienen prueba gratis; con otros pagas en línea y tu cuenta queda lista en el acto.'],
            ],
        ];
    }
}
