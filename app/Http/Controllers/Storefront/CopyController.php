<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\Store;
use App\Models\Storefront;
use App\Services\Storefront\StorefrontManager;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Copiar la configuracion entre sedes.
 *
 * Siempre al **borrador** de destino, nunca a lo publicado: quien reciba la copia la revisa y
 * publica cuando quiera. De otro modo un clic aqui cambiaria la tienda de tres sedes a la vez.
 */
class CopyController extends Controller
{
    use ResolvesStorefront;

    public function __construct(protected StorefrontManager $manager) {}

    public function store(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $validated = $request->validate([
            'direction' => ['required', Rule::in(['from', 'to_all'])],
            'store_id' => ['required_if:direction,from', 'nullable', 'integer'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => [Rule::in(['theme', 'blocks', 'settings'])],
        ], [
            'store_id.required_if' => 'Elige de qué sede quieres copiar.',
            'parts.required' => 'Elige qué se copia.',
        ]);

        $current = $this->storefront();
        $parts = $validated['parts'];

        if ($validated['direction'] === 'from') {
            $source = Store::query()
                ->where('company_id', $companyId)
                ->findOrFail($validated['store_id']);

            $this->apply($this->manager->for($source), $current, $parts);

            AccessLog::log('storefront_copied', $request->user()->id, [
                'from_store' => $source->id,
                'to_store' => $current->store_id,
                'parts' => $parts,
            ]);

            return back()->with('success', 'Se copió la configuración de '.$source->name.' a tu borrador. Revísala y publícala.');
        }

        $targets = Store::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereKeyNot($current->store_id)
            ->get();

        foreach ($targets as $target) {
            $this->apply($current, $this->manager->for($target), $parts);
        }

        AccessLog::log('storefront_copied', $request->user()->id, [
            'from_store' => $current->store_id,
            'to_stores' => $targets->pluck('id')->all(),
            'parts' => $parts,
        ]);

        return back()->with(
            'success',
            $targets->count() === 1
                ? 'Se copió al borrador de la otra sede.'
                : 'Se copió al borrador de las otras '.$targets->count().' sedes.'
        );
    }

    /**
     * @param  list<string>  $parts
     */
    protected function apply(Storefront $from, Storefront $to, array $parts): void
    {
        $snapshot = $this->manager->snapshot($from);
        $target = $this->manager->snapshot($to);

        // Solo viaja lo que se eligio; lo demas se queda como estaba en el destino.
        $merged = [
            'theme' => in_array('theme', $parts, true) ? $snapshot['theme'] : $target['theme'],
            'blocks' => in_array('blocks', $parts, true) ? $snapshot['blocks'] : $target['blocks'],
            'settings' => in_array('settings', $parts, true) ? $snapshot['settings'] : $target['settings'],
            'zones' => in_array('settings', $parts, true) ? $snapshot['zones'] : $target['zones'],
            'slots' => in_array('settings', $parts, true) ? $snapshot['slots'] : $target['slots'],
            // El diseño viaja con su plantilla: si la sede de origen la sigue, la de destino
            // tambien queda siguiendola.
            'template' => in_array('blocks', $parts, true) ? ($snapshot['template'] ?? null) : ($target['template'] ?? null),
        ];

        $this->manager->restore($to, $merged);
    }
}
