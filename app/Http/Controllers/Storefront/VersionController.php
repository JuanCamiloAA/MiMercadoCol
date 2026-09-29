<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\StorefrontVersion;
use App\Services\Storefront\StorefrontPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El historial de publicaciones.
 *
 * Cada fila dice que cambio respecto de la anterior, en lenguaje de tienda: «3 bloques
 * cambiados · tarifa de domicilio $ 4.000 → $ 4.500».
 */
class VersionController extends Controller
{
    use ResolvesStorefront;

    public function __construct(protected StorefrontPublisher $publisher) {}

    public function index(): JsonResponse
    {
        $storefront = $this->storefront();

        $versions = StorefrontVersion::query()
            ->where('storefront_id', $storefront->id)
            ->with('author:id,name,last_name')
            ->orderByDesc('number')
            ->limit(30)
            ->get();

        $rows = $versions->values()->map(function (StorefrontVersion $version, int $index) use ($versions, $storefront) {
            $previous = $versions[$index + 1] ?? null;

            return [
                'id' => $version->id,
                'number' => $version->number,
                'label' => $version->label,
                'author' => $version->author?->full_name,
                'published_at' => $version->published_at?->toIso8601String(),
                'is_current' => $version->id === $storefront->published_version_id,
                'changes' => $this->publisher->diff($previous?->snapshot, $version->snapshot),
            ];
        });

        return response()->json(['versions' => $rows]);
    }

    public function restore(Request $request, StorefrontVersion $version): RedirectResponse
    {
        $storefront = $this->storefront();

        $copy = $this->publisher->restore($storefront, $version, $request->user());

        AccessLog::log('storefront_restored', $request->user()->id, [
            'storefront_id' => $storefront->id,
            'from_version' => $version->number,
            'new_version' => $copy->number,
        ]);

        return back()->with('success', 'Se volvió a la versión '.$version->number.'.');
    }
}
