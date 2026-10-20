<?php

namespace Tests\Feature;

use App\Landing\Blocks\VideoShowcaseBlock;
use App\Models\LandingBlock;
use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El bloque «Video con pasos» de la landing: subir el video, guardarlo en el bloque y pintarlo.
 */
class LandingVideoShowcaseTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
        app(LandingManager::class)->ensureSeeded($this->super);
    }

    public function test_el_video_se_sube_se_guarda_en_el_bloque_y_se_pinta(): void
    {
        $this->actingAs($this->super)->post(route('platform.landing.blocks.store'), ['type' => 'video_showcase'])->assertSessionHasNoErrors();
        $block = LandingBlock::query()->where('type', 'video_showcase')->firstOrFail();

        $path = $this->actingAs($this->super)
            ->postJson(route('platform.landing.upload-video'), ['video' => UploadedFile::fake()->create('demo.mp4', 2048, 'video/mp4')])
            ->assertOk()
            ->json('path');

        Storage::disk('public')->assertExists($path);
        $this->assertMatchesRegularExpression('#^landing/videos/\d{4}/\d{2}/[0-9a-f-]+\.mp4$#', $path);

        $this->actingAs($this->super)
            ->patchJson(route('platform.landing.blocks.update', $block->uuid), ['data' => array_merge($block->data, ['video' => $path])])
            ->assertOk()
            ->assertJsonPath('block.image_urls.video', Uploads::url($path));

        $this->actingAs($this->super)
            ->get(route('platform.landing.preview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('blocks', fn ($blocks) => collect($blocks)->contains(
                fn ($item) => $item['type'] === 'video_showcase' && $item['data']['video_url'] === Uploads::url($path) && count($item['data']['steps']) === 3,
            )));
    }

    public function test_solo_acepta_mp4_o_webm_hasta_su_tope(): void
    {
        $upload = fn (UploadedFile $file) => $this->actingAs($this->super)->postJson(route('platform.landing.upload-video'), ['video' => $file]);

        $upload(UploadedFile::fake()->create('clip.webm', 512, 'video/webm'))->assertOk();
        $upload(UploadedFile::fake()->create('animacion.gif', 512, 'image/gif'))->assertStatus(422)->assertJsonPath('errors.video.0', 'El video debe ser MP4 o WebM.');
        $upload(UploadedFile::fake()->create('pesado.mp4', VideoShowcaseBlock::MAX_VIDEO_KB + 1, 'video/mp4'))->assertStatus(422);

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->postJson(route('platform.landing.upload-video'), ['video' => UploadedFile::fake()->create('demo.mp4', 10, 'video/mp4')])->assertForbidden();
    }

    public function test_limpia_los_segundos_de_cada_paso_y_la_ruta_del_video(): void
    {
        $block = new VideoShowcaseBlock();
        $data = $block->normalize([
            'video' => 'https://otro-sitio.com/video.mp4',
            'steps' => [
                ['title' => 'Uno', 'starts_at' => '3.26'],
                ['title' => 'Dos', 'starts_at' => '-2'],
                ['title' => 'Tres', 'starts_at' => 'pronto'],
                ['title' => 'Cuatro', 'starts_at' => 9000],
            ],
        ]);

        $this->assertNull($data['video']);
        $this->assertSame([3.5, 0, null, 600], array_column($data['steps'], 'starts_at'));
        $this->assertSame('landing/videos/2026/10/demo.mp4', $block->normalize(['video' => 'landing/videos/2026/10/demo.mp4'])['video']);
    }
}
