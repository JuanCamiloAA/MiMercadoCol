<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPreviewBuilder;
use App\Services\Storefront\StorefrontRenderer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La vista previa del borrador.
 *
 * No pide sesion: pide una firma. El enlace lo emite el editor y dura treinta minutos, de modo
 * que se puede abrir en el telefono o mandarselo a alguien sin darle acceso al panel, y sin que
 * el borrador quede expuesto para siempre.
 *
 * La firma cubre la sede; que pagina se ve (y de que categoria o articulo) lo elige el editor
 * sin volver a firmar.
 */
class PreviewController extends Controller
{
    /** Lo que el editor cambia sin pedir otra firma. */
    public const FREE_PARAMETERS = ['device', 'page', 'category', 'product', 'scheme', 'v'];

    public function __construct(
        protected StorefrontRenderer $renderer,
        protected StorefrontManager $manager,
        protected StorefrontPreviewBuilder $builder,
    ) {}

    public function show(Request $request, Store $store): Response
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(self::FREE_PARAMETERS), 403, 'Este enlace de vista previa ya venció. Vuelve al editor y ábrelo de nuevo.');

        $validated = self::validatePreview($request);

        return Inertia::render('Storefront/Preview', $this->builder->build(
            $this->renderer->snapshotFor($this->manager->for($store), draft: true),
            $store,
            $validated['page'] ?? 'home',
            isset($validated['category']) ? (int) $validated['category'] : null,
            isset($validated['product']) ? (int) $validated['product'] : null,
            $validated['device'] ?? null,
            $validated['scheme'] ?? null,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function validatePreview(Request $request): array
    {
        return $request->validate([
            'device' => ['nullable', Rule::in(['mobile', 'desktop'])],
            'page' => ['nullable', Rule::in(StorefrontPreviewBuilder::VIEWS)],
            'category' => ['nullable', 'integer'],
            'product' => ['nullable', 'integer'],
            // El modo que el editor está revisando (Claro | Oscuro); sin él, el de la tienda.
            'scheme' => ['nullable', Rule::in(['light', 'dark'])],
        ]);
    }
}
