<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Services\ImageService;
use App\Support\TenantContext;
use App\Support\Uploads;
use App\Support\VideoOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Biblioteca de imagenes y videos del editor.
 *
 * Al subir se guardan las variantes que necesita la tienda y se exige el texto alternativo:
 * una imagen sin descripcion no existe para quien navega con lector de pantalla, ni para el
 * buscador. Los videos (`storeVideo`) van sin variantes y tambien con su descripcion.
 */
class MediaController extends Controller
{
    public function __construct(protected ImageService $images) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();

        // Sin `kind`, imagenes: los campos de imagen que ya existian no deben ofrecer un video.
        $kind = $request->query('kind') === 'video' ? 'video' : 'image';

        return response()->json([
            'media' => MediaAsset::query()
                ->where('company_id', $companyId)
                ->ofKind($kind)
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
            'disk' => Uploads::diskName(),
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

    /**
     * Un video para los bloques de video de la tienda: MP4 o WebM, hasta `VideoOptions::MAX_VIDEO_KB`,
     * con su descripcion. Se guarda tal cual: no hay recortes que sacarle.
     */
    public function storeVideo(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $asset = MediaAsset::create(['company_id' => $companyId, ...self::videoAttributes($request, 'storefront/'.$companyId.'/videos')]);

        return response()->json(['media' => $asset->toPayload()], 201);
    }

    /**
     * Valida y guarda el video; devuelve lo que va en la fila. Lo usa tambien la biblioteca de
     * la plataforma (`PlatformMediaController`).
     *
     * @return array<string, mixed>
     */
    public static function videoAttributes(Request $request, string $directory): array
    {
        [$rules, $messages] = VideoOptions::uploadRules();

        $validated = $request->validate([
            'video' => $rules,
            'alt' => ['required', 'string', 'min:3', 'max:160'],
        ], [
            ...$messages,
            'alt.required' => 'Describe el video: es lo que lee quien no lo puede ver.',
            'alt.min' => 'La descripción es muy corta.',
        ]);

        $file = $validated['video'];

        return [
            'path' => $file->storeAs($directory, Str::random(32).'.'.$file->extension(), Uploads::diskName()),
            'disk' => Uploads::diskName(),
            'mime' => $file->getMimeType(),
            'bytes' => $file->getSize(),
            'alt' => $validated['alt'],
            'created_by_user_id' => $request->user()->id,
        ];
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
