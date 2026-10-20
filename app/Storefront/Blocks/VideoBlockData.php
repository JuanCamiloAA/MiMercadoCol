<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use App\Support\MediaVariants;
use App\Support\VideoOptions;

/**
 * Solo un video, como un GIF: se repite solo, sin sonido y sin barra. Con un titulo opcional
 * encima. Sin video no se pinta en la tienda.
 */
class VideoBlockData extends BlockData
{
    /** Las opciones de video que ofrece (`VideoOptions`). */
    public const VIDEO_OPTIONS = ['video_speed', 'video_autoplay', 'video_loop', 'video_radius', 'video_shadow', 'video_frame'];

    public function type(): string
    {
        return 'video';
    }

    public function label(): string
    {
        return 'Video';
    }

    public function icon(): string
    {
        return 'VideoCamera';
    }

    public function help(): string
    {
        return 'Un video que se repite solo, sin sonido ni barra, como un GIF.';
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_CATEGORY, StorefrontBlock::PAGE_SEARCH];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            ...MediaVariants::field('video_media_id', ['type' => 'video', 'label' => 'Video', 'required' => true, 'help' => 'MP4 o WebM, sin sonido. Que dure poco y pese menos de 8 MB: se repite solo.']),
            ...MediaVariants::field('poster_media_id', ['type' => 'media', 'label' => 'Imagen mientras carga', 'help' => 'Opcional. Un cuadro del video, para que el espacio no quede vacío.']),
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60, 'help' => 'Opcional.'],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 200, 'rows' => 2, 'help' => 'Opcional.'],
            ...VideoOptions::fields(self::VIDEO_OPTIONS),
        ];
    }

    public function defaults(): array
    {
        return [
            'video_media_id' => null,
            'poster_media_id' => null,
            'title' => null,
            'text' => null,
            ...VideoOptions::defaults(self::VIDEO_OPTIONS),
        ];
    }

    public function summary(array $payload): string
    {
        $title = $this->short($payload['title'] ?? null);

        return $title === '' ? 'Video' : 'Video — '.$title;
    }

    public function isEmpty(array $payload): bool
    {
        return empty($payload['video_media_id']);
    }

    protected function ownIssues(array $payload, array $media): array
    {
        return [
            ...$this->videoIssues($payload['video_media_id'] ?? null, $media, 'El bloque de video'),
            ...$this->variantIssues($this->fields(), 'video_media_id', $payload, $media, 'El bloque de video'),
            ...$this->variantIssues($this->fields(), 'poster_media_id', $payload, $media, 'La imagen del bloque de video'),
        ];
    }
}
