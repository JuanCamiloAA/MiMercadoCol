<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use App\Support\MediaVariants;

/**
 * Imagen y texto en dos columnas: «Agenda tu cita», «Cada vela se vierte a mano». En celular la
 * imagen va arriba. `reverse` pone la imagen a la derecha.
 */
class ImageTextBlockData extends BlockData
{
    public function type(): string
    {
        return 'image_text';
    }

    public function label(): string
    {
        return 'Imagen y texto';
    }

    public function icon(): string
    {
        return 'Article';
    }

    public function help(): string
    {
        return 'Una foto al lado de un texto con su botón: el taller, la asesoría, cómo llega el pedido.';
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_PRODUCT, StorefrontBlock::PAGE_CATEGORY];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            ...MediaVariants::field('media_id', ['type' => 'media', 'label' => 'Imagen', 'help' => 'Vertical o cuadrada se ve mejor.']),
            'reverse' => ['type' => 'toggle', 'label' => 'La imagen a la derecha'],
            'style' => [
                'type' => 'select',
                'label' => 'Estilo',
                'options' => self::options(['plain' => 'Sobre el fondo de la página', 'panel' => 'El texto en un panel de color suave']),
            ],
            'eyebrow' => ['type' => 'text', 'label' => 'Antetítulo', 'max' => 40],
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 80, 'required' => true],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 400, 'rows' => 4],
            'button_label' => ['type' => 'text', 'label' => 'Texto del botón', 'max' => 30],
            'target' => ['type' => 'target', 'label' => 'A dónde lleva', 'kinds' => self::targetKinds($context, whatsapp: true)],
        ];
    }

    public function defaults(): array
    {
        return [
            'media_id' => null,
            'media_mobile_id' => null,
            'reverse' => false,
            'style' => 'plain',
            'eyebrow' => null,
            'title' => '',
            'text' => null,
            'button_label' => null,
            'target' => null,
        ];
    }

    public function summary(array $payload): string
    {
        $title = $this->short($payload['title'] ?? '');

        return $title === '' ? 'Imagen y texto — sin título' : 'Imagen y texto — '.$title;
    }

    public function isEmpty(array $payload): bool
    {
        return empty($payload['media_id']) || trim((string) ($payload['title'] ?? '')) === '';
    }

    protected function ownIssues(array $payload, array $media): array
    {
        $issues = $this->imageIssues($payload['media_id'] ?? null, $media, 'La imagen y texto');
        array_push($issues, ...$this->variantIssues($this->fields(), 'media_id', $payload, $media, 'La imagen y texto'));

        if (trim((string) ($payload['title'] ?? '')) === '') {
            $issues[] = ['level' => 'block', 'message' => 'La imagen y texto necesita un título.'];
        }

        return $issues;
    }
}
