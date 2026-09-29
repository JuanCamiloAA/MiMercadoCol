<?php

namespace App\Services\Storefront;

use App\Models\MediaAsset;
use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Models\StorefrontTemplate;
use App\Models\StorefrontTemplateVersion;
use App\Models\StorefrontVersion;
use App\Models\User;
use App\Storefront\BlockCatalogue;
use App\Storefront\Blocks\BlockData;
use App\Storefront\Targets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * La plantilla base de la tienda y su relacion con las sedes.
 *
 * - Publicar la plantilla le llega a todas las sedes que la siguen, sin tocar sus versiones.
 * - Independizarse (`detach`) copia el diseño a la sede y sus imagenes a la biblioteca de la
 *   empresa: borrar o cambiar la plantilla despues no rompe nada.
 * - Volver (`reattach`) guarda antes una version del borrador, para poder deshacer.
 */
class StorefrontTemplateService
{
    public const KEEP_VERSIONS = 30;

    public function __construct(
        protected StorefrontManager $manager,
        protected StorefrontPublisher $publisher,
    ) {}

    /**
     * Un borrador vacio: la forma por defecto y la estructura por defecto, sin bloques.
     *
     * @return array<string, mixed>
     */
    public function emptyDraft(): array
    {
        $defaults = $this->manager->defaultSettings();

        return [
            'theme' => [
                'scheme' => 'light',
                'allow_scheme_toggle' => false,
                'font_pair' => 'mercado',
                'radius' => 'soft',
                'density' => 'cozy',
                'button_style' => 'solid',
            ],
            'blocks' => [],
            'settings' => StorefrontTemplate::designOf(['settings' => $defaults])['settings'],
        ];
    }

    public function create(string $name, ?string $description): StorefrontTemplate
    {
        return StorefrontTemplate::create([
            'name' => $name,
            'description' => $description,
            'draft' => $this->emptyDraft(),
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    /**
     * Lo que impide publicar la plantilla y lo que conviene saber.
     *
     * @return array{blocking: list<array{message: string, hint: string|null}>, warnings: list<array{message: string, hint: string|null}>}
     */
    public function validate(StorefrontTemplate $template): array
    {
        $draft = $template->draft ?? [];
        $blocks = $draft['blocks'] ?? [];
        $media = $this->publisher->mediaFacts($blocks, null);
        $blocking = [];
        $warnings = [];

        foreach ($blocks as $block) {
            if (! BlockCatalogue::has($block['type'] ?? '')) {
                continue;
            }

            $definition = BlockCatalogue::for($block['type']);
            $where = StorefrontBlock::PAGE_LABELS[$block['page'] ?? 'home'] ?? 'Portada';

            foreach ($definition->issues($block['payload'] ?? [], $media, BlockData::CONTEXT_TEMPLATE) as $issue) {
                $entry = ['message' => $definition->label().' ('.mb_strtolower($where).'): '.$issue['message'], 'hint' => 'Contenido · '.$where];

                if ($issue['level'] === 'block') {
                    $blocking[] = $entry;
                } else {
                    $warnings[] = $entry;
                }
            }
        }

        $settings = app(StorefrontStructure::class)->withDefaults($draft['settings'] ?? []);

        foreach ($settings['nav']['items'] ?? [] as $item) {
            if (($item['target']['kind'] ?? null) === Targets::KIND_CATEGORY) {
                $blocking[] = ['message' => 'El menú enlaza una categoría por id: una plantilla no puede.', 'hint' => 'Contenido · Menú de categorías'];

                break;
            }
        }

        if (($draft['blocks'] ?? []) === []) {
            $warnings[] = ['message' => 'La plantilla no tiene bloques: las tiendas que la sigan quedan con la portada vacía.', 'hint' => 'Contenido'];
        }

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }

    /**
     * Publica el borrador. Las sedes que la siguen lo ven en la proxima visita.
     */
    public function publish(StorefrontTemplate $template, User $user, ?string $label = null): StorefrontTemplateVersion
    {
        $checks = $this->validate($template);

        if ($checks['blocking'] !== []) {
            throw ValidationException::withMessages([
                'publish' => array_map(fn (array $issue) => $issue['message'], $checks['blocking']),
            ]);
        }

        return DB::transaction(function () use ($template, $user, $label) {
            $version = $this->version($template, $template->draft, $user, $label);

            $template->forceFill([
                'published' => $template->draft,
                'published_at' => now(),
                'published_by_user_id' => $user->id,
            ])->save();

            $this->prune($template);
            $this->forgetFollowers($template);

            return $version;
        });
    }

    /** Vuelve a una version: se publica una copia y el borrador queda igual a ella. */
    public function restore(StorefrontTemplate $template, StorefrontTemplateVersion $version, User $user): StorefrontTemplateVersion
    {
        abort_if($version->template_id !== $template->id, 403, 'Esa versión no es de esta plantilla.');

        return DB::transaction(function () use ($template, $version, $user) {
            $copy = $this->version($template, $version->snapshot, $user, 'Se volvió a la versión '.$version->number);

            $template->forceFill([
                'draft' => $version->snapshot,
                'published' => $version->snapshot,
                'published_at' => now(),
                'published_by_user_id' => $user->id,
            ])->save();

            $this->prune($template);
            $this->forgetFollowers($template);

            return $copy;
        });
    }

    /**
     * La plantilla con que nacen las tiendas nuevas. Una sola a la vez: lo hace cumplir esta
     * transaccion, no la base.
     */
    public function setDefault(StorefrontTemplate $template): void
    {
        if (! $template->is_active || $template->published === null) {
            throw ValidationException::withMessages(['template' => 'Solo una plantilla activa y publicada puede ser la de por defecto.']);
        }

        DB::transaction(function () use ($template) {
            StorefrontTemplate::query()->lockForUpdate()->get();
            StorefrontTemplate::query()->whereKeyNot($template->id)->where('is_default', true)->update(['is_default' => false]);
            $template->forceFill(['is_default' => true])->save();
        });
    }

    public function setActive(StorefrontTemplate $template, bool $active): void
    {
        if (! $active && $template->is_default) {
            throw ValidationException::withMessages(['template' => 'La plantilla por defecto no se puede desactivar: marca otra primero.']);
        }

        $template->forceFill(['is_active' => $active])->save();
    }

    /**
     * La sede deja de seguir la plantilla y se queda con su diseño.
     *
     * Lo que ve el cliente no cambia: se publica una version con el mismo diseño que tenia, ya
     * con las imagenes copiadas a la biblioteca de la empresa.
     */
    public function detach(Storefront $storefront, User $user): void
    {
        DB::transaction(function () use ($storefront, $user) {
            $storefront = Storefront::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($storefront->id);

            if (! $storefront->follows_template) {
                return;
            }

            $template = $storefront->template;
            $design = $template?->published ?? $this->emptyDraft();
            $design = $this->copyPlatformMedia($design, (int) $storefront->company_id, $user);
            $flags = ['template' => ['id' => $storefront->template_id, 'follows' => false]];

            // Lo publicado: el mismo diseño que el cliente ve hoy, ya como propio.
            if ($storefront->publishedVersion) {
                $published = array_merge(StorefrontTemplate::compose($storefront->publishedVersion->snapshot, $design), $flags);

                $version = StorefrontVersion::create([
                    'storefront_id' => $storefront->id,
                    'number' => (int) StorefrontVersion::query()->where('storefront_id', $storefront->id)->max('number') + 1,
                    'label' => 'Diseño propio'.($template ? ' (desde «'.$template->name.'»)' : ''),
                    'snapshot' => $published,
                    'created_by_user_id' => $user->id,
                    'published_at' => now(),
                ]);

                $storefront->forceFill(['published_version_id' => $version->id, 'published_at' => now(), 'published_by_user_id' => $user->id])->save();
            }

            // El borrador: el diseño de la plantilla sobre lo que la sede tenia en el suyo.
            $this->manager->restore($storefront, array_merge(StorefrontTemplate::compose($this->manager->snapshot($storefront), $design), $flags));
            $this->publisher->forget($storefront);
        });
    }

    /**
     * Vuelve a la plantilla (la suya, u otra que se elija). Antes guarda el borrador como una
     * version sin publicar: desde el historial se puede deshacer.
     */
    public function reattach(Storefront $storefront, User $user, ?StorefrontTemplate $template = null): void
    {
        $template ??= $storefront->template ?? StorefrontManager::defaultTemplate();

        if (! $template || ! $template->is_active || $template->published === null) {
            throw ValidationException::withMessages(['template' => 'Esa plantilla no está disponible.']);
        }

        DB::transaction(function () use ($storefront, $user, $template) {
            StorefrontVersion::create([
                'storefront_id' => $storefront->id,
                'number' => (int) StorefrontVersion::query()->where('storefront_id', $storefront->id)->max('number') + 1,
                'label' => 'Diseño propio antes de volver a «'.$template->name.'»',
                'snapshot' => $this->manager->snapshot($storefront),
                'created_by_user_id' => $user->id,
                'published_at' => null,
            ]);

            $storefront->forceFill(['template_id' => $template->id, 'follows_template' => true])->save();
            $this->publisher->forget($storefront);
        });
    }

    /** Aplicar otra plantilla es volver, eligiendo cual. */
    public function apply(Storefront $storefront, StorefrontTemplate $template, User $user): void
    {
        $this->reattach($storefront, $user, $template);
    }

    /**
     * Copia a la biblioteca de la empresa las imagenes de plataforma que usa el diseño y
     * reescribe sus ids. Archivos nuevos y filas nuevas: si mañana se borra la plantilla o su
     * imagen, la tienda independizada no pierde nada.
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public function copyPlatformMedia(array $design, int $companyId, ?User $user = null): array
    {
        $ids = [];

        foreach ($design['blocks'] ?? [] as $block) {
            if (BlockCatalogue::has($block['type'] ?? '')) {
                array_push($ids, ...BlockCatalogue::for($block['type'])->mediaIds($block['payload'] ?? []));
            }
        }

        if ($ids === []) {
            return $design;
        }

        $map = [];

        foreach (MediaAsset::platform()->whereIn('id', array_unique($ids))->get() as $asset) {
            $map[$asset->id] = $this->copyAsset($asset, $companyId, $user)->id;
        }

        $design['blocks'] = array_map(function (array $block) use ($map) {
            $block['payload'] = $this->rewriteMedia($block['payload'] ?? [], $map);

            return $block;
        }, $design['blocks'] ?? []);

        return $design;
    }

    protected function copyAsset(MediaAsset $asset, int $companyId, ?User $user): MediaAsset
    {
        $disk = Storage::disk($asset->disk);
        $directory = 'storefront/'.$companyId;
        $name = Str::random(32);
        $copy = function (string $path, string $suffix) use ($disk, $directory, $name): string {
            $target = $directory.'/'.$name.$suffix.'.'.pathinfo($path, PATHINFO_EXTENSION);

            if ($disk->exists($path)) {
                $disk->copy($path, $target);
            }

            return $target;
        };

        $variants = [];

        foreach ($asset->variants ?? [] as $key => $path) {
            $variants[$key] = $copy($path, '-'.$key);
        }

        return MediaAsset::query()->withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'path' => $copy($asset->path, ''),
            'disk' => $asset->disk,
            'mime' => $asset->mime,
            'width' => $asset->width,
            'height' => $asset->height,
            'bytes' => $asset->bytes,
            'alt' => $asset->alt,
            'focal_x' => $asset->focal_x,
            'focal_y' => $asset->focal_y,
            'variants' => $variants,
            'created_by_user_id' => $user?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $map
     * @return array<string, mixed>
     */
    protected function rewriteMedia(array $payload, array $map): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->rewriteMedia($value, $map);
            } elseif (BlockData::isMediaKey($key) && is_numeric($value) && isset($map[(int) $value])) {
                $payload[$key] = $map[(int) $value];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function version(StorefrontTemplate $template, array $snapshot, User $user, ?string $label): StorefrontTemplateVersion
    {
        return StorefrontTemplateVersion::create([
            'template_id' => $template->id,
            'number' => (int) StorefrontTemplateVersion::query()->where('template_id', $template->id)->max('number') + 1,
            'snapshot' => $snapshot,
            'label' => $label,
            'created_by_user_id' => $user->id,
            'published_at' => now(),
        ]);
    }

    protected function prune(StorefrontTemplate $template): void
    {
        $keep = StorefrontTemplateVersion::query()->where('template_id', $template->id)->orderByDesc('number')->limit(self::KEEP_VERSIONS)->pluck('id');

        StorefrontTemplateVersion::query()->where('template_id', $template->id)->whereNotIn('id', $keep)->delete();
    }

    /** Las sedes que la siguen se vuelven a pintar con la plantilla nueva. */
    public function forgetFollowers(StorefrontTemplate $template): void
    {
        Storefront::query()->withoutGlobalScopes()
            ->where('template_id', $template->id)
            ->where('follows_template', true)
            ->get()
            ->each(fn (Storefront $storefront) => $this->publisher->forget($storefront));
    }
}
