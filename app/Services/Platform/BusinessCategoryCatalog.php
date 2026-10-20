<?php

namespace App\Services\Platform;

use App\Models\BusinessCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * El catalogo de clasificaciones de empresa.
 *
 * La plataforma nacio para la canasta familiar, pero ya la usan comercios de otros tipos. Lo de
 * fabrica es un punto de partida: el super usuario crea, renombra, ordena y desactiva las suyas en
 * Plataforma > Clasificaciones.
 */
class BusinessCategoryCatalog
{
    /** La de las empresas que ya existian antes de clasificar: todas vendian mercado. */
    public const DEFAULT_SLUG = 'alimentos-y-supermercados';

    /**
     * Lo que trae la plataforma. El icono es uno de `config/landing_icons.php`.
     *
     * @var list<array{name: string, icon: string}>
     */
    public const DEFAULTS = [
        ['name' => 'Alimentos y supermercados', 'icon' => 'Basket'],
        ['name' => 'Textiles y confección', 'icon' => 'TShirt'],
        ['name' => 'Belleza y cuidado personal', 'icon' => 'FlowerLotus'],
        ['name' => 'Artículos varios', 'icon' => 'Package'],
        ['name' => 'Droguería y salud', 'icon' => 'FirstAidKit'],
        ['name' => 'Ferretería y construcción', 'icon' => 'Hammer'],
        ['name' => 'Hogar y decoración', 'icon' => 'Armchair'],
        ['name' => 'Tecnología y celulares', 'icon' => 'DeviceMobile'],
        ['name' => 'Mascotas', 'icon' => 'PawPrint'],
        ['name' => 'Papelería y librería', 'icon' => 'Notebook'],
        ['name' => 'Restaurantes y comidas', 'icon' => 'ForkKnife'],
    ];

    /** Siembra lo de fabrica. Si ya hay una con ese nombre, la deja como este. */
    public function seedDefaults(): void
    {
        foreach (self::DEFAULTS as $order => $definition) {
            BusinessCategory::query()->firstOrCreate(
                ['slug' => Str::slug($definition['name'])],
                ['name' => $definition['name'], 'icon' => $definition['icon'], 'sort_order' => $order, 'is_active' => true],
            );
        }
    }

    public function defaultId(): ?int
    {
        return BusinessCategory::query()->where('slug', self::DEFAULT_SLUG)->value('id');
    }

    /**
     * Para elegir en un formulario: las activas, y la actual aunque ya no lo este.
     *
     * @return Collection<int, array{value: int, label: string, icon: string, is_active: bool}>
     */
    public function options(?int $current = null): Collection
    {
        return BusinessCategory::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($current, fn ($inner) => $inner->orWhere('id', $current)))
            ->ordered()
            ->get()
            ->map(fn (BusinessCategory $category) => [
                'value' => $category->id,
                'label' => $category->name.($category->is_active ? '' : ' (inactiva)'),
                'icon' => $category->icon,
                'is_active' => $category->is_active,
            ])
            ->values();
    }

    /**
     * La de un archivo: por nombre o por su slug, sin importar tildes ni mayusculas.
     */
    public function findByText(string $text): ?BusinessCategory
    {
        $slug = Str::slug($text);

        return BusinessCategory::query()->active()->where(fn ($query) => $query->where('slug', $slug)->orWhere('name', trim($text)))->first();
    }
}
