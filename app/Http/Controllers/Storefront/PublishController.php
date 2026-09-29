<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Services\Storefront\StorefrontPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Publicar el borrador.
 */
class PublishController extends Controller
{
    use ResolvesStorefront;

    public function __construct(protected StorefrontPublisher $publisher) {}

    public function store(Request $request): RedirectResponse
    {
        $storefront = $this->storefront();

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        // La primera publicacion pone la tienda en linea: nace apagada para no mostrar un
        // catalogo sin precios, y publicar es decir «ya esta lista».
        $first = $storefront->published_version_id === null;

        $version = $this->publisher->publish($storefront, $request->user(), $validated['label'] ?? null);

        if ($first && ! $storefront->is_online) {
            $storefront->forceFill(['is_online' => true])->save();
        }

        // Cambiar lo que ve el publico deja rastro de quien fue.
        AccessLog::log('storefront_published', $request->user()->id, [
            'storefront_id' => $storefront->id,
            'store' => $storefront->store?->name,
            'version' => $version->number,
        ]);

        return back()->with('success', 'La tienda quedó publicada (versión '.$version->number.').');
    }

    /**
     * Encender o apagar la tienda de la sede. Apagada, sus paginas dicen que no esta disponible
     * y no aparece en la landing; lo publicado se conserva.
     */
    public function online(Request $request): RedirectResponse
    {
        $storefront = $this->storefront();
        $online = (bool) $request->validate(['is_online' => ['required', 'boolean']])['is_online'];

        if ($online && $storefront->published_version_id === null) {
            return back()->withErrors(['publish' => 'Publica la tienda antes de ponerla en línea.']);
        }

        $storefront->forceFill(['is_online' => $online])->save();

        AccessLog::log($online ? 'storefront_online' : 'storefront_offline', $request->user()->id, [
            'storefront_id' => $storefront->id,
            'store' => $storefront->store?->name,
        ]);

        return back()->with('success', $online ? 'La tienda quedó en línea.' : 'La tienda quedó apagada: los clientes ven que no está disponible.');
    }
}
