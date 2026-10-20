<?php

namespace App\Services\Catalog;

use App\Services\ImageService;
use App\Services\SettingsService;
use App\Support\Uploads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Cuantas fotos se le montan a un articulo, a un articulo principal (familia) o a un servicio.
 *
 * El tope lo decide la empresa en Ajustes > Catalogo (`catalog.max_images`, 1 por defecto) y se
 * cuenta por ficha: lo que ya tiene mas lo que llega. Bajar el tope no borra nada: una ficha que
 * ya tenia mas fotos las conserva y solo deja quitar, no agregar.
 */
class CatalogImages
{
    /** El tope mas alto que se puede elegir: mas fotos ya no ayudan a vender y pesan en la tienda. */
    public const MAX_LIMIT = 10;

    /** Lo que vale como foto, en los tres formularios. */
    public const FILE_RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];

    public function __construct(
        protected SettingsService $settings,
        protected ImageService $images,
    ) {}

    /**
     * Guarda las fotos al final de la galeria, en el orden en que llegaron.
     *
     * @param  HasMany<Model, Model>  $gallery
     * @param  iterable<UploadedFile|null>  $files
     * @param  callable(int): array<string, mixed>|null  $extra  columnas propias de la foto por posicion
     * @return Collection<int, Model> las fotos nuevas
     */
    public function append(HasMany $gallery, iterable $files, int $companyId, string $folder, ?callable $extra = null): Collection
    {
        $sort = (int) $gallery->max('sort_order');
        $created = collect();

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $stored = $this->images->storeProductImage($file, $companyId, $folder);

            $created->push($gallery->create(array_merge([
                'path' => $stored['path'],
                'thumb_path' => $stored['thumb_path'],
                'sort_order' => ++$sort,
            ], $extra ? $extra($index) : [])));
        }

        return $created;
    }

    /**
     * Deja la foto elegida de primera (la portada) y las demas en el orden que tenian.
     *
     * @param  HasMany<Model, Model>  $gallery
     */
    public function makeFirst(HasMany $gallery, Model $chosen): void
    {
        $ids = (clone $gallery)->reorder()->orderBy('sort_order')->orderBy('id')->pluck('id')
            ->reject(fn ($id) => (int) $id === (int) $chosen->getKey())
            ->prepend($chosen->getKey())
            ->values();

        foreach ($ids as $position => $id) {
            $gallery->getRelated()->newQuery()->whereKey($id)->update(['sort_order' => $position + 1]);
        }
    }

    /** Borra los archivos de una foto; el registro lo borra quien llama. */
    public function deleteFiles(Model $image): void
    {
        Uploads::disk()->delete(array_values(array_filter([$image->getAttribute('path'), $image->getAttribute('thumb_path')])));
    }

    public function limit(int $companyId): int
    {
        return max(1, min(self::MAX_LIMIT, (int) $this->settings->get($companyId, 'catalog.max_images')));
    }

    /**
     * Agrega el error si lo que ya tiene la ficha mas lo que llega pasa del tope.
     */
    public function check(Validator $validator, int $companyId, int $current, int $incoming, string $field = 'images'): void
    {
        if ($incoming <= 0) {
            return;
        }

        $limit = $this->limit($companyId);

        if ($current + $incoming > $limit) {
            $validator->errors()->add($field, self::message($limit, $current));
        }
    }

    public static function message(int $limit, int $current): string
    {
        $room = max(0, $limit - $current);
        $cap = $limit === 1 ? 'una foto por ficha' : "hasta {$limit} fotos por ficha";

        return $room === 0
            ? "Tu empresa monta {$cap} y esta ya tiene {$current}: quita una antes de subir otra. El tope se cambia en Ajustes > Catálogo."
            : "Tu empresa monta {$cap}: a esta solo le caben {$room} más. El tope se cambia en Ajustes > Catálogo.";
    }

    /**
     * @return array<string, string>
     */
    public static function fileMessages(string $field = 'images'): array
    {
        return [
            "{$field}.array" => 'Las fotos no llegaron bien: vuelve a elegirlas.',
            "{$field}.*.image" => 'Cada archivo debe ser una imagen.',
            "{$field}.*.mimes" => 'Las fotos van en JPG, PNG o WebP.',
            "{$field}.*.max" => 'Cada foto debe pesar menos de 4 MB.',
        ];
    }
}
