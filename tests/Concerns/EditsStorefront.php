<?php

namespace Tests\Concerns;

use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Storefront\BlockCatalogue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Ayudantes para las pruebas del editor de la tienda.
 *
 * El editor siempre trabaja sobre la sede activa, que vive en sesion; por eso cada peticion
 * tiene que llevarla. Envolverlo aqui evita repetir el `withSession` en cada linea.
 */
trait EditsStorefront
{
    /** Peticion como administrador, con la sede activa puesta. */
    protected function editor(): static
    {
        return $this->actingAs($this->admin, 'web')->withSession($this->withActiveStore());
    }

    /**
     * Agrega un bloque al borrador sin pasar por la ruta, para preparar el escenario.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function addBlock(string $type, array $payload = [], ?Storefront $storefront = null): StorefrontBlock
    {
        $storefront ??= $this->storefront;
        $definition = BlockCatalogue::for($type);

        return StorefrontBlock::create([
            'storefront_id' => $storefront->id,
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'sort_order' => (int) StorefrontBlock::query()->where('storefront_id', $storefront->id)->max('sort_order') + 1,
            'is_visible' => true,
            'devices' => 'all',
            'payload' => $definition->normalize(array_merge($definition->defaults(), $payload)),
        ]);
    }

    /**
     * Deja el sitio en condiciones de publicar.
     *
     * Publicar exige un medio de pago y, si el domicilio esta encendido con cobertura por
     * zonas, al menos una zona. Las pruebas que no miden eso arrancan con cobertura por radio.
     */
    protected function makeStorefrontPublishable(Storefront $storefront): void
    {
        $storefront->settings()->updateOrCreate(
            ['key' => 'coverage'],
            ['value' => ['mode' => 'radius', 'radius_km' => 3]]
        );

        $storefront->refresh()->load('settings');
    }

    /** @return TestResponse<\Illuminate\Http\Response> */
    protected function openEditor(): TestResponse
    {
        return $this->editor()->get(route('storefront.editor.index'));
    }
}
