<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SaveMapSettingsRequest;
use App\Services\Landing\LandingRenderer;
use App\Services\PlatformSettingsService;
use App\Support\Maps\MapConfig;
use App\Support\Maps\MapProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Mapas: con que proveedor se pintan el buscador de la landing y el mapa del
 * formulario de sedes. Se prueba antes de guardar: un proveedor mal configurado deja a la
 * landing sin mapa para todos.
 */
class MapSettingsController extends Controller
{
    public function __construct(protected PlatformSettingsService $settings) {}

    public function index(LandingRenderer $landing): Response
    {
        $values = $this->settings->all();

        return Inertia::render('Platform/Maps/Index', [
            'settings' => collect(SaveMapSettingsRequest::FIELDS)->map(fn (string $key) => $values[$key] ?? null)->all(),
            'providers' => MapProviders::catalogue(),
            'map' => MapConfig::current(),
            // Las mismas sedes que ve la landing: la vista previa muestra lo que vera el publico.
            'points' => $landing->stores()
                ->filter(fn (array $store) => $store['latitude'] !== null && $store['longitude'] !== null)
                ->map(fn (array $store) => [
                    'id' => $store['id'],
                    'name' => $store['name'],
                    'company' => $store['company'],
                    'accent' => $store['accent'],
                    'accent_ink' => $store['accent_ink'],
                    'latitude' => $store['latitude'],
                    'longitude' => $store['longitude'],
                ])
                ->values(),
        ]);
    }

    public function update(SaveMapSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany($request->settings());

        $label = collect(MapProviders::catalogue())->firstWhere('value', $request->validated('provider'))['label'];

        return back()->with('success', "Los mapas quedaron con {$label}.");
    }

    /** Lo que se veria con estos datos, sin guardar nada. */
    public function preview(SaveMapSettingsRequest $request): JsonResponse
    {
        return response()->json(['map' => MapConfig::build($request->settings())]);
    }
}
