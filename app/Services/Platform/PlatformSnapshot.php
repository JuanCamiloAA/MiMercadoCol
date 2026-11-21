<?php

namespace App\Services\Platform;

use App\Models\BillingCycle;
use App\Models\BusinessCategory;
use App\Models\LandingBlock;
use App\Models\LandingVersion;
use App\Models\MediaAsset;
use App\Models\MembershipPlan;
use App\Models\PlatformSetting;
use App\Models\StorefrontTemplate;
use App\Models\StorefrontTemplateVersion;
use App\Models\User;
use App\Services\PlatformSettingsService;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\Blocks\BlockData;
use App\Support\Uploads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Lo que es de la plataforma y no de ninguna empresa, llevado de una base a otra: ciclos de cobro,
 * planes de membresia, clasificaciones de empresa, ajustes de plataforma (marca, SEO, textos
 * legales), la landing (borrador y version publicada) y las plantillas de tienda con sus medios.
 *
 * `export()` lo saca de la base actual a `database/data/platform_snapshot`: un JSON y los archivos
 * que ese JSON nombra. `import()` lo carga en otra base sin duplicar: cada cosa se busca por su
 * clave natural (codigo, slug, clave, ruta) y los ids de los medios se traducen a los de destino.
 *
 * Las llaves de la pasarela no viajan: son secretos y cada ambiente pone las suyas.
 */
final class PlatformSnapshot
{
    public const DIR = 'data/platform_snapshot';

    /** Nota de la version que deja una importacion en la landing o en una plantilla que ya existia. */
    public const NOTE = 'Traída del ambiente local';

    public function __construct(
        private PlatformSettingsService $platform,
        private StorefrontTemplateService $templates,
    ) {}

    public static function path(string $file = ''): string
    {
        return database_path(self::DIR.($file !== '' ? '/'.$file : ''));
    }

    /**
     * @return array{files: int, missing: list<string>, bytes: int}
     */
    public function export(?string $dir = null): array
    {
        $dir ??= self::path();

        $settings = PlatformSetting::query()->orderBy('key')->get()
            ->filter(fn (PlatformSetting $row) => array_key_exists($row->key, PlatformSettingsService::DEFAULTS))
            ->mapWithKeys(fn (PlatformSetting $row) => [$row->key => $row->value])
            ->all();

        $media = MediaAsset::platform()->orderBy('id')->get()->map(fn (MediaAsset $asset) => [
            'id' => $asset->id,
            'path' => $asset->path,
            'mime' => $asset->mime,
            'width' => $asset->width,
            'height' => $asset->height,
            'bytes' => $asset->bytes,
            'alt' => $asset->alt,
            'focal_x' => $asset->focal_x,
            'focal_y' => $asset->focal_y,
            'variants' => $asset->variants,
        ])->all();

        $templates = StorefrontTemplate::query()->with(['versions' => fn ($query) => $query->orderBy('number')])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (StorefrontTemplate $template) => [
                'slug' => $template->slug,
                'name' => $template->name,
                'description' => $template->description,
                'audience' => $template->audience,
                'audience_group' => $template->audience_group,
                'palette_suggestion' => $template->palette_suggestion,
                'sort_order' => $template->sort_order,
                'preview_media_id' => $template->preview_media_id,
                'draft' => $template->draft,
                'published' => $template->published,
                'published_at' => $template->published_at?->format('Y-m-d H:i:s'),
                'is_default' => $template->is_default,
                'is_active' => $template->is_active,
                'versions' => $template->versions->map(fn (StorefrontTemplateVersion $version) => [
                    'number' => $version->number,
                    'snapshot' => $version->snapshot,
                    'label' => $version->label,
                    'published_at' => $version->published_at?->format('Y-m-d H:i:s'),
                ])->values()->all(),
            ])->values()->all();

        $blocks = LandingBlock::query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (LandingBlock $block) => $block->only($block->getFillable()))
            ->values()->all();

        $data = [
            'exported_at' => now()->format('Y-m-d H:i:s'),
            'billing_cycles' => $this->fillables(BillingCycle::query()->orderBy('sort_order')->orderBy('id')->get()),
            'membership_plans' => $this->fillables(MembershipPlan::query()->orderBy('sort_order')->orderBy('id')->get()),
            'business_categories' => $this->fillables(BusinessCategory::query()->orderBy('sort_order')->orderBy('id')->get()),
            'platform_settings' => $settings,
            'media' => $media,
            'templates' => $templates,
            'landing' => [
                'blocks' => $blocks,
                'published' => LandingVersion::query()->orderByDesc('number')->first()?->snapshot,
            ],
        ];

        $data['files'] = $this->referencedFiles($data);

        // Se rehace la carpeta entera: un archivo que ya no se usa no debe seguir viajando.
        File::deleteDirectory($dir.'/files');
        File::ensureDirectoryExists($dir.'/files');

        $disk = Uploads::disk();
        $missing = [];
        $bytes = 0;

        foreach ($data['files'] as $index => $file) {
            if (! $disk->exists($file)) {
                $missing[] = $file;
                unset($data['files'][$index]);

                continue;
            }

            $target = $dir.'/files/'.$file;
            File::ensureDirectoryExists(dirname($target));
            $this->copyStream($disk->readStream($file), $target);
            $bytes += (int) filesize($target);
        }

        $data['files'] = array_values($data['files']);

        File::put($dir.'/snapshot.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return ['files' => count($data['files']), 'missing' => $missing, 'bytes' => $bytes];
    }

    /**
     * @return array<string, int|list<string>>
     */
    public function import(User $author, ?string $dir = null): array
    {
        $dir ??= self::path();
        $file = $dir.'/snapshot.json';

        if (! is_file($file)) {
            throw new RuntimeException("No existe {$file}. Generalo con «php artisan platform:snapshot export».");
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        // Primero los archivos: si uno no sube, la base no queda apuntando a algo que no existe.
        $uploaded = $this->uploadFiles($dir, $data['files'] ?? []);

        return DB::transaction(function () use ($data, $author, $uploaded) {
            foreach ($data['billing_cycles'] ?? [] as $row) {
                $this->upsert(BillingCycle::query()->where('code', $row['code'])->first() ?? new BillingCycle, $row);
            }

            foreach ($data['membership_plans'] ?? [] as $row) {
                $this->upsert(MembershipPlan::query()->where('slug', $row['slug'])->first() ?? new MembershipPlan, $row);
            }

            foreach ($data['business_categories'] ?? [] as $row) {
                $category = BusinessCategory::query()->where('slug', $row['slug'])->first()
                    ?? BusinessCategory::query()->where('name', $row['name'])->first()
                    ?? new BusinessCategory;

                $this->upsert($category, $row);
            }

            $this->platform->setMany($data['platform_settings'] ?? []);

            $media = $this->importMedia($data['media'] ?? [], $author);
            $unmapped = [];
            $templates = $this->importTemplates($data['templates'] ?? [], $media, $author, $unmapped);
            $landing = $this->importLanding($data['landing'] ?? [], $author);

            return [
                'billing_cycles' => count($data['billing_cycles'] ?? []),
                'membership_plans' => count($data['membership_plans'] ?? []),
                'business_categories' => count($data['business_categories'] ?? []),
                'platform_settings' => count($data['platform_settings'] ?? []),
                'media' => count($media),
                'templates' => $templates,
                'landing_blocks' => $landing,
                'files_uploaded' => $uploaded,
                'unmapped_media' => array_values(array_unique($unmapped)),
            ];
        });
    }

    /**
     * @param  iterable<Model>  $models
     * @return list<array<string, mixed>>
     */
    private function fillables(iterable $models): array
    {
        $rows = [];

        foreach ($models as $model) {
            $rows[] = $model->only($model->getFillable());
        }

        return $rows;
    }

    /**
     * Las rutas del disco que el JSON usa: el original y los recortes de cada medio, y todo
     * `landing/...` que aparezca en la landing o en los ajustes (videos, imagenes, logo, favicon).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function referencedFiles(array $data): array
    {
        $files = [];

        foreach ($data['media'] as $asset) {
            $files[] = $asset['path'];
            array_push($files, ...array_values(array_filter((array) ($asset['variants'] ?? []), 'is_string')));
        }

        array_walk_recursive($data['landing'], function ($value) use (&$files) {
            if (is_string($value) && str_starts_with($value, 'landing/')) {
                $files[] = $value;
            }
        });

        foreach ($data['platform_settings'] as $value) {
            if (is_string($value) && str_starts_with($value, 'landing/')) {
                $files[] = $value;
            }
        }

        $files = array_values(array_unique(array_filter($files)));
        sort($files);

        return $files;
    }

    /**
     * Sube al disco de archivos lo que no este ya alli (en produccion, Firebase).
     *
     * @param  list<string>  $files
     */
    private function uploadFiles(string $dir, array $files): int
    {
        $disk = Uploads::disk();
        $uploaded = 0;

        foreach ($files as $file) {
            if ($disk->exists($file)) {
                continue;
            }

            $source = $dir.'/files/'.$file;
            $stream = is_file($source) ? fopen($source, 'rb') : false;

            if ($stream === false) {
                throw new RuntimeException("Falta {$source} en la carpeta de la exportación.");
            }

            try {
                if (! $disk->writeStream($file, $stream)) {
                    throw new RuntimeException("No se pudo subir {$file} al disco «".Uploads::diskName().'».');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $uploaded++;
        }

        return $uploaded;
    }

    /**
     * Medios de plataforma: el que ya esta en esa ruta se reutiliza. Devuelve id de origen => id de destino.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, int>
     */
    private function importMedia(array $rows, User $author): array
    {
        $map = [];

        foreach ($rows as $row) {
            $attributes = [
                'path' => $row['path'],
                'disk' => Uploads::diskName(),
                'mime' => $row['mime'] ?? null,
                'width' => $row['width'] ?? null,
                'height' => $row['height'] ?? null,
                'bytes' => $row['bytes'] ?? null,
                'alt' => $row['alt'] ?? null,
                'focal_x' => $row['focal_x'] ?? 0.5,
                'focal_y' => $row['focal_y'] ?? 0.5,
                'variants' => $row['variants'] ?? null,
            ];

            $asset = MediaAsset::platform()->where('path', $row['path'])->first();

            if ($asset) {
                $asset->forceFill($attributes)->saveQuietly();
            } else {
                $asset = MediaAsset::createForPlatform([...$attributes, 'created_by_user_id' => $author->id]);
            }

            $map[(int) $row['id']] = $asset->id;
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, int>  $media
     * @param  list<int>  $unmapped
     */
    private function importTemplates(array $rows, array $media, User $author, array &$unmapped): int
    {
        $defaultId = null;

        foreach ($rows as $row) {
            $template = StorefrontTemplate::query()->where('slug', $row['slug'])->first() ?? new StorefrontTemplate;
            $isNew = ! $template->exists;
            $published = $row['published'] === null ? null : $this->remap($row['published'], $media, $unmapped);
            $preview = $row['preview_media_id'] ? ($media[(int) $row['preview_media_id']] ?? null) : null;

            $template->forceFill([
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['description'],
                'audience' => $row['audience'],
                'audience_group' => $row['audience_group'],
                'palette_suggestion' => $row['palette_suggestion'],
                'sort_order' => $row['sort_order'],
                'preview_media_id' => $preview,
                'draft' => $this->remap($row['draft'], $media, $unmapped),
                'published' => $published,
                'published_at' => $published === null ? null : ($row['published_at'] ?? now()),
                'published_by_user_id' => $published === null ? null : $author->id,
                'is_active' => $row['is_active'],
            ]);

            if ($isNew) {
                $template->is_default = false;
            }

            $template->save();

            if ($isNew) {
                foreach ($row['versions'] as $version) {
                    StorefrontTemplateVersion::query()->create([
                        'template_id' => $template->id,
                        'number' => $version['number'],
                        'snapshot' => $this->remap($version['snapshot'], $media, $unmapped),
                        'label' => $version['label'],
                        'created_by_user_id' => $author->id,
                        'published_at' => $version['published_at'],
                    ]);
                }
            } elseif ($published !== null) {
                // La historia de destino no se reescribe: si lo publicado cambio, es una version mas.
                $latest = $template->versions()->orderByDesc('number')->first();

                if (! $latest || $latest->snapshot != $published) {
                    StorefrontTemplateVersion::query()->create([
                        'template_id' => $template->id,
                        'number' => ($latest?->number ?? 0) + 1,
                        'snapshot' => $published,
                        'label' => self::NOTE,
                        'created_by_user_id' => $author->id,
                        'published_at' => now(),
                    ]);
                }
            }

            if ($row['is_default'] && $published !== null && $row['is_active']) {
                $defaultId = $template->id;
            }

            $this->templates->forgetFollowers($template);
        }

        if ($defaultId !== null) {
            StorefrontTemplate::query()->whereKeyNot($defaultId)->where('is_default', true)->update(['is_default' => false]);
            StorefrontTemplate::query()->whereKey($defaultId)->update(['is_default' => true]);
        }

        return count($rows);
    }

    /**
     * La landing queda igual a la de origen: el borrador se reemplaza y, si lo publicado de
     * destino es distinto, se publica como version nueva.
     *
     * @param  array<string, mixed>  $landing
     */
    private function importLanding(array $landing, User $author): int
    {
        $blocks = $landing['blocks'] ?? [];

        if ($blocks === []) {
            return 0;
        }

        LandingBlock::query()->delete();

        foreach ($blocks as $block) {
            LandingBlock::query()->create($block);
        }

        $published = $landing['published'] ?? null;
        $latest = LandingVersion::query()->orderByDesc('number')->first();

        if ($published !== null && (! $latest || $latest->snapshot != $published)) {
            LandingVersion::query()->create([
                'number' => ($latest?->number ?? 0) + 1,
                'snapshot' => $published,
                'note' => self::NOTE,
                'published_by' => $author->id,
                'published_at' => now(),
            ]);
        }

        return count($blocks);
    }

    /**
     * Cambia cada id de medio (`media_id`, `media_mobile_id`, `video_media_id`… en cualquier nivel)
     * por el de destino.
     *
     * @param  array<int, int>  $media
     * @param  list<int>  $unmapped
     */
    private function remap(mixed $value, array $media, array &$unmapped): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (BlockData::isMediaKey($key) && (is_int($item) || (is_string($item) && ctype_digit($item)))) {
                if (! isset($media[(int) $item])) {
                    $unmapped[] = (int) $item;
                }

                $value[$key] = $media[(int) $item] ?? null;
            } elseif (is_array($item)) {
                $value[$key] = $this->remap($item, $media, $unmapped);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsert(Model $model, array $row): void
    {
        $model->forceFill(array_intersect_key($row, array_flip($model->getFillable())))->save();
    }

    /**
     * @param  resource|null  $source
     */
    private function copyStream($source, string $target): void
    {
        $handle = fopen($target, 'wb');

        if (! is_resource($source) || $handle === false) {
            throw new RuntimeException("No se pudo copiar a {$target}.");
        }

        stream_copy_to_stream($source, $handle);
        fclose($source);
        fclose($handle);
    }
}
