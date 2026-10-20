<?php

namespace Tests\Feature;

use App\Models\Storefront;
use App\Services\Storefront\StorefrontManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * La migracion que lleva el fondo de los bloques (`background` en la tienda, `tone` en la landing)
 * a la pestaña «Diseño» (`appearance`), en el borrador, lo publicado, las plantillas y la landing.
 */
class MoveSectionBackgroundsMigrationTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->storefront = app(StorefrontManager::class)->for($this->store);
    }

    /** @return Migration&object{up: callable, down: callable} */
    protected function migration(): object
    {
        return require database_path('migrations/2026_10_15_000100_move_section_backgrounds_to_appearance.php');
    }

    /** @param  array<string, mixed>  $payload */
    protected function storeBlock(string $type, array $payload): int
    {
        return DB::table('storefront_blocks')->insertGetId([
            'storefront_id' => $this->storefront->id,
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'page' => 'home',
            'sort_order' => 90,
            'is_visible' => true,
            'devices' => 'all',
            'payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function payload(int $id): array
    {
        return json_decode((string) DB::table('storefront_blocks')->where('id', $id)->value('payload'), true);
    }

    public function test_convierte_los_fondos_de_la_tienda_y_deja_lo_demas_igual(): void
    {
        $custom = $this->storeBlock('video', ['title' => 'Así llega', 'background' => 'custom', 'background_color' => '#1e3a8a']);
        $soft = $this->storeBlock('product_grid', ['title' => 'Ofertas', 'background' => 'soft']);
        $plain = $this->storeBlock('media_carousel', ['title' => 'Fotos', 'background' => 'none', 'background_color' => '#0B1220']);
        $showcase = $this->storeBlock('video_showcase', ['title' => 'Pasos', 'background' => 'dark', 'background_color' => '#0B1220']);
        $other = $this->storeBlock('rich_text', ['title' => 'Texto', 'background' => 'soft']);

        DB::table('storefront_versions')->insert([
            'storefront_id' => $this->storefront->id,
            'number' => 7,
            'snapshot' => json_encode(['blocks' => [['type' => 'product_grid', 'payload' => ['title' => 'Ofertas', 'background' => 'brand']]]]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertEquals(['bg_type' => 'color', 'bg_color' => '#1E3A8A'], $this->payload($custom)['appearance']);
        $this->assertArrayNotHasKey('background_color', $this->payload($custom));
        // «Suave» es el color neutro del catálogo: basta con decir que lleva color.
        $this->assertEquals(['bg_type' => 'color'], $this->payload($soft)['appearance']);
        $this->assertArrayNotHasKey('background', $this->payload($soft));

        // Sin fondo, o con el de fábrica, no queda diseño propio.
        $this->assertArrayNotHasKey('appearance', $this->payload($plain));
        $this->assertArrayNotHasKey('appearance', $this->payload($showcase));
        $this->assertSame('Pasos', $this->payload($showcase)['title']);

        // Un tipo que nunca tuvo fondo no se toca.
        $this->assertSame('soft', $this->payload($other)['background']);

        $snapshot = json_decode((string) DB::table('storefront_versions')->where('number', 7)->value('snapshot'), true);
        $this->assertEquals(['bg_type' => 'color', 'bg_color' => 'brand'], $snapshot['blocks'][0]['payload']['appearance']);

        // Correrla otra vez no cambia nada.
        $before = $this->payload($custom);
        $this->migration()->up();
        $this->assertEquals($before, $this->payload($custom));

        // Y se puede deshacer.
        $this->migration()->down();
        $this->assertSame('custom', $this->payload($custom)['background']);
        $this->assertSame('#1E3A8A', $this->payload($custom)['background_color']);
        $this->assertArrayNotHasKey('appearance', $this->payload($custom));
        $this->assertSame('soft', $this->payload($soft)['background']);
        $this->assertSame('dark', $this->payload($showcase)['background']);
        $this->assertSame('none', $this->payload($plain)['background']);
    }

    public function test_convierte_el_tono_de_la_landing_en_el_borrador_lo_publicado_y_el_historial(): void
    {
        $data = ['title' => 'Video', 'tone' => 'custom', 'background_color' => '#ff8800'];

        $id = DB::table('landing_blocks')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'type' => 'video',
            'position' => 50,
            'is_visible' => true,
            'data' => json_encode($data),
            'published_data' => json_encode(['title' => 'Video', 'tone' => 'dark']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('landing_versions')->insert([
            'number' => 3,
            'snapshot' => json_encode([['uuid' => 'x', 'type' => 'video_showcase', 'data' => ['title' => 'Pasos', 'tone' => 'light']]]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $row = DB::table('landing_blocks')->where('id', $id)->first();
        $draft = json_decode((string) $row->data, true);
        $published = json_decode((string) $row->published_data, true);

        $this->assertArrayNotHasKey('tone', $draft);
        $this->assertEquals(['bg_type' => 'color', 'bg_color' => '#FF8800'], $draft['appearance']);
        $this->assertEquals(['bg_type' => 'color', 'bg_color' => 'dark'], $published['appearance']);

        // «Video con pasos» era oscuro de fábrica; claro ahora es «sin fondo propio».
        $snapshot = json_decode((string) DB::table('landing_versions')->where('number', 3)->value('snapshot'), true);
        $this->assertEquals(['bg_type' => 'none'], $snapshot[0]['data']['appearance']);

        $this->migration()->down();

        $draft = json_decode((string) DB::table('landing_blocks')->where('id', $id)->value('data'), true);
        $this->assertSame('custom', $draft['tone']);
        $this->assertSame('#FF8800', $draft['background_color']);
        $snapshot = json_decode((string) DB::table('landing_versions')->where('number', 3)->value('snapshot'), true);
        $this->assertSame('light', $snapshot[0]['data']['tone']);
    }
}
