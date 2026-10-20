<?php

use App\Landing\Blocks\LandingBlock;
use App\Landing\LandingBlockCatalogue;
use App\Models\StorefrontBlock;
use App\Services\Storefront\StorefrontPublisher;
use App\Storefront\BlockCatalogue;
use App\Storefront\Blocks\BlockData;
use App\Support\SectionAppearance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El fondo de los bloques pasa a la pestaña «Diseño» (`appearance`).
 *
 * En la tienda los bloques de video y el estante tenian `background` (none/soft/dark/brand/custom +
 * `background_color`); en la landing los de video, `tone` (light/dark/custom). Aqui se convierten en
 * todos los lugares donde hay un bloque guardado: borrador, versiones publicadas, plantillas y sus
 * versiones, y la landing con su historial.
 *
 * Se usa la misma conversion de `upgrade()` y se normaliza igual que al guardar, asi el borrador y
 * lo publicado quedan iguales entre si y el editor no avisa cambios sin publicar que no existen.
 * Los demas tipos no se tocan.
 */
return new class extends Migration
{
    /** Los tipos de la tienda que tenian `background`. */
    protected const STORE_TYPES = ['video', 'video_showcase', 'media_carousel', 'product_grid'];

    /** Los tipos de la landing que tenian `tone`. */
    protected const LANDING_TYPES = ['video', 'video_showcase', 'media_carousel'];

    public function up(): void
    {
        $this->walk(fn (array $payload, string $type) => $this->storeUp($payload, $type), fn (array $data, string $type) => $this->landingUp($data, $type));
    }

    public function down(): void
    {
        $this->walk(fn (array $payload, string $type) => $this->storeDown($payload, $type), fn (array $data, string $type) => $this->landingDown($data, $type));
    }

    /**
     * Recorre cada bloque guardado y le aplica la conversion. Solo escribe lo que cambio.
     *
     * @param  callable(array<string, mixed>, string): ?array<string, mixed>  $store
     * @param  callable(array<string, mixed>, string): ?array<string, mixed>  $landing
     */
    protected function walk(callable $store, callable $landing): void
    {
        DB::table('storefront_blocks')->whereIn('type', self::STORE_TYPES)->orderBy('id')->each(function (object $row) use ($store) {
            $payload = json_decode((string) $row->payload, true);
            $next = is_array($payload) ? $store($payload, $row->type) : null;

            if ($next !== null) {
                DB::table('storefront_blocks')->where('id', $row->id)->update(['payload' => json_encode($next)]);
            }
        });

        $snapshots = [
            ['storefront_versions', 'snapshot'],
            ['storefront_templates', 'draft'],
            ['storefront_templates', 'published'],
            ['storefront_template_versions', 'snapshot'],
        ];

        foreach ($snapshots as [$table, $column]) {
            DB::table($table)->whereNotNull($column)->orderBy('id')->each(function (object $row) use ($table, $column, $store) {
                $snapshot = json_decode((string) $row->{$column}, true);

                if (! is_array($snapshot) || ! is_array($snapshot['blocks'] ?? null)) {
                    return;
                }

                $changed = false;

                foreach ($snapshot['blocks'] as $index => $block) {
                    $type = (string) ($block['type'] ?? '');
                    $next = in_array($type, self::STORE_TYPES, true) && is_array($block['payload'] ?? null) ? $store($block['payload'], $type) : null;

                    if ($next !== null) {
                        $snapshot['blocks'][$index]['payload'] = $next;
                        $changed = true;
                    }
                }

                if ($changed) {
                    DB::table($table)->where('id', $row->id)->update([$column => json_encode($snapshot)]);
                }
            });
        }

        DB::table('landing_blocks')->whereIn('type', self::LANDING_TYPES)->orderBy('id')->each(function (object $row) use ($landing) {
            $changes = [];

            foreach (['data', 'published_data'] as $column) {
                $data = json_decode((string) $row->{$column}, true);
                $next = is_array($data) ? $landing($data, $row->type) : null;

                if ($next !== null) {
                    $changes[$column] = json_encode($next);
                }
            }

            if ($changes !== []) {
                DB::table('landing_blocks')->where('id', $row->id)->update($changes);
            }
        });

        DB::table('landing_versions')->orderBy('id')->each(function (object $row) use ($landing) {
            $snapshot = json_decode((string) $row->snapshot, true);

            if (! is_array($snapshot)) {
                return;
            }

            $changed = false;

            foreach ($snapshot as $index => $block) {
                $type = (string) ($block['type'] ?? '');
                $next = in_array($type, self::LANDING_TYPES, true) && is_array($block['data'] ?? null) ? $landing($block['data'], $type) : null;

                if ($next !== null) {
                    $snapshot[$index]['data'] = $next;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('landing_versions')->where('id', $row->id)->update(['snapshot' => json_encode($snapshot)]);
            }
        });

        // Lo publicado se sirve pintado desde la cache: sin olvidarlo, la tienda seguiria un rato con
        // el fondo de antes.
        foreach (DB::table('storefronts')->pluck('store_id') as $storeId) {
            foreach ([...StorefrontBlock::PAGES, StorefrontPublisher::SHELL] as $page) {
                Cache::forget(StorefrontPublisher::cacheKey((int) $storeId, $page));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function storeUp(array $payload, string $type): ?array
    {
        if (! array_key_exists('background', $payload)) {
            return null;
        }

        $payload = BlockData::upgradeBackground($payload);
        $defaults = BlockCatalogue::has($type) ? BlockCatalogue::for($type)->appearanceDefaults() : [];
        $appearance = SectionAppearance::normalize($payload['appearance'] ?? null, $defaults, SectionAppearance::SURFACE_STORE);

        unset($payload['appearance']);

        if ($appearance !== []) {
            $payload['appearance'] = $appearance;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function landingUp(array $data, string $type): ?array
    {
        if (! array_key_exists('tone', $data) || ! LandingBlockCatalogue::has($type)) {
            return null;
        }

        // El mismo `normalize()` del guardado, que trae la conversion (`upgrade()`): asi lo
        // publicado y el borrador quedan con la misma forma.
        return LandingBlockCatalogue::for($type)->normalize(LandingBlock::upgradeTone($data));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function storeDown(array $payload, string $type): ?array
    {
        if (array_key_exists('background', $payload)) {
            return null;
        }

        $appearance = is_array($payload['appearance'] ?? null) ? $payload['appearance'] : [];
        $defaults = SectionAppearance::defaults(BlockCatalogue::has($type) ? BlockCatalogue::for($type)->appearanceDefaults() : [], SectionAppearance::SURFACE_STORE);
        [$background, $color] = $this->legacy(array_merge($defaults, $appearance), ['soft' => 'soft', 'brand_dark' => 'dark', 'brand' => 'brand']);

        $payload['background'] = $background;

        if ($type !== 'product_grid') {
            $payload['background_color'] = $color;
        }

        return $this->withoutBackground($payload, $appearance);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function landingDown(array $data, string $type): ?array
    {
        if (array_key_exists('tone', $data)) {
            return null;
        }

        $appearance = is_array($data['appearance'] ?? null) ? $data['appearance'] : [];
        $defaults = SectionAppearance::defaults(LandingBlockCatalogue::has($type) ? LandingBlockCatalogue::for($type)->appearanceDefaults() : [], SectionAppearance::SURFACE_LANDING);
        [$tone, $color] = $this->legacy(array_merge($defaults, $appearance), ['dark' => 'dark']);

        $data['tone'] = $tone === 'none' ? 'light' : $tone;
        $data['background_color'] = $color;

        return $this->withoutBackground($data, $appearance);
    }

    /**
     * El valor viejo que corresponde al fondo de `appearance` (ya con el defecto del tipo encima:
     * «Video con pasos» es oscuro sin que nadie lo diga). Lo que no era un color queda sin fondo.
     *
     * @param  array<string, mixed>  $appearance
     * @param  array<string, string>  $tokens
     * @return array{0: string, 1: string}
     */
    protected function legacy(array $appearance, array $tokens): array
    {
        $type = $appearance['bg_type'] ?? 'none';
        $color = (string) ($appearance['bg_color'] ?? '');

        if ($type !== 'color') {
            return ['none', '#0B1220'];
        }

        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1) {
            return ['custom', strtoupper($color)];
        }

        return [$tokens[$color] ?? 'none', '#0B1220'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $appearance
     * @return array<string, mixed>
     */
    protected function withoutBackground(array $payload, array $appearance): array
    {
        unset($appearance['bg_type'], $appearance['bg_color'], $payload['appearance']);

        if ($appearance !== []) {
            $payload['appearance'] = $appearance;
        }

        return $payload;
    }
};
