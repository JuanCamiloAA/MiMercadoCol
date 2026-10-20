<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\Settings\SettingRequests;
use App\Models\MediaAsset;
use App\Models\StorefrontBlock;
use App\Models\StorefrontTemplate;
use App\Models\StorefrontTheme;
use App\Storefront\BlockCatalogue;
use App\Storefront\Blocks\BlockData;
use App\Support\SectionAppearance;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El borrador de una plantilla: tema, estructura y bloques, guardados en `draft`.
 *
 * Son las mismas acciones del editor de una sede, sobre un JSON en vez de filas. Cada cambio
 * bloquea la fila de la plantilla: el guardado automatico manda varios seguidos y ninguno
 * puede pisar a otro.
 *
 * Una plantilla no guarda ids de una empresa: un bloque que traiga articulos, categorias o
 * promociones por id se rechaza, no se limpia en silencio.
 */
class StoreTemplateDraftController extends Controller
{
    public function theme(Request $request, StorefrontTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'scheme' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['scheme'])],
            'allow_scheme_toggle' => ['sometimes', 'boolean'],
            'font_pair' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['font_pair'])],
            'radius' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['radius'])],
            'density' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['density'])],
            'button_style' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['button_style'])],
            'logo_font' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['logo_font'])],
            'surface' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['surface'])],
            'heading_case' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['heading_case'])],
            'container' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['container'])],
            'button_shape' => ['sometimes', Rule::in(StorefrontTheme::OPTIONS['button_shape'])],
        ]);

        $this->mutate($template, function (array $draft) use ($validated) {
            $draft['theme'] = array_merge($draft['theme'] ?? [], array_intersect_key($validated, array_flip(StorefrontTemplate::DESIGN_KEYS['theme'])));

            return $draft;
        });

        return back(303);
    }

    public function settings(StorefrontTemplate $template, string $key): RedirectResponse
    {
        abort_unless(in_array($key, StorefrontTemplate::DESIGN_KEYS['settings'], true), 404, 'Ese ajuste no es de diseño: lo pone cada sede.');

        $value = SettingRequests::validate($key)->value();

        // Del pie, la plantilla no guarda lo que es de cada sede.
        $value = array_diff_key($value, array_flip(StorefrontTemplate::DESIGN_KEYS['settings_except'][$key] ?? []));

        $this->mutate($template, function (array $draft) use ($key, $value) {
            $draft['settings'][$key] = $value;

            return $draft;
        });

        return back(303);
    }

    public function storeBlock(Request $request, StorefrontTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(BlockCatalogue::names())],
            'page' => ['sometimes', Rule::in(StorefrontBlock::PAGES)],
            'after' => ['nullable', 'string'],
        ]);

        $page = $validated['page'] ?? StorefrontBlock::PAGE_HOME;
        $definition = BlockCatalogue::for($validated['type']);

        if (! in_array($page, $definition->pages(), true)) {
            return back()->withErrors(['type' => $definition->label().' no se puede usar en esa página.']);
        }

        $this->mutate($template, function (array $draft) use ($definition, $page, $validated) {
            $blocks = $this->pageBlocks($draft, $page);
            $position = collect($blocks)->search(fn (array $block) => $block['uuid'] === ($validated['after'] ?? null));
            $new = [
                'uuid' => (string) Str::uuid(),
                'type' => $definition->type(),
                'page' => $page,
                'sort_order' => 0,
                'is_visible' => true,
                'visible_from' => null,
                'visible_to' => null,
                'devices' => 'all',
                'payload' => $definition->normalize($definition->defaults(), BlockData::CONTEXT_TEMPLATE),
            ];

            array_splice($blocks, $position === false ? count($blocks) : $position + 1, 0, [$new]);

            return $this->withPage($draft, $page, $blocks);
        });

        return back()->with('success', $definition->label().' agregado a la plantilla.');
    }

    public function updateBlock(Request $request, StorefrontTemplate $template, string $uuid): RedirectResponse
    {
        $validated = $request->validate([
            'payload' => ['sometimes', 'array'],
            'is_visible' => ['sometimes', 'boolean'],
            'devices' => ['sometimes', Rule::in(StorefrontBlock::DEVICES)],
            'visible_from' => ['sometimes', 'nullable', 'date'],
            'visible_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:visible_from'],
        ]);

        $this->mutate($template, function (array $draft) use ($uuid, $validated) {
            $found = false;

            foreach ($draft['blocks'] as $index => $block) {
                if ($block['uuid'] !== $uuid) {
                    continue;
                }

                $found = true;

                if (array_key_exists('payload', $validated)) {
                    $definition = BlockCatalogue::for($block['type']);
                    $mediaIds = $definition->mediaIds($validated['payload']);
                    $violations = [
                        ...$definition->violations($validated['payload']),
                        // El fondo de una plantilla solo puede ser un medio de plataforma.
                        ...SectionAppearance::violations($validated['payload']['appearance'] ?? null, SectionAppearance::SURFACE_STORE, null, true),
                        // Y cualquier imagen del bloque (los logos de marcas, las fotos de ejemplo): una
                        // plantilla no puede mostrar lo que subió una empresa.
                        ...($mediaIds !== [] && MediaAsset::query()->withoutGlobalScopes()->whereIn('id', $mediaIds)->whereNotNull('company_id')->exists()
                            ? ['Una plantilla solo puede usar imágenes y videos de la plataforma.']
                            : []),
                    ];

                    // Se rechaza, no se limpia: quien edita tiene que saber que eso no va.
                    if ($violations !== []) {
                        throw ValidationException::withMessages(['payload' => $violations]);
                    }

                    $block['payload'] = $definition->normalize($validated['payload'], BlockData::CONTEXT_TEMPLATE);
                }

                foreach (['is_visible', 'devices', 'visible_from', 'visible_to'] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $block[$field] = $validated[$field];
                    }
                }

                $draft['blocks'][$index] = $block;
            }

            abort_unless($found, 404, 'Ese bloque ya no está en la plantilla.');

            return $draft;
        });

        return back(303);
    }

    public function duplicateBlock(StorefrontTemplate $template, string $uuid): RedirectResponse
    {
        $this->mutate($template, function (array $draft) use ($uuid) {
            $source = collect($draft['blocks'])->firstWhere('uuid', $uuid);
            abort_unless($source !== null, 404);

            $blocks = $this->pageBlocks($draft, $source['page'] ?? 'home');
            $position = collect($blocks)->search(fn (array $block) => $block['uuid'] === $uuid);
            array_splice($blocks, $position + 1, 0, [array_merge($source, ['uuid' => (string) Str::uuid()])]);

            return $this->withPage($draft, $source['page'] ?? 'home', $blocks);
        });

        return back()->with('success', 'Bloque duplicado.');
    }

    public function destroyBlock(StorefrontTemplate $template, string $uuid): RedirectResponse
    {
        $this->mutate($template, function (array $draft) use ($uuid) {
            $draft['blocks'] = array_values(array_filter($draft['blocks'], fn (array $block) => $block['uuid'] !== $uuid));

            return $draft;
        });

        return back()->with('success', 'Bloque eliminado de la plantilla.');
    }

    public function reorderBlocks(Request $request, StorefrontTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string'],
        ]);

        $this->mutate($template, function (array $draft) use ($validated) {
            $rank = array_flip($validated['order']);

            foreach ($draft['blocks'] as $index => $block) {
                if (isset($rank[$block['uuid']])) {
                    $draft['blocks'][$index]['sort_order'] = $rank[$block['uuid']];
                }
            }

            return $draft;
        });

        return back(303);
    }

    /**
     * Cambia el borrador con la fila bloqueada.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    protected function mutate(StorefrontTemplate $template, Closure $change): void
    {
        DB::transaction(function () use ($template, $change) {
            $locked = StorefrontTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $draft = $locked->draft ?? [];
            $draft['blocks'] ??= [];
            $draft['settings'] ??= [];
            $draft['theme'] ??= [];

            $locked->forceFill(['draft' => $change($draft)])->save();
        });
    }

    /**
     * Los bloques de una pagina, en su orden.
     *
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    protected function pageBlocks(array $draft, string $page): array
    {
        return collect($draft['blocks'] ?? [])
            ->filter(fn (array $block) => ($block['page'] ?? 'home') === $page)
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    /**
     * Pone los bloques de una pagina en su orden y deja los de las otras como estaban.
     *
     * @param  array<string, mixed>  $draft
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    protected function withPage(array $draft, string $page, array $blocks): array
    {
        $others = array_values(array_filter($draft['blocks'] ?? [], fn (array $block) => ($block['page'] ?? 'home') !== $page));

        foreach ($blocks as $position => $block) {
            $blocks[$position]['sort_order'] = $position;
        }

        $draft['blocks'] = [...$others, ...$blocks];

        return $draft;
    }
}
