<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Services\ImageService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Biblioteca de imagenes del editor.
 *
 * Al subir se guardan las variantes que necesita la tienda y se exige el texto alternativo:
 * una imagen sin descripcion no existe para quien navega con lector de pantalla, ni para el
 * buscador.
 */
class MediaController extends Controller
{
    public function __construct(protected ImageService $images) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();

        return response()->json([
            'media' => MediaAsset::query()
                ->where('company_id', $companyId)
                ->latest('id')
                ->limit(40)
                ->get()
                ->map(fn (MediaAsset $asset) => $asset->toPayload()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $validated = $request->validate([
            'image' => ['required', 'image', 'max:4096'],
            'alt' => ['required', 'string', 'min:3', 'max:160'],
        ], [
            'image.max' => 'La imagen no puede pesar más de 4 MB.',
            'alt.required' => 'Describe la imagen: es lo que lee quien no la puede ver.',
            'alt.min' => 'La descripción es muy corta.',
        ]);

        $stored = $this->images->storeWithVariants($validated['image'], 'storefront', MediaAsset::VARIANTS);

        $asset = MediaAsset::create([
            'company_id' => $companyId,
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

    /** El texto alternativo y el punto focal se pueden corregir sin volver a subir. */
    public function update(Request $request, MediaAsset $media): JsonResponse
    {
        abort_if(
            ! $request->user()->isSuperAdmin() && $media->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa imagen no es de tu empresa.'
        );

        $validated = $request->validate([
            'alt' => ['sometimes', 'string', 'min:3', 'max:160'],
            'focal_x' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'focal_y' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $media->update($validated);

        return response()->json(['media' => $media->fresh()->toPayload()]);
    }
}
