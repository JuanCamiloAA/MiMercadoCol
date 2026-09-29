<?php

namespace Tests\Concerns;

use App\Models\MediaAsset;
use App\Models\Storefront;
use App\Models\StorefrontTemplate;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\BlockCatalogue;
use App\Storefront\Blocks\BlockData;
use Illuminate\Support\Str;

/**
 * Ayudantes de las pruebas de la tienda renovada: ajustes publicados, medios y plantillas.
 */
trait BuildsStorefront
{
    /** El sitio de la sede de pruebas, listo para publicar (cobertura por radio). */
    protected function site(): Storefront
    {
        $storefront = app(StorefrontManager::class)->for($this->store);
        $storefront->settings()->updateOrCreate(['key' => 'coverage'], ['value' => ['mode' => 'radius', 'radius_km' => 3]]);

        return $storefront->refresh()->load('settings');
    }

    /**
     * Guarda un ajuste en el borrador y publica.
     *
     * @param  array<string, mixed>  $value
     */
    protected function publishSetting(string $key, array $value, ?User $author = null): Storefront
    {
        $storefront = $this->site();
        $default = app(StorefrontManager::class)->defaultSettings()[$key] ?? [];
        $storefront->settings()->updateOrCreate(['key' => $key], ['value' => array_merge($default, $value)]);

        return $this->publishSite($storefront, $author);
    }

    protected function publishSite(?Storefront $storefront = null, ?User $author = null): Storefront
    {
        $storefront ??= $this->site();
        $author ??= User::query()->where('company_id', $this->store->company_id)->first() ?? $this->makeUser('admin');

        app(StorefrontPublisher::class)->publish($storefront->refresh()->load('settings'), $author, 'Prueba');
        $storefront->forceFill(['is_online' => true])->save();
        app(StorefrontPublisher::class)->forget($storefront);

        return $storefront->refresh();
    }

    /** Una imagen de la biblioteca, sin archivo: el renderizador solo lee la fila. */
    protected function mediaFor(?int $companyId, string $alt = 'Canasta de frutas', int $bytes = 120000): MediaAsset
    {
        $attributes = [
            'path' => 'storefront/'.($companyId ?? 0).'/'.Str::random(8).'.jpg',
            'disk' => 'public',
            'mime' => 'image/jpeg',
            'width' => 1600,
            'height' => 600,
            'bytes' => $bytes,
            'alt' => $alt,
            'variants' => [],
        ];

        return $companyId === null
            ? MediaAsset::createForPlatform($attributes)
            : MediaAsset::query()->withoutGlobalScopes()->create(array_merge($attributes, ['company_id' => $companyId]));
    }

    /**
     * Una plantilla publicada con estos bloques (tipo, pagina, payload).
     *
     * @param  list<array{0: string, 1: string, 2: array<string, mixed>}>  $blocks
     */
    protected function makeTemplate(string $name = 'Supermercado', array $blocks = [], bool $default = true): StorefrontTemplate
    {
        $service = app(StorefrontTemplateService::class);
        $template = $service->create($name, null);
        $draft = $template->draft;

        foreach ($blocks as $order => [$type, $page, $payload]) {
            $definition = BlockCatalogue::for($type);
            $draft['blocks'][] = [
                'uuid' => (string) Str::uuid(),
                'type' => $type,
                'page' => $page,
                'sort_order' => $order,
                'is_visible' => true,
                'visible_from' => null,
                'visible_to' => null,
                'devices' => 'all',
                'payload' => $definition->normalize(array_merge($definition->defaults(), $payload), BlockData::CONTEXT_TEMPLATE),
            ];
        }

        $template->forceFill(['draft' => $draft])->save();
        $super = User::query()->whereNull('company_id')->first() ?? $this->makeSuperAdmin();
        $service->publish($template->refresh(), $super);

        if ($default) {
            $service->setDefault($template->refresh());
        }

        return $template->refresh();
    }
}
