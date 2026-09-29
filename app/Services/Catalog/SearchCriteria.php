<?php

namespace App\Services\Catalog;

/**
 * Los filtros con los que se le pide catalogo a la fuente externa.
 */
final class SearchCriteria
{
    public function __construct(
        public readonly string $country = 'Colombia',
        public readonly ?string $brand = null,
        public readonly ?string $category = null,
        public readonly ?string $search = null,
        public readonly bool $onlyWithImage = false,
        public readonly int $limit = 500,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            country: (string) ($data['country'] ?? 'Colombia'),
            brand: self::clean($data['brand'] ?? null),
            category: self::clean($data['category'] ?? null),
            search: self::clean($data['search'] ?? null),
            onlyWithImage: (bool) ($data['only_with_image'] ?? false),
            limit: (int) ($data['limit'] ?? 500),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'brand' => $this->brand,
            'category' => $this->category,
            'search' => $this->search,
            'only_with_image' => $this->onlyWithImage,
            'limit' => $this->limit,
        ];
    }

    /** Como se describe en la pantalla, para que el historial se entienda sin traducir. */
    public function summary(): string
    {
        $parts = ['País: '.$this->country];

        if ($this->brand !== null) {
            $parts[] = 'Marca: '.$this->brand;
        }

        if ($this->category !== null) {
            $parts[] = 'Categoría: '.$this->category;
        }

        if ($this->search !== null) {
            $parts[] = 'Búsqueda: '.$this->search;
        }

        if ($this->onlyWithImage) {
            $parts[] = 'Solo con imagen';
        }

        return implode(' · ', $parts);
    }

    /**
     * La marca, en la forma de etiqueta que espera la fuente.
     *
     * `brands_tags` compara contra la etiqueta, no contra el texto: «Alpina» devuelve 22
     * articulos y `alpina` devuelve 112. Sin normalizar, el filtro funciona a medias y nadie
     * se entera de que se esta perdiendo cuatro quintas partes de la marca.
     */
    public function brandTag(): ?string
    {
        if ($this->brand === null) {
            return null;
        }

        $tag = mb_strtolower(trim($this->brand));
        // Los acentos no existen en las etiquetas de la fuente.
        $tag = strtr($tag, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        $tag = preg_replace('/[^a-z0-9]+/', '-', $tag) ?? $tag;

        $tag = trim($tag, '-');

        return $tag === '' ? null : $tag;
    }

    protected static function clean(mixed $value): ?string
    {
        $clean = trim((string) ($value ?? ''));

        return $clean === '' ? null : $clean;
    }
}
