<?php

namespace Database\Seeders;

use App\Http\Requests\Storefront\Settings\SettingRequests;
use App\Models\StorefrontBlock;
use App\Models\StorefrontTemplate;
use App\Models\StorefrontTheme;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\BlockCatalogue;
use App\Storefront\Blocks\BlockData;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\BannerPainter;
use Database\Seeders\Support\ScenePainter;
use Illuminate\Database\Seeder;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * El catalogo de plantillas de tienda: las de `data/storefront_templates.json`.
 *
 * Idempotente por `slug`: la que ya existe no se toca, aunque alguien la haya editado. Cada una
 * entra con sus imagenes pintadas como medios de plataforma, su miniatura, publicada («Plantilla
 * inicial») y activa. Nunca cambia la plantilla por defecto.
 *
 * Todo pasa por las mismas reglas del editor: el tema por las listas de `StorefrontTheme`, cada
 * ajuste por su request y cada bloque por `normalize` en contexto plantilla. Lo que no cumpla
 * revienta aqui, con su motivo, en vez de llegar torcido a una tienda.
 */
class StorefrontTemplateCatalogSeeder extends Seeder
{
    public const DATA = 'seeders/data/storefront_templates.json';

    /** @var array<string, int|null> Lo ya pintado en esta corrida, por receta y tamaño. */
    protected array $painted = [];

    public function __construct(
        protected StorefrontTemplateService $templates,
        protected StorefrontManager $manager,
        protected BannerPainter $painter,
    ) {}

    /**
     * Las plantillas del archivo, tal cual.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        $data = json_decode((string) file_get_contents(database_path(self::DATA)), true, 512, JSON_THROW_ON_ERROR);

        return array_values($data['templates'] ?? []);
    }

    public function run(): void
    {
        $author = User::query()->withoutGlobalScopes()->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))->first()
            ?? User::query()->withoutGlobalScopes()->orderBy('id')->first();

        if (! $author) {
            return;
        }

        foreach (self::catalog() as $entry) {
            if (StorefrontTemplate::query()->where('slug', $entry['slug'])->exists()) {
                continue;
            }

            $this->plant($entry, $author);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function plant(array $entry, User $author): StorefrontTemplate
    {
        // Primero lo que no pinta nada: si el JSON trae un error, revienta antes de llenar el disco.
        $empty = $this->templates->emptyDraft();
        $theme = $this->theme($entry, $empty['theme']);
        $settings = $this->settings($entry, $empty['settings']);
        $draft = ['theme' => $theme, 'blocks' => $this->blocks($entry), 'settings' => $settings];

        $cover = $this->coverRecipe($entry);
        $preview = $cover ? $this->painter->thumbnail($cover, $entry['name'], (string) ($entry['audience'] ?? '')) : null;

        $template = $this->templates->create($entry['name'], $entry['description'] ?? null, $entry['slug'], [
            'audience' => $entry['audience'] ?? null,
            'audience_group' => in_array($entry['audience_group'] ?? null, StorefrontTemplate::AUDIENCE_GROUPS, true) ? $entry['audience_group'] : null,
            'palette_suggestion' => $entry['palette_suggestion'] ?? null,
            'sort_order' => (int) ($entry['sort_order'] ?? 0),
        ]);

        $template->forceFill(['draft' => $draft, 'preview_media_id' => $preview?->id])->save();
        $this->templates->publish($template->refresh(), $author, 'Plantilla inicial');

        return $template->refresh();
    }

    /**
     * La forma del tema, con las mismas listas que acepta el editor.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    protected function theme(array $entry, array $defaults): array
    {
        $theme = array_merge($defaults, array_intersect_key($entry['theme'] ?? [], array_flip(StorefrontTemplate::DESIGN_KEYS['theme'])));
        $rules = ['allow_scheme_toggle' => ['boolean']];

        foreach (StorefrontTemplate::DESIGN_KEYS['theme'] as $key) {
            if (isset(StorefrontTheme::OPTIONS[$key])) {
                $rules[$key] = ['required', Rule::in(StorefrontTheme::OPTIONS[$key])];
            }
        }

        $this->check($entry, 'el tema', Validator::make($theme, $rules)->errors()->all());

        return $theme;
    }

    /**
     * Cada clave de diseño sobre su defecto, validada por el request del editor (en contexto
     * plantilla, que no acepta categorias por id). Del pie se quita lo que es de cada sede.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    protected function settings(array $entry, array $defaults): array
    {
        $settings = $defaults;
        // El request pide la clave completa, tambien lo que es de la sede (contacto del pie): se
        // valida sobre el defecto entero, como lo manda el editor, y despues se quita.
        $full = $this->manager->defaultSettings();

        foreach (StorefrontTemplate::DESIGN_KEYS['settings'] as $key) {
            if (! isset($entry['settings'][$key]) || ! is_array($entry['settings'][$key])) {
                continue;
            }

            $class = SettingRequests::MAP[$key];
            $request = $class::create('/plantilla', 'PATCH', ['value' => array_merge($full[$key] ?? [], $entry['settings'][$key])]);
            $route = (new Route('PATCH', 'plantilla', []))->name('platform.store_templates.settings');
            $request->setRouteResolver(fn () => $route);
            $request->setContainer(app())->setRedirector(app(Redirector::class));

            try {
                $request->validateResolved();
            } catch (ValidationException $exception) {
                $this->check($entry, 'el ajuste «'.$key.'»', array_merge(...array_values($exception->errors())));
            }

            $settings[$key] = array_diff_key($request->value(), array_flip(StorefrontTemplate::DESIGN_KEYS['settings_except'][$key] ?? []));
        }

        return $settings;
    }

    /**
     * Los bloques de todas las paginas, con sus imagenes ya pintadas.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    protected function blocks(array $entry): array
    {
        $rows = [];

        foreach ($entry['blocks'] ?? [] as $index => $block) {
            $type = (string) ($block['type'] ?? '');
            $page = (string) ($block['page'] ?? StorefrontBlock::PAGE_HOME);

            if (! BlockCatalogue::has($type)) {
                throw new RuntimeException("La plantilla «{$entry['slug']}» trae un bloque «{$type}» que no existe.");
            }

            $definition = BlockCatalogue::for($type);

            if (! in_array($page, $definition->pages(), true)) {
                throw new RuntimeException("La plantilla «{$entry['slug']}»: {$definition->label()} no va en la página «{$page}».");
            }

            $payload = $this->withMedia($type, (array) ($block['payload'] ?? []));

            // Un lanzamiento necesita una fecha por venir: tres semanas despues de sembrar, a las 6 p. m.
            if ($type === 'deal_countdown' && ($payload['style'] ?? null) === 'drop' && empty($payload['starts_at'])) {
                $payload['starts_at'] = CarbonImmutable::now('America/Bogota')->addDays(21)->setTime(18, 0)->format('Y-m-d\TH:i');
            }

            $this->check($entry, $definition->label(), $definition->violations($payload));

            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'type' => $type,
                'page' => $page,
                'sort_order' => (int) ($block['sort_order'] ?? $index),
                'is_visible' => true,
                'visible_from' => null,
                'visible_to' => null,
                'devices' => 'all',
                'payload' => $definition->normalize(array_merge($definition->defaults(), $payload), BlockData::CONTEXT_TEMPLATE),
            ];
        }

        return $rows;
    }

    /**
     * Cambia cada receta (`media`) por la imagen pintada (`media_id`), del tamaño que pide el
     * lugar donde va. Las laminas de la portada llevan tambien la de celular.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withMedia(string $type, array $payload): array
    {
        $cover = in_array($payload['layout'] ?? null, ['cover_center', 'editorial', 'split'], true)
            || in_array($payload['appearance']['height'] ?? null, ['screen', 'tall'], true);

        if ($type === 'hero') {
            foreach (['slides', 'side'] as $list) {
                foreach ($payload[$list] ?? [] as $i => $item) {
                    if (! $this->isRecipe($item['media'] ?? null)) {
                        continue;
                    }

                    $recipe = $item['media'];
                    unset($item['media']);

                    if ($list === 'slides') {
                        [$w, $h] = $cover ? [1920, 1080] : [1600, 600];
                        $focus = in_array($payload['layout'] ?? null, ['cover_center', 'split'], true) ? 'center' : 'right';
                        $item['media_id'] = $this->paint($recipe, $w, $h, $focus, $item['title'] ?? null);

                        // La dividida en celular es un recuadro apaisado: la vertical se recortaba hasta
                        // perder el motivo, y la de computador, con el motivo al centro, cabe mejor.
                        if (($payload['layout'] ?? null) !== 'split') {
                            $item['media_mobile_id'] = $this->paint($recipe, 900, 1400, 'center', $item['title'] ?? null);
                        }
                    } else {
                        $item['media_id'] = $this->paint($recipe, 800, 600, 'center', $item['title'] ?? null);
                    }

                    $payload[$list][$i] = $item;
                }
            }

            return $payload;
        }

        if ($type === 'banner_grid') {
            $layout = (string) ($payload['layout'] ?? '3');

            foreach ($payload['tiles'] ?? [] as $i => $tile) {
                if (! $this->isRecipe($tile['media'] ?? null)) {
                    continue;
                }

                $recipe = $tile['media'];
                unset($tile['media']);
                [$w, $h] = match (true) {
                    in_array($tile['style']['ratio'] ?? null, ['tall', 'portrait'], true) => [900, 1200],
                    $layout === '1+2' && $i === 0 => [1200, 1200],
                    $layout === '2' => [1600, 900],
                    $layout === '1' => [1600, 600],
                    default => [1200, 900],
                };
                $tile['media_id'] = $this->paint($recipe, $w, $h, 'center', $tile['title'] ?? null);
                $payload['tiles'][$i] = $tile;
            }

            return $payload;
        }

        if ($type === 'categories') {
            foreach ($payload['preview_media'] ?? [] as $i => $item) {
                if ($this->isRecipe($item['media'] ?? null)) {
                    $payload['preview_media'][$i] = ['label' => $item['label'] ?? null, 'media_id' => $this->paint($item['media'], 900, 1200, 'center', $item['label'] ?? null)];
                }
            }

            return $payload;
        }

        if ($this->isRecipe($payload['media'] ?? null)) {
            [$w, $h] = $type === 'look' ? [960, 1200] : [1200, 1200];
            $payload['media_id'] = $this->paint($payload['media'], $w, $h, 'center', $payload['title'] ?? null);
            unset($payload['media']);
        }

        return $payload;
    }

    /**
     * La receta de la portada: la de la primera lamina del primer `hero` de la portada.
     *
     * @param  array<string, mixed>  $entry
     * @return list<mixed>|null
     */
    protected function coverRecipe(array $entry): ?array
    {
        foreach ($entry['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'hero' && ($block['page'] ?? 'home') === 'home' && $this->isRecipe($block['payload']['slides'][0]['media'] ?? null)) {
                return $block['payload']['slides'][0]['media'];
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $recipe
     */
    protected function paint(array $recipe, int $width, int $height, string $focus, ?string $label): ?int
    {
        $key = json_encode($recipe).'|'.$width.'x'.$height.'|'.$focus;

        if (! array_key_exists($key, $this->painted)) {
            $alt = ScenePainter::describe($recipe);
            $label = trim((string) $label);
            $this->painted[$key] = $this->painter->scene($recipe, $width, $height, $label !== '' ? $label.': '.Str::lcfirst($alt) : $alt, $focus)?->id;
        }

        return $this->painted[$key];
    }

    protected function isRecipe(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && in_array($value[0] ?? null, ScenePainter::MOTIFS, true);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $errors
     */
    protected function check(array $entry, string $where, array $errors): void
    {
        if ($errors !== []) {
            throw new RuntimeException("La plantilla «{$entry['slug']}», en {$where}: ".implode(' ', $errors));
        }
    }
}
