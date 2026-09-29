<?php

namespace App\Services\Catalog;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Habla con Open Food Facts. No toca base de datos.
 *
 * Tres cosas de esta API que hay que tener presentes y que este cliente resuelve de una vez:
 * un codigo inexistente responde HTTP 200 con `status: 0`, el `page_size` maximo real es 100
 * aunque se pida mas, y el uso justo pide no pasar de una peticion por segundo.
 */
class OpenFoodFactsClient implements ExternalCatalogClient
{
    /** Lo unico que este modulo necesita de cada articulo. Pedir menos es ser buen vecino. */
    public const FIELDS = 'code,product_name,generic_name,brands,quantity,categories_tags,countries_tags,image_front_small_url';

    /**
     * Cuando salio la ultima peticion de verdad, en microsegundos.
     *
     * El ritmo lo lleva el cliente y no quien lo llama: asi un acierto de cache no paga la
     * espera y ningun camino nuevo puede olvidarse de respetar el uso justo.
     */
    protected ?float $lastRequestAt = null;

    public function findByEan(string $ean): ?array
    {
        // El acierto se guarda un dia y el «no existe» una hora: repetir la misma consulta
        // fallida contra una API gratuita es justo lo que no hay que hacer.
        $cached = Cache::get($this->cacheKey($ean));

        if ($cached !== null) {
            return $cached === 'missing' ? null : $cached;
        }

        $response = $this->get($this->url("/api/v2/product/{$ean}.json"), ['fields' => self::FIELDS]);
        $body = $this->decode($response);

        // Nunca fiarse solo del codigo HTTP: la API contesta 200 con status 0.
        if ((int) ($body['status'] ?? 0) !== 1 || ! is_array($body['product'] ?? null)) {
            Cache::put($this->cacheKey($ean), 'missing', now()->addMinutes($this->config('cache_miss_minutes')));

            return null;
        }

        $product = $body['product'];
        Cache::put($this->cacheKey($ean), $product, now()->addMinutes($this->config('cache_hit_minutes')));

        return $product;
    }

    public function search(SearchCriteria $criteria, int $page = 1, int $perPage = 100): array
    {
        // Pedir mas de cien no falla: devuelve cien y deja creyendo que trajo mas.
        $perPage = min($perPage, (int) $this->config('page_size'));

        $query = array_filter([
            'countries_tags_en' => $criteria->country,
            // En forma de etiqueta: la fuente compara contra el tag, no contra el texto.
            'brands_tags' => $criteria->brandTag(),
            'categories_tags_en' => $criteria->category,
            'search_terms' => $criteria->search,
            'fields' => self::FIELDS,
            'page' => $page,
            'page_size' => $perPage,
            'sort_by' => 'unique_scans_n',
        ], fn ($value) => $value !== null && $value !== '');

        $body = $this->decode($this->get($this->url('/api/v2/search'), $query));

        if (! is_array($body['products'] ?? null)) {
            throw CatalogSourceException::malformed();
        }

        $products = array_values(array_filter(
            $body['products'],
            fn ($product) => is_array($product) && (! $criteria->onlyWithImage || ! empty($product['image_front_small_url']))
        ));

        return [
            'count' => (int) ($body['count'] ?? count($products)),
            'page' => (int) ($body['page'] ?? $page),
            'page_size' => $perPage,
            'products' => $products,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function get(string $url, array $query): Response
    {
        $this->throttle();

        try {
            $response = Http::withHeaders(['User-Agent' => (string) config('catalog.user_agent')])
                ->timeout((int) $this->config('timeout'))
                ->retry((int) $this->config('retries'), (int) $this->config('retry_delay_ms'), throw: false)
                ->get($url, $query);
        } catch (ConnectionException $exception) {
            throw CatalogSourceException::unreachable($exception->getMessage());
        }

        if (! $response->successful()) {
            throw CatalogSourceException::badResponse($response->status());
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw CatalogSourceException::malformed();
        }

        return $body;
    }

    /** Espera lo que falte para no pasar de una peticion por segundo. */
    protected function throttle(): void
    {
        $gap = (int) $this->config('delay_between_pages_ms') * 1000;

        if ($gap <= 0) {
            return;
        }

        if ($this->lastRequestAt !== null) {
            $waited = (microtime(true) - $this->lastRequestAt) * 1_000_000;

            if ($waited < $gap) {
                usleep((int) ($gap - $waited));
            }
        }

        $this->lastRequestAt = microtime(true);
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('catalog.openfoodfacts.base_url'), '/').$path;
    }

    protected function cacheKey(string $ean): string
    {
        return "catalog:off:ean:{$ean}";
    }

    protected function config(string $key): mixed
    {
        return config("catalog.openfoodfacts.{$key}");
    }
}
