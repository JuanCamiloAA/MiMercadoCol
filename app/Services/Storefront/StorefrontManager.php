<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Models\StorefrontSetting;
use App\Models\StorefrontTemplate;
use App\Models\StorefrontTheme;
use App\Storefront\BlockCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El borrador de una sede.
 *
 * Todo lo que el editor toca pasa por aqui: encontrar el sitio de la sede, armar su retrato y
 * volver a poner un retrato como borrador. La tienda publica no usa este servicio.
 */
class StorefrontManager
{
    /**
     * El sitio de la sede, creado con sus valores por defecto si todavia no existia.
     */
    public function for(Store $store): Storefront
    {
        $storefront = Storefront::query()->withoutGlobalScopes()->where('store_id', $store->id)->first();

        if (! $storefront) {
            $storefront = DB::transaction(function () use ($store) {
                // Una tienda nueva nace con la plantilla por defecto y la sigue: recibe sus
                // cambios hasta que alguien la personalice.
                $template = self::defaultTemplate();

                $created = Storefront::query()->withoutGlobalScopes()->create([
                    'company_id' => $store->company_id,
                    'store_id' => $store->id,
                    'slug' => $this->uniqueSlug($store),
                    'is_online' => true,
                    'template_id' => $template?->id,
                    'follows_template' => $template !== null,
                ]);

                // La tienda se llama como la empresa; la sede se dice aparte. Con varias empresas en
                // la landing, «Centro» a secas no dice de quien es.
                StorefrontTheme::create(['storefront_id' => $created->id, 'display_name' => $store->company?->name ?? $store->name]);

                foreach ($this->defaultSettings() as $key => $value) {
                    StorefrontSetting::create(['storefront_id' => $created->id, 'key' => $key, 'value' => $value]);
                }

                return $created;
            });
        }

        return $storefront->load(['theme', 'blocks', 'settings', 'zones', 'slots', 'template']);
    }

    /** La plantilla con que nacen las tiendas nuevas: activa, publicada y marcada por defecto. */
    public static function defaultTemplate(): ?StorefrontTemplate
    {
        return StorefrontTemplate::query()->where('is_default', true)->where('is_active', true)->whereNotNull('published')->first();
    }

    /**
     * Retrato completo del borrador: lo que se guarda al publicar.
     *
     * Es autosuficiente a proposito. Si manana se agrega un campo al tema, los retratos viejos
     * siguen sirviendo porque traen lo que habia el dia que se publicaron.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Storefront $storefront): array
    {
        $storefront->loadMissing(['theme', 'blocks', 'settings', 'zones', 'slots']);

        return [
            'theme' => $this->themePayload($storefront),
            'blocks' => $storefront->blocks->map(fn (StorefrontBlock $block) => [
                'uuid' => $block->uuid,
                'type' => $block->type,
                'page' => $block->page ?? StorefrontBlock::PAGE_HOME,
                'sort_order' => $block->sort_order,
                'is_visible' => $block->is_visible,
                'visible_from' => $block->visible_from?->toIso8601String(),
                'visible_to' => $block->visible_to?->toIso8601String(),
                'devices' => $block->devices,
                'payload' => $block->payload ?? [],
            ])->values()->all(),
            'settings' => $storefront->settings->mapWithKeys(fn (StorefrontSetting $s) => [$s->key => $s->value])->all(),
            // Si el diseño lo pone la plantilla. Viaja en el retrato para que volver a una version
            // (o copiarla a otra sede) deje la tienda siguiendo o no la plantilla, como estaba.
            'template' => ['id' => $storefront->template_id, 'follows' => (bool) $storefront->follows_template],
            'zones' => $storefront->zones->map(fn ($zone) => [
                'name' => $zone->name,
                'fee' => $zone->fee,
                'eta_minutes' => $zone->eta_minutes,
                'is_active' => $zone->is_active,
                'geometry' => $zone->geometry,
                'sort_order' => $zone->sort_order,
            ])->values()->all(),
            'slots' => $storefront->slots->map(fn ($slot) => [
                'weekday' => $slot->weekday,
                'starts_at' => (string) $slot->starts_at,
                'ends_at' => (string) $slot->ends_at,
                'capacity' => $slot->capacity,
                'is_active' => $slot->is_active,
            ])->values()->all(),
        ];
    }

    /**
     * Repone el borrador con un retrato. Se usa al revertir y al copiar de otra sede.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restore(Storefront $storefront, array $snapshot): void
    {
        DB::transaction(function () use ($storefront, $snapshot) {
            $storefront->blocks()->delete();
            $storefront->zones()->delete();
            $storefront->slots()->delete();

            foreach ($snapshot['blocks'] ?? [] as $order => $block) {
                if (! BlockCatalogue::has($block['type'] ?? '')) {
                    continue;
                }

                // Un retrato de antes de las paginas es todo portada.
                $page = in_array($block['page'] ?? null, StorefrontBlock::PAGES, true) ? $block['page'] : StorefrontBlock::PAGE_HOME;

                StorefrontBlock::create([
                    'storefront_id' => $storefront->id,
                    // Un uuid nuevo: dos sedes con el mismo retrato no comparten identidad.
                    'uuid' => (string) Str::uuid(),
                    'type' => $block['type'],
                    'page' => BlockCatalogue::allowedOn($block['type'], $page) ? $page : StorefrontBlock::PAGE_HOME,
                    'sort_order' => $block['sort_order'] ?? $order,
                    'is_visible' => $block['is_visible'] ?? true,
                    'visible_from' => $block['visible_from'] ?? null,
                    'visible_to' => $block['visible_to'] ?? null,
                    'devices' => in_array($block['devices'] ?? 'all', StorefrontBlock::DEVICES, true) ? $block['devices'] : 'all',
                    'payload' => BlockCatalogue::for($block['type'])->normalize($block['payload'] ?? []),
                ]);
            }

            if (isset($snapshot['theme'])) {
                $storefront->theme()->updateOrCreate(
                    ['storefront_id' => $storefront->id],
                    collect($snapshot['theme'])->only([
                        'display_name', 'tagline', 'accent', 'deal_color', 'scheme', 'allow_scheme_toggle',
                        'font_pair', 'radius', 'density', 'button_style',
                    ])->all()
                );
            }

            foreach ($snapshot['settings'] ?? [] as $key => $value) {
                if (in_array($key, StorefrontSetting::KEYS, true)) {
                    StorefrontSetting::updateOrCreate(
                        ['storefront_id' => $storefront->id, 'key' => $key],
                        ['value' => $value]
                    );
                }
            }

            // Un retrato sin la marca de plantilla es de antes de las plantillas: diseño propio.
            $template = $snapshot['template'] ?? null;
            $templateId = is_array($template) && ! empty($template['id']) && StorefrontTemplate::query()->whereKey($template['id'])->exists()
                ? (int) $template['id']
                : $storefront->template_id;

            $storefront->forceFill([
                'template_id' => $templateId,
                'follows_template' => $templateId !== null && is_array($template) && (bool) ($template['follows'] ?? false),
            ])->save();

            foreach ($snapshot['zones'] ?? [] as $zone) {
                $storefront->zones()->create($zone);
            }

            foreach ($snapshot['slots'] ?? [] as $slot) {
                $storefront->slots()->create($slot);
            }
        });

        $storefront->refresh()->load(['theme', 'blocks', 'settings', 'zones', 'slots', 'template']);
    }

    /**
     * @return array<string, mixed>
     */
    public function themePayload(Storefront $storefront): array
    {
        $theme = $storefront->theme;

        $accent = $theme?->accent ?? '#0E6E4E';
        $deal = $theme?->deal_color ?? '#D92D20';

        return [
            'display_name' => $theme?->display_name,
            'tagline' => $theme?->tagline,
            'accent' => $accent,
            // Va en el retrato para que la tienda no tenga que recalcularlo, y para que una
            // version vieja conserve el par exacto que se publico.
            'accent_ink' => app(StorefrontPublisher::class)->ink($accent),
            // El rojo de oferta, con su tinta calculada igual que la del acento.
            'deal_color' => $deal,
            'deal_ink' => app(StorefrontPublisher::class)->ink($deal),
            'scheme' => $theme?->scheme ?? 'light',
            'allow_scheme_toggle' => (bool) ($theme?->allow_scheme_toggle ?? false),
            'font_pair' => $theme?->font_pair ?? 'moderna',
            'radius' => $theme?->radius ?? 'soft',
            'density' => $theme?->density ?? 'cozy',
            'button_style' => $theme?->button_style ?? 'solid',
            'logo' => $theme?->logo?->toPayload(),
            'favicon' => $theme?->favicon?->toPayload(),
            'mark' => $theme?->mark?->toPayload(),
        ];
    }

    /**
     * Cuando se guardo por ultima vez algo del borrador.
     */
    public function lastSavedAt(Storefront $storefront): ?string
    {
        $times = collect([
            $storefront->blocks->max('updated_at'),
            $storefront->theme?->updated_at,
            $storefront->settings->max('updated_at'),
        ])->filter();

        return $times->isEmpty() ? null : $times->max()->toIso8601String();
    }

    /** Si el borrador tiene algo distinto de lo publicado. */
    public function hasUnpublishedChanges(Storefront $storefront): bool
    {
        $published = $storefront->publishedVersion?->snapshot;

        if ($published === null) {
            return true;
        }

        return $this->comparable($this->snapshot($storefront)) !== $this->comparable($published);
    }

    /**
     * El retrato sin lo que cambia solo: los uuid de los bloques.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    protected function comparable(array $snapshot): array
    {
        // Un retrato de antes de las paginas, del rojo de oferta o de las plantillas trae menos
        // campos; lo que falta vale lo mismo que su valor por defecto, y no es un cambio.
        $snapshot['blocks'] = array_map(
            fn (array $block) => array_merge(['page' => StorefrontBlock::PAGE_HOME], collect($block)->except('uuid')->all()),
            $snapshot['blocks'] ?? []
        );
        $snapshot['theme'] = array_merge(
            ['deal_color' => '#D92D20', 'deal_ink' => '#FFFFFF', 'allow_scheme_toggle' => false, 'mark' => null],
            $snapshot['theme'] ?? []
        );
        $snapshot['template'] = array_merge(['id' => null, 'follows' => false], $snapshot['template'] ?? []);
        ksort($snapshot['theme']);
        ksort($snapshot);

        return $snapshot;
    }

    protected function uniqueSlug(Store $store): string
    {
        $base = Str::slug($store->name) ?: 'tienda';
        $slug = $base;
        $suffix = 2;

        while (Storefront::query()->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function defaultSettings(): array
    {
        return [
            'delivery' => [
                'enabled' => true,
                'fee' => 5000,
                'min_order_total' => 0,
                'free_from' => 0,
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
            // `hide_zero_price` siempre es verdadero: un articulo en cero es un articulo que se regala.
            'catalog' => ['show_out_of_stock' => true, 'price_per_unit' => true, 'hide_zero_price' => true],
            'texts' => ['delivery_note' => null],
            'seo' => ['title' => null, 'description' => null],
            ...StorefrontStructure::DEFAULTS,
        ];
    }
}
