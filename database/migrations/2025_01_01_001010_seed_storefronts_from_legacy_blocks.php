<?php

use App\Models\Company;
use App\Models\Store;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Le da a cada sede su sitio, con lo que hoy se ve publicado.
 *
 * La portada era una sola para toda la empresa; ahora cada sede tiene la suya. Cada una arranca
 * con una copia de esa portada —borrador y version publicada— para que ningun cliente note el
 * cambio. A partir de aqui cada sede sigue su propio camino.
 *
 * El armador del retrato va escrito aqui a mano, y no llamando al servicio de publicacion, para
 * que esta migracion siga corriendo igual dentro de un ano aunque el servicio cambie.
 */
return new class extends Migration
{
    /** Los tipos viejos y su equivalente en el catalogo nuevo. */
    protected const TYPES = [
        'banner' => 'hero',
        'categories' => 'categories',
        'products' => 'product_grid',
        'notices' => 'benefits',
        'text' => 'rich_text',
        'store_info' => 'store_info',
    ];

    public function up(): void
    {
        $legacy = DB::table('storefront_blocks_legacy')->orderBy('position')->orderBy('id')->get();

        foreach (Store::query()->withoutGlobalScopes()->get() as $store) {
            $storefrontId = $this->createStorefront($store);
            $blocks = $legacy->where('company_id', $store->company_id)->values();

            $draft = [];
            $published = [];

            foreach ($blocks as $order => $block) {
                $uuid = (string) Str::uuid();

                $draft[] = $this->block($uuid, $block->type, $order, (bool) $block->is_visible, $block->data);

                if ($block->published_data !== null) {
                    $published[] = $this->block($uuid, $block->type, $order, (bool) $block->is_visible, $block->published_data);
                }
            }

            foreach ($draft as $block) {
                DB::table('storefront_blocks')->insert(array_merge($block, [
                    'storefront_id' => $storefrontId,
                    'payload' => json_encode($block['payload']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            if ($published !== []) {
                $this->publishFirstVersion($storefrontId, $published);
            }
        }
    }

    public function down(): void
    {
        DB::table('storefronts')->update(['published_version_id' => null]);
        DB::table('storefront_versions')->delete();
        DB::table('storefront_blocks')->delete();
        DB::table('storefront_settings')->delete();
        DB::table('storefront_themes')->delete();
        DB::table('storefronts')->delete();
    }

    protected function createStorefront(Store $store): int
    {
        $slug = $this->uniqueSlug($store);

        $storefrontId = DB::table('storefronts')->insertGetId([
            'company_id' => $store->company_id,
            'store_id' => $store->id,
            'slug' => $slug,
            'is_online' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $company = Company::query()->withoutGlobalScopes()->find($store->company_id);

        DB::table('storefront_themes')->insert([
            'storefront_id' => $storefrontId,
            'display_name' => $company?->name ?? $store->name,
            'accent' => '#0E6E4E',
            'scheme' => 'light',
            'font_pair' => 'moderna',
            'radius' => 'soft',
            'density' => 'cozy',
            'button_style' => 'solid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($this->defaultSettings($store) as $key => $value) {
            DB::table('storefront_settings')->insert([
                'storefront_id' => $storefrontId,
                'key' => $key,
                'value' => json_encode($value),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $storefrontId;
    }

    protected function uniqueSlug(Store $store): string
    {
        $base = Str::slug($store->name) ?: 'tienda';
        $slug = $base;
        $suffix = 2;

        while (DB::table('storefronts')->where('company_id', $store->company_id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function defaultSettings(Store $store): array
    {
        $fee = (int) (DB::table('settings')
            ->where('company_id', $store->company_id)
            ->where('key', 'store.delivery_fee')
            ->value('value') ?? 5000);

        $freeFrom = (int) (DB::table('settings')
            ->where('company_id', $store->company_id)
            ->where('key', 'store.free_delivery_from')
            ->value('value') ?? 0);

        return [
            'delivery' => [
                'enabled' => true,
                'fee' => $fee,
                'min_order_total' => 0,
                'free_from' => $freeFrom,
                'eta_minutes' => 40,
                'pickup' => false,
                'tip' => false,
            ],
            'coverage' => ['mode' => 'zones', 'radius_km' => 3],
            'slots' => ['enabled' => false],
            'payments' => [
                'cash_on_delivery' => true,
                'card_on_delivery' => true,
                'transfer' => false,
                'customer_credit' => true,
            ],
            'catalog' => ['show_out_of_stock' => true, 'price_per_unit' => true],
            'texts' => ['delivery_note' => null],
            'seo' => ['title' => $store->name, 'description' => null],
        ];
    }

    /**
     * Traduce un bloque viejo a la forma nueva.
     *
     * @return array<string, mixed>
     */
    protected function block(string $uuid, string $type, int $order, bool $visible, ?string $json): array
    {
        $data = json_decode($json ?? '{}', true) ?: [];

        return [
            'uuid' => $uuid,
            'type' => self::TYPES[$type] ?? 'rich_text',
            'sort_order' => $order,
            'is_visible' => $visible,
            'visible_from' => null,
            'visible_to' => null,
            'devices' => 'all',
            'payload' => $this->payload($type, $data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function payload(string $type, array $data): array
    {
        return match ($type) {
            'banner' => [
                'media_id' => null,
                // La imagen vieja era una ruta suelta; se conserva como respaldo visible.
                'legacy_image' => $data['image'] ?? null,
                'alt' => $data['title'] ?? 'Imagen del banner',
                'title' => $data['title'] ?? '',
                'subtitle' => $data['subtitle'] ?? null,
                'button_label' => $data['link']['label'] ?? null,
                'button_target' => ['kind' => 'url', 'value' => $data['link']['url'] ?? '/tienda'],
                'text_color' => ($data['tone'] ?? 'light') === 'dark' ? 'dark' : 'light',
            ],
            'categories' => [
                'source' => ($data['items'] ?? []) === [] ? 'all' : 'manual',
                'category_ids' => array_values(array_filter(array_map(
                    fn ($item) => isset($item['category_id']) ? (int) $item['category_id'] : null,
                    $data['items'] ?? []
                ))),
                'limit' => 12,
            ],
            'products' => [
                'title' => $data['title'] ?? 'Artículos',
                'source' => $data['source'] ?? 'featured',
                'value' => $data['category_id'] ?? $data['promotion_id'] ?? null,
                'product_ids' => array_map('intval', $data['product_ids'] ?? []),
                'limit' => (int) ($data['limit'] ?? 8),
                'columns' => ($data['layout'] ?? 'carousel') === 'grid' ? 2 : 3,
                'show_price_per_unit' => true,
            ],
            'notices' => [
                'items' => array_map(fn ($item) => [
                    'icon' => $item['icon'] ?? 'Truck',
                    'title' => $item['title'] ?? '',
                    'text' => $item['text'] ?? '',
                ], array_slice($data['items'] ?? [], 0, 4)),
            ],
            'text' => [
                'title' => $data['title'] ?? null,
                'body' => $data['body'] ?? '',
            ],
            'store_info' => [
                'show_map' => false,
                'note' => $data['note'] ?? null,
            ],
            default => [],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function publishFirstVersion(int $storefrontId, array $blocks): void
    {
        $theme = DB::table('storefront_themes')->where('storefront_id', $storefrontId)->first();
        $settings = DB::table('storefront_settings')->where('storefront_id', $storefrontId)->get();

        $snapshot = [
            'theme' => collect((array) $theme)->except(['id', 'storefront_id', 'created_at', 'updated_at'])->all(),
            'blocks' => $blocks,
            'settings' => $settings->mapWithKeys(fn ($row) => [$row->key => json_decode($row->value, true)])->all(),
            'zones' => [],
            'slots' => [],
        ];

        $versionId = DB::table('storefront_versions')->insertGetId([
            'storefront_id' => $storefrontId,
            'number' => 1,
            'label' => 'Portada anterior',
            'snapshot' => json_encode($snapshot),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('storefronts')->where('id', $storefrontId)->update([
            'published_version_id' => $versionId,
            'published_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
