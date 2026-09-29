<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use App\Storefront\Targets;

/**
 * Franja de llamado: un texto, un icono y un boton. «Pide por WhatsApp», «Crea tu cuenta».
 *
 * Con destino WhatsApp el numero es el de la sede (el del pie, o el telefono de su ficha).
 */
class CtaBandBlockData extends BlockData
{
    public function type(): string
    {
        return 'cta_band';
    }

    public function label(): string
    {
        return 'Franja con botón';
    }

    public function icon(): string
    {
        return 'Megaphone';
    }

    public function help(): string
    {
        return 'Un llamado corto con su botón: pedir por WhatsApp, crear la cuenta, ver las ofertas.';
    }

    public function pages(): array
    {
        return StorefrontBlock::PAGES;
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'icon' => ['type' => 'icon', 'label' => 'Ícono'],
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60, 'required' => true],
            'text' => ['type' => 'text', 'label' => 'Texto', 'max' => 120],
            'button_label' => ['type' => 'text', 'label' => 'Texto del botón', 'max' => 30],
            'target' => [
                'type' => 'target',
                'label' => 'A dónde lleva',
                'help' => 'Con WhatsApp se abre la conversación con el número de la sede; el texto es el mensaje inicial.',
                'kinds' => self::targetKinds($context, whatsapp: true),
            ],
            'tone' => [
                'type' => 'select',
                'label' => 'Color',
                'options' => self::options(['accent' => 'De la marca', 'soft' => 'Suave', 'dark' => 'Oscuro', 'deal' => 'De oferta']),
            ],
        ];
    }

    public function defaults(): array
    {
        return [
            'icon' => 'WhatsappLogo',
            'title' => '¿Prefieres pedir por WhatsApp?',
            'text' => 'Escríbenos y te armamos el pedido.',
            'button_label' => 'Escribir',
            'target' => ['kind' => Targets::KIND_WHATSAPP, 'value' => 'Hola, quiero hacer un pedido.'],
            'tone' => 'soft',
        ];
    }

    public function summary(array $payload): string
    {
        $title = $this->short($payload['title'] ?? '');

        return $title === '' ? 'Franja — sin título' : 'Franja — '.$title;
    }

    public function isEmpty(array $payload): bool
    {
        return trim((string) ($payload['title'] ?? '')) === '';
    }

    protected function ownIssues(array $payload, array $media): array
    {
        return trim((string) ($payload['title'] ?? '')) === ''
            ? [['level' => 'block', 'message' => 'La franja necesita un título.']]
            : [];
    }
}
