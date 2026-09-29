<?php

namespace App\Services\Storefront;

use App\Models\StorefrontBlock;
use App\Storefront\BlockCatalogue;
use App\Storefront\Blocks\BlockData;
use App\Storefront\Targets;

/**
 * Lo que el editor necesita y es igual en la tienda de una sede y en la plantilla de la
 * plataforma: el catalogo de bloques (filtrado por contexto), las opciones cerradas de la
 * estructura y la forma de las filas de bloques. Es un solo editor con dos contextos, no dos
 * editores.
 */
class StorefrontEditorPayload
{
    /**
     * Las listas cerradas de la estructura global, con su nombre en español.
     *
     * @return array<string, mixed>
     */
    public function structureOptions(string $context): array
    {
        $options = fn (array $map) => collect($map)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();

        return [
            'topbar_tones' => $options(StorefrontStructure::TOPBAR_TONES),
            'topbar_variables' => StorefrontStructure::TOPBAR_VARIABLES,
            'header_styles' => $options(StorefrontStructure::HEADER_STYLES),
            'logo_modes' => $options(StorefrontStructure::LOGO_MODES),
            'mobile_nav_items' => $options(StorefrontStructure::MOBILE_NAV_ITEMS),
            'sorts' => $options(StorefrontStructure::SORTS),
            'filters' => $options(StorefrontStructure::FILTERS),
            'social' => $options(StorefrontStructure::SOCIAL),
            'payment_logos' => $options(StorefrontStructure::PAYMENT_LOGOS),
            'pages' => Targets::pageOptions(),
            'target_kinds' => $context === BlockData::CONTEXT_TEMPLATE ? Targets::TEMPLATE_KINDS : Targets::KINDS,
            'block_pages' => $options(StorefrontBlock::PAGE_LABELS),
            'choices' => collect(StorefrontStructure::CHOICES)->map(fn (array $map) => collect($map)->map(fn (string $label, string|int $value) => ['value' => (string) $value, 'label' => $label])->values()->all())->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogue(string $context): array
    {
        return BlockCatalogue::toPayload($context);
    }

    /**
     * Una fila de bloque para la lista del editor, venga de la base o del borrador de una
     * plantilla (donde el id es su uuid).
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public function blockRow(array $block, int|string $id): array
    {
        $definition = BlockCatalogue::has($block['type'] ?? '') ? BlockCatalogue::for($block['type']) : null;
        $payload = $block['payload'] ?? [];
        $from = $block['visible_from'] ?? null;
        $to = $block['visible_to'] ?? null;

        return [
            'id' => $id,
            'uuid' => $block['uuid'] ?? (string) $id,
            'type' => $block['type'],
            'page' => $block['page'] ?? StorefrontBlock::PAGE_HOME,
            'sort_order' => (int) ($block['sort_order'] ?? 0),
            'is_visible' => (bool) ($block['is_visible'] ?? true),
            'visible_from' => $from ? substr((string) $from, 0, 16) : null,
            'visible_to' => $to ? substr((string) $to, 0, 16) : null,
            'devices' => $block['devices'] ?? 'all',
            'payload' => $payload,
            // El nombre con su regla: dos estantes tienen que distinguirse sin abrirlos.
            'summary' => $definition?->summary($payload) ?? $block['type'],
            'is_empty' => $definition?->isEmpty($payload) ?? false,
            'is_scheduled' => $from !== null || $to !== null,
            'is_live' => BlockData::itemIsLive(['visible_from' => $from, 'visible_to' => $to]) && (bool) ($block['is_visible'] ?? true),
        ];
    }
}
