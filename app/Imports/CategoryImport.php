<?php

namespace App\Imports;

use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CategoryImport extends MasterImport
{
    public function module(): string
    {
        return 'categories';
    }

    public function title(): string
    {
        return 'Importar categorias';
    }

    public function description(): string
    {
        return 'Si ya hay una categoria con ese nombre, se actualiza en vez de duplicarse.';
    }

    public function model(): string
    {
        return Category::class;
    }

    public function columns(): array
    {
        return [
            'nombre' => 'Como se llama la categoria',
            'padre' => 'Nombre de la categoria que la contiene, si depende de otra',
            'orden' => 'En que posicion aparece; menor sale primero',
            'activa' => 'si / no',
        ];
    }

    public function required(): array
    {
        return ['nombre'];
    }

    public function example(): array
    {
        return ['Lacteos', '', '10', 'si'];
    }

    public function notes(): array
    {
        return [
            'La categoria padre debe existir antes, o venir en una fila anterior del mismo archivo.',
            'El enlace para la tienda en linea se arma solo a partir del nombre.',
        ];
    }

    public function existing(array $row, int $companyId): ?Model
    {
        return Category::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name', $this->text($row, 'nombre'))
            ->first();
    }

    public function payload(array $row, int $companyId): ?array
    {
        $name = (string) $this->text($row, 'nombre');
        $parentName = $this->text($row, 'padre');

        $parent = $parentName
            ? Category::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('name', $parentName)->first()
            : null;

        if ($parentName && ! $parent) {
            throw new \RuntimeException("la categoria padre «{$parentName}» no existe");
        }

        return [
            'name' => $name,
            'slug' => $this->uniqueSlug($name, $companyId),
            'parent_id' => $parent?->id,
            'sort_order' => $this->integer($row, 'orden'),
            'is_active' => $this->boolean($row, 'activa'),
        ];
    }

    /** El enlace tiene que ser unico dentro de la empresa: si choca, se numera. */
    protected function uniqueSlug(string $name, int $companyId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Category::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('slug', $slug)->where('name', '!=', $name)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
