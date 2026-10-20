<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use App\Support\MediaVariants;

/**
 * Franja de marcas: los logos de lo que vende la tienda, en gris. Cada marca es un logo de la
 * biblioteca o, si no hay logo, su nombre escrito. En una plantilla solo valen medios de la
 * plataforma o texto (lo revisa `StorefrontTemplateDraftController`).
 */
class BrandLogosBlockData extends BlockData
{
    public function type(): string
    {
        return 'brand_logos';
    }

    public function label(): string
    {
        return 'Marcas';
    }

    public function icon(): string
    {
        return 'Medal';
    }

    public function help(): string
    {
        return 'Los logos de las marcas que vendes, en una franja gris que se desliza en el celular.';
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_CATEGORY];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 50, 'help' => 'Opcional.'],
            'items' => [
                'type' => 'repeater',
                'label' => 'Marcas',
                'singular' => 'marca',
                'max_items' => 12,
                'item' => [
                    // Un logo no cambia en celular, pero en modo oscuro casi siempre necesita su versión clara.
                    ...MediaVariants::field('media_id', ['type' => 'media', 'label' => 'Logo', 'help' => 'Con fondo transparente. Sin logo, va el nombre.'], MediaVariants::SURFACE_STORE, [MediaVariants::DARK]),
                    'text' => ['type' => 'text', 'label' => 'Nombre', 'max' => 30],
                    'target' => ['type' => 'target', 'label' => 'A dónde lleva', 'kinds' => self::targetKinds($context)],
                ],
            ],
        ];
    }

    public function defaults(): array
    {
        return ['title' => null, 'items' => []];
    }

    public function summary(array $payload): string
    {
        $count = count($payload['items'] ?? []);

        return 'Marcas — '.$count.' '.($count === 1 ? 'marca' : 'marcas');
    }

    public function isEmpty(array $payload): bool
    {
        return collect($payload['items'] ?? [])->filter(fn ($item) => is_array($item) && (! empty($item['media_id']) || trim((string) ($item['text'] ?? '')) !== ''))->isEmpty();
    }

    protected function ownIssues(array $payload, array $media): array
    {
        $issues = [];

        foreach ($payload['items'] ?? [] as $index => $item) {
            if (! empty($item['media_id'])) {
                array_push($issues, ...$this->imageIssues($item['media_id'], $media, 'La marca '.($index + 1)));
                array_push($issues, ...$this->variantIssues($this->fields()['items']['item'], 'media_id', $item, $media, 'La marca '.($index + 1)));
            }
        }

        return $issues;
    }
}
