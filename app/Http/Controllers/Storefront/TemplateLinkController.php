<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\StorefrontTemplate;
use App\Services\Storefront\StorefrontTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * La sede y la plantilla base: personalizar (dejar de seguirla) y volver a ella o aplicar otra.
 */
class TemplateLinkController extends Controller
{
    use ResolvesStorefront;

    public function __construct(protected StorefrontTemplateService $templates) {}

    /**
     * «Personalizar»: la tienda se queda con el diseño de hoy como propio, con sus imagenes ya
     * en la biblioteca de la empresa, y deja de recibir los cambios de la plantilla.
     */
    public function detach(Request $request): RedirectResponse
    {
        $storefront = $this->storefront();
        $name = $storefront->template?->name;

        $this->templates->detach($storefront, $request->user());

        AccessLog::log('storefront_template_detached', $request->user()->id, [
            'storefront_id' => $storefront->id,
            'template' => $name,
        ]);

        return back()->with('success', 'La tienda quedó con diseño propio. Ya puedes cambiar lo que quieras.');
    }

    /**
     * «Volver a la plantilla» (o aplicar otra). El diseño propio queda guardado como una
     * version del historial, por si hay que deshacer.
     */
    public function reattach(Request $request): RedirectResponse
    {
        $validated = $request->validate(['template_id' => ['nullable', 'integer']]);
        $storefront = $this->storefront();
        $template = isset($validated['template_id'])
            ? StorefrontTemplate::query()->where('is_active', true)->whereNotNull('published')->findOrFail($validated['template_id'])
            : null;

        $this->templates->reattach($storefront, $request->user(), $template);

        AccessLog::log('storefront_template_attached', $request->user()->id, [
            'storefront_id' => $storefront->id,
            'template_id' => $storefront->refresh()->template_id,
        ]);

        return back()->with('success', 'La tienda volvió a la plantilla «'.$storefront->template?->name.'». Tu diseño anterior quedó en el historial.');
    }
}
