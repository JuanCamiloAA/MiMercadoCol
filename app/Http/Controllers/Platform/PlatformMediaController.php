<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las imagenes de la plataforma, para las plantillas de tienda.
 *
 * Son filas de `media_assets` sin empresa: ninguna biblioteca de empresa las ve. Cuando una
 * tienda deja la plantilla, las que usa se copian a su propia biblioteca.
 */
class PlatformMediaController extends Controller
{
    public function __construct(protected ImageService $images) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'media' => MediaAsset::platform()->latest('id')->limit(60)->get()->map(fn (MediaAsset $asset) => $asset->toPayload()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'max:4096'],
            'alt' => ['required', 'string', 'min:3', 'max:160'],
        ], [
            'image.max' => 'La imagen no puede pesar más de 4 MB.',
            'alt.required' => 'Describe la imagen: es lo que lee quien no la puede ver.',
        ]);

        $stored = $this->images->storeWithVariants($validated['image'], 'storefront-platform', MediaAsset::VARIANTS);

        $asset = MediaAsset::createForPlatform([
            'path' => $stored['path'],
            'disk' => 'public',
            'mime' => $validated['image']->getMimeType(),
            'width' => $stored['width'],
            'height' => $stored['height'],
            'bytes' => $validated['image']->getSize(),
            'alt' => $validated['alt'],
            'variants' => $stored['variants'],
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['media' => $asset->toPayload()], 201);
    }
}
