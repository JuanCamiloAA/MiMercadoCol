<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Storefront\BlockCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Los bloques del borrador, pagina por pagina.
 *
 * Todo lo que pasa por aqui toca el borrador y nada mas. El cliente sigue viendo lo publicado
 * hasta que alguien publique, asi que se puede desarmar la portada entera sin miedo.
 *
 * Si la sede sigue una plantilla, sus bloques son los de la plantilla: se personaliza primero
 * (`TemplateLinkController::detach`) y despues se edita.
 */
class BlockController extends Controller
{
    use ResolvesStorefront;

    public function store(Request $request): RedirectResponse
    {
        $storefront = $this->storefront();

        if ($blocked = $this->followsTemplate($storefront)) {
            return $blocked;
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(BlockCatalogue::names())],
            'page' => ['sometimes', Rule::in(StorefrontBlock::PAGES)],
            'after' => ['nullable', 'integer'],
        ], ['type.required' => 'Elige qué bloque agregar.']);

        $page = $validated['page'] ?? StorefrontBlock::PAGE_HOME;
        $definition = BlockCatalogue::for($validated['type']);

        // Cada tipo dice en que paginas vale: un estante de producto en el carrito si, los
        // datos de la tienda en la pagina de categoria no.
        if (! in_array($page, $definition->pages(), true)) {
            return back()->withErrors([
                'type' => $definition->label().' no se puede usar en la página de '.mb_strtolower(StorefrontBlock::PAGE_LABELS[$page]).'.',
            ]);
        }

        $pageBlocks = StorefrontBlock::query()->where('storefront_id', $storefront->id)->where('page', $page);

        // El bloque nuevo cae justo debajo del que estaba seleccionado.
        $position = $validated['after'] ?? null;
        $order = $position && (clone $pageBlocks)->whereKey($position)->exists()
            ? (int) (clone $pageBlocks)->whereKey($position)->value('sort_order') + 1
            : (int) (clone $pageBlocks)->max('sort_order') + 1;

        DB::transaction(function () use ($storefront, $definition, $order, $page) {
            StorefrontBlock::query()
                ->where('storefront_id', $storefront->id)
                ->where('page', $page)
                ->where('sort_order', '>=', $order)
                ->increment('sort_order');

            StorefrontBlock::create([
                'storefront_id' => $storefront->id,
                'uuid' => (string) Str::uuid(),
                'type' => $definition->type(),
                'page' => $page,
                'sort_order' => $order,
                'is_visible' => true,
                'devices' => 'all',
                'payload' => $definition->normalize($definition->defaults()),
            ]);
        });

        return back()->with('success', $definition->label().' agregado al borrador.');
    }

    /**
     * Guardado automatico: el editor manda cada cambio con su debounce.
     */
    public function update(Request $request, StorefrontBlock $block): RedirectResponse
    {
        $this->assertOwned($block->storefront_id);

        if ($blocked = $this->followsTemplate($this->storefront())) {
            return $blocked;
        }

        $validated = $request->validate([
            'payload' => ['sometimes', 'array'],
            'is_visible' => ['sometimes', 'boolean'],
            'devices' => ['sometimes', Rule::in(StorefrontBlock::DEVICES)],
            'visible_from' => ['sometimes', 'nullable', 'date'],
            'visible_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:visible_from'],
        ], [
            'visible_to.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
        ]);

        $changes = collect($validated)->except('payload')->all();

        if (array_key_exists('payload', $validated)) {
            // Lo que llega del editor se recorta a la forma del tipo: un bloque guardado nunca
            // llega incompleto al renderizador.
            $changes['payload'] = BlockCatalogue::for($block->type)->normalize($validated['payload']);
        }

        $block->update($changes);

        return back(303);
    }

    public function duplicate(StorefrontBlock $block): RedirectResponse
    {
        $this->assertOwned($block->storefront_id);

        if ($blocked = $this->followsTemplate($this->storefront())) {
            return $blocked;
        }

        DB::transaction(function () use ($block) {
            StorefrontBlock::query()
                ->where('storefront_id', $block->storefront_id)
                ->where('page', $block->page)
                ->where('sort_order', '>', $block->sort_order)
                ->increment('sort_order');

            StorefrontBlock::create([
                'storefront_id' => $block->storefront_id,
                'uuid' => (string) Str::uuid(),
                'type' => $block->type,
                'page' => $block->page,
                'sort_order' => $block->sort_order + 1,
                'is_visible' => $block->is_visible,
                'visible_from' => $block->visible_from,
                'visible_to' => $block->visible_to,
                'devices' => $block->devices,
                'payload' => $block->payload,
            ]);
        });

        return back()->with('success', 'Bloque duplicado.');
    }

    public function destroy(StorefrontBlock $block): RedirectResponse
    {
        $this->assertOwned($block->storefront_id);

        if ($blocked = $this->followsTemplate($this->storefront())) {
            return $blocked;
        }

        $block->delete();

        return back()->with('success', 'Bloque eliminado del borrador.');
    }

    /** El orden de los bloques de una pagina. */
    public function reorder(Request $request): RedirectResponse
    {
        $storefront = $this->storefront();

        if ($blocked = $this->followsTemplate($storefront)) {
            return $blocked;
        }

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($validated, $storefront) {
            foreach ($validated['order'] as $position => $id) {
                StorefrontBlock::query()
                    ->where('storefront_id', $storefront->id)
                    ->whereKey($id)
                    ->update(['sort_order' => $position]);
            }
        });

        return back(303);
    }

    /** Una sede que sigue la plantilla no edita bloques: primero se personaliza. */
    protected function followsTemplate(Storefront $storefront): ?RedirectResponse
    {
        return $storefront->follows_template
            ? back()->withErrors(['template' => 'Esta tienda usa una plantilla. Personalízala para cambiar sus bloques.'])
            : null;
    }
}
