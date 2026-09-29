<?php

namespace App\Services\Landing;

use App\Landing\LandingBlockCatalogue;
use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\MembershipPlan;
use App\Models\Store;
use App\Models\Storefront;
use App\Services\Membership\MembershipFeatures;
use App\Services\Membership\MembershipPricing;
use App\Services\Storefront\StorefrontRenderer;
use App\Support\StorefrontContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Los datos de la landing que vienen de la base, resueltos en un solo lugar: el mismo para la
 * pagina publica y para la vista previa del editor.
 *
 * Solo fuentes tipadas (sedes vendibles, empresas, planes). La landing es publica: aqui no
 * entra SQL escrito por nadie.
 */
class LandingRenderer
{
    public function __construct(protected StorefrontRenderer $storefronts) {}

    /**
     * @param  list<array{uuid: string, type: string, is_visible: bool, data: array<string, mixed>}>  $blocks
     * @return list<array<string, mixed>>
     */
    public function resolve(array $blocks): array
    {
        $resolved = [];
        $cache = [];

        foreach ($blocks as $block) {
            if (! LandingBlockCatalogue::has($block['type'])) {
                continue;
            }

            $data = $this->withImageUrls($block['type'], $block['data'] ?? []);

            $resolved[] = [
                'uuid' => $block['uuid'],
                'type' => $block['type'],
                'is_visible' => (bool) ($block['is_visible'] ?? true),
                'data' => $data,
                'source' => match ($block['type']) {
                    'store_finder' => ['stores' => $cache['stores'] ??= $this->stores()->all()],
                    'companies_showcase' => ['companies' => $cache['companies'] ??= $this->companies()],
                    'plans' => $cache['plans'] ??= $this->plans(),
                    default => null,
                },
            ];
        }

        return $resolved;
    }

    /**
     * Las sedes que pueden vender hoy, con lo que muestra su tarjeta en el buscador.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function stores(): Collection
    {
        $now = CarbonImmutable::now('America/Bogota');

        $stores = StorefrontContext::sellable()->with('company')->orderBy('stores.name')->get();
        $storefronts = Storefront::query()->withoutGlobalScopes()->whereIn('store_id', $stores->pluck('id'))->get()->keyBy('store_id');

        return $stores->map(function (Store $store) use ($now, $storefronts) {
            $storefront = $storefronts->get($store->id);
            $theme = $this->storefronts->shell($store)['theme'] ?? [];
            $opens = substr((string) $store->opens_at, 0, 5);
            $closes = substr((string) $store->closes_at, 0, 5);
            $time = $now->format('H:i');

            return [
                'id' => $store->id,
                'name' => $store->name,
                // El nombre que la tienda eligio en su editor; si quedo el de la sede, el de la empresa.
                'company' => filled($theme['display_name'] ?? null) && $theme['display_name'] !== $store->name ? $theme['display_name'] : $store->company->name,
                'company_name' => $store->company->name,
                'company_id' => $store->company_id,
                'address' => $store->address,
                'city' => $store->city ?? $store->company->city,
                'latitude' => $store->latitude !== null ? (float) $store->latitude : null,
                'longitude' => $store->longitude !== null ? (float) $store->longitude : null,
                'opens_at' => $opens ?: null,
                'closes_at' => $closes ?: null,
                'is_open' => $opens !== '' && $closes !== '' && $time >= $opens && $time < $closes,
                'accent' => $theme['accent'] ?? '#0E6E4E',
                'accent_ink' => $theme['accent_ink'] ?? '#FFFFFF',
                'logo_url' => $theme['logo']['url'] ?? null,
                'url' => route('storefront.entry', [$store->company->slug, $storefront->slug]),
            ];
        })->values();
    }

    /**
     * Las sedes vendibles ordenadas por distancia a un punto (Haversine). Las que no tienen
     * coordenadas van al final, sin distancia. La ubicacion no se guarda en ninguna parte.
     *
     * @return list<array<string, mixed>>
     */
    public function nearby(float $latitude, float $longitude): array
    {
        return $this->stores()
            ->map(function (array $store) use ($latitude, $longitude) {
                $store['distance_km'] = $store['latitude'] !== null && $store['longitude'] !== null
                    ? round($this->haversine($latitude, $longitude, $store['latitude'], $store['longitude']), 2)
                    : null;

                return $store;
            })
            ->sortBy(fn (array $store) => $store['distance_km'] ?? PHP_FLOAT_MAX)
            ->values()
            ->all();
    }

    /**
     * Empresas activas con al menos una sede vendible, que no se ocultaron de la landing.
     *
     * @return list<array<string, mixed>>
     */
    public function companies(): array
    {
        $stores = $this->stores()->groupBy('company_id');

        return Company::query()
            ->whereIn('id', $stores->keys())
            ->where('show_on_landing', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $stores[$company->id]->first()['company'] ?? $company->name,
                'city' => $company->city,
                'stores_count' => $stores[$company->id]->count(),
                'accent' => $stores[$company->id]->first()['accent'] ?? '#0E6E4E',
                'accent_ink' => $stores[$company->id]->first()['accent_ink'] ?? '#FFFFFF',
                'logo_url' => $stores[$company->id]->first()['logo_url'] ?? null,
                'url' => $stores[$company->id]->first()['url'],
            ])
            ->values()
            ->all();
    }

    /**
     * Planes publicos y activos con el precio de cada ciclo activo. Los precios salen de
     * `MembershipPricing`: el navegador no calcula nada.
     *
     * @return array{plans: list<array<string, mixed>>, cycles: list<array<string, mixed>>}
     */
    public function plans(): array
    {
        $cycles = BillingCycle::query()->active()->ordered()->get();

        return [
            'cycles' => $cycles->map(fn (BillingCycle $cycle) => [
                'id' => $cycle->id,
                'code' => $cycle->code,
                'name' => $cycle->name,
                'months' => $cycle->months,
                'discount_percent' => $cycle->discount_percent,
            ])->values()->all(),
            'plans' => MembershipPlan::query()
                ->active()
                ->where('is_public', true)
                ->ordered()
                ->get()
                ->map(fn (MembershipPlan $plan) => [
                    'id' => $plan->id,
                    'slug' => $plan->slug,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'is_featured' => $plan->is_featured,
                    'trial_days' => $plan->trial_days,
                    'limits' => ['stores' => $plan->max_stores, 'users' => $plan->max_users, 'registers' => $plan->max_registers],
                    'features' => array_map(fn (string $key) => MembershipFeatures::CATALOGUE[$key]['label'], MembershipFeatures::sanitize($plan->features)),
                    'prices' => (object) collect(MembershipPricing::priceTable($plan, $cycles))->mapWithKeys(fn ($price, $id) => [(string) $id => $price])->all(),
                    'monthly' => (object) $cycles->mapWithKeys(fn (BillingCycle $cycle) => [(string) $cycle->id => MembershipPricing::monthlyEquivalent($plan, $cycle)])->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * A cada campo de imagen le suma su direccion publica (`logo` → `logo_url`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withImageUrls(string $type, array $data): array
    {
        foreach (LandingBlockCatalogue::for($type)->fields() as $key => $field) {
            if ($field['type'] === 'image') {
                $data[$key.'_url'] = is_string($data[$key] ?? null) && $data[$key] !== '' ? Storage::disk('public')->url($data[$key]) : null;
            }
        }

        return $data;
    }

    protected function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
