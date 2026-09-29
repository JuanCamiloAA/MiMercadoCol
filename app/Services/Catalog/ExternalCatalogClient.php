<?php

namespace App\Services\Catalog;

/**
 * Lo que este modulo necesita de una fuente de catalogo externa.
 *
 * Esta detras de una interfaz para que las pruebas puedan sustituirla y para que cambiar de
 * fuente manana no obligue a tocar el importador.
 */
interface ExternalCatalogClient
{
    /**
     * Un articulo por su codigo de barras, o null si la fuente no lo tiene.
     *
     * @return array<string, mixed>|null
     *
     * @throws CatalogSourceException si la fuente no responde
     */
    public function findByEan(string $ean): ?array;

    /**
     * Una pagina de resultados.
     *
     * @return array{count: int, page: int, page_size: int, products: list<array<string, mixed>>}
     *
     * @throws CatalogSourceException si la fuente no responde
     */
    public function search(SearchCriteria $criteria, int $page = 1, int $perPage = 100): array;
}
