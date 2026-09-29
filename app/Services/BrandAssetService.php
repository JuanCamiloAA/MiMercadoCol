<?php

namespace App\Services;

use App\Support\Color;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Las imagenes de marca de una empresa: subir, reemplazar y quitar, y mantener el logo del
 * tiquete al dia con ellas.
 *
 * Se guardan en ajustes (`brand.mark`, `brand.logo_light`, `brand.logo_dark`) y no en
 * `companies.logo`: la marca vive en un solo lugar.
 */
class BrandAssetService
{
    public function __construct(
        protected SettingsService $settings,
        protected ImageService $images,
    ) {}

    /**
     * Sube (o reemplaza) una imagen. Lo anterior del mismo lugar se borra del disco.
     *
     * @return array<string, string>
     */
    public function store(int $companyId, string $slot, UploadedFile $file): array
    {
        $key = "brand.{$slot}";
        $previous = $this->settings->get($companyId, $key);
        $accent = $this->settings->get($companyId, 'brand.accent');

        $paths = $this->images->storeBrandAsset($file, $companyId, $slot, Color::isHex($accent) ? $accent : '#0E6E4E');

        DB::transaction(fn () => $this->settings->set($companyId, $key, $paths));

        $this->images->deleteBrandAsset($previous);
        $this->refreshReceiptLogo($companyId);

        return $paths;
    }

    public function destroy(int $companyId, string $slot): void
    {
        $key = "brand.{$slot}";
        $previous = $this->settings->get($companyId, $key);

        $this->settings->set($companyId, $key, null);
        $this->images->deleteBrandAsset($previous);
        $this->refreshReceiptLogo($companyId);
    }

    /**
     * El icono de iOS lleva de fondo el color de la marca: si el color cambia, se rehace desde
     * el isotipo guardado y se borra el anterior.
     */
    public function refreshTouchIcon(int $companyId): void
    {
        $mark = $this->settings->get($companyId, 'brand.mark');

        if (! is_array($mark) || ! is_string($mark['path'] ?? null)) {
            return;
        }

        $accent = $this->settings->get($companyId, 'brand.accent');
        $touch = $this->images->storeTouchIcon($mark['path'], $companyId, Color::isHex($accent) ? $accent : '#0E6E4E');

        if ($touch === null) {
            return;
        }

        $previous = $mark['touch'] ?? null;
        $this->settings->set($companyId, 'brand.mark', [...$mark, 'touch' => $touch]);

        if (is_string($previous) && $previous !== $mark['path'] && $previous !== ($mark['favicon'] ?? null)) {
            $this->images->deleteBrandAsset(['touch' => $previous]);
        }
    }

    /**
     * El logo del tiquete sale del logo para fondo claro o, si no hay, del isotipo: los dos
     * se leen sobre papel blanco. Se regenera cada vez que cambia alguno de ellos.
     */
    public function refreshReceiptLogo(int $companyId): void
    {
        $previous = $this->settings->get($companyId, 'brand.logo_receipt');
        $source = $this->settings->get($companyId, 'brand.logo_light')['path']
            ?? $this->settings->get($companyId, 'brand.mark')['path']
            ?? null;

        $receipt = is_string($source) ? $this->images->storeReceiptLogo($source, $companyId) : null;

        $this->settings->set($companyId, 'brand.logo_receipt', $receipt);
        $this->images->deleteBrandAsset($previous);
    }
}
