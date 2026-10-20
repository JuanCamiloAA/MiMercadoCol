<?php

namespace App\Services\Landing;

use App\Landing\Demo\DemoWorkspace;
use App\Landing\LandingBlockCatalogue;
use App\Models\BillingCycle;
use App\Models\BusinessCategory;
use App\Models\Company;
use App\Models\MembershipPlan;
use App\Models\Store;
use App\Models\Storefront;
use App\Services\Membership\MembershipPricing;
use App\Services\Membership\PlanCatalogue;
use App\Services\Storefront\StorefrontConfig;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontStructure;
use App\Support\Maps\MapConfig;
use App\Support\SectionAppearance;
use App\Support\StorefrontContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Los datos de la landing que vienen de la base, resueltos en un solo lugar: el mismo para la
 * pagina publica y para la vista previa del editor.
 *
 * Solo fuentes tipadas (sedes vendibles, empresas, planes). La landing es publica: aqui no
 * entra SQL escrito por nadie.
 */
class LandingRenderer
{
    public function __construct(protected StorefrontRenderer $storefronts, protected StorefrontConfig $config) {}

    /**
     * @param  list<array{uuid: string, type: string, is_visible: bool, data: array<string, mixed>}>  $blocks
     * @return list<array<string, mixed>>
     */
    public function resolve(array $blocks): array
    {
        $resolved = [];
        $cache = [];
        $withNextSlot = $this->wantsNextSlot($blocks);

        foreach ($blocks as $block) {
            if (! LandingBlockCatalogue::has($block['type'])) {
                continue;
            }

            // Una version publicada antes de que el bloque tuviera una clave llega sin ella: con
            // `normalize()` vale su defecto, y la correccion del bloque (el mapa de cada diseño)
            // es la misma en la pagina publica, en la vista previa y al guardar.
            $definition = LandingBlockCatalogue::for($block['type']);
            $data = $definition->withMediaUrls($definition->normalize($block['data'] ?? []));

            $resolved[] = [
                'uuid' => $block['uuid'],
                'type' => $block['type'],
                'is_visible' => (bool) ($block['is_visible'] ?? true),
                'data' => $data,
                // El diseño de la seccion ya resuelto: el defecto del tipo con lo cambiado encima
                // (y los fondos con su direccion). El encabezado y el pie no llevan.
                'appearance' => $definition->supportsAppearance()
                    ? self::withDark(array_merge(
                        SectionAppearance::defaults($definition->appearanceDefaults(), SectionAppearance::SURFACE_LANDING),
                        is_array($data['appearance'] ?? null) ? $data['appearance'] : [],
                    ))
                    : null,
                'source' => match ($block['type']) {
                    // Las tiendas y, para filtrarlas, las clasificaciones que tienen alguna.
                    'store_finder' => [
                        'stores' => $cache['stores'] ??= $this->stores($withNextSlot)->all(),
                        'categories' => $cache['store_categories'] ??= $this->categoriesOf(collect($cache['stores'])),
                        // Con que proveedor se pinta: lo decide la plataforma, no el bloque.
                        'map' => $cache['map'] ??= MapConfig::current(),
                    ],
                    'companies_showcase' => [
                        'companies' => $cache['companies'] ??= $this->companies(),
                        'categories' => $cache['company_categories'] ??= $this->categoriesOf(collect($cache['companies'])),
                    ],
                    'plans' => $cache['plans'] ??= $this->plans(),
                    // Datos de ejemplo, sin consultas: lo que se haga en la demo no vuelve al servidor.
                    'app_demo' => app(DemoWorkspace::class)->build($data),
                    default => null,
                },
            ];
        }

        return $resolved;
    }

    /**
     * Si algun buscador visible muestra la proxima franja. Contarla cuesta una consulta por franja
     * de cada sede: si nadie la muestra, no se paga.
     *
     * @param  list<array{type: string, is_visible?: bool, data?: array<string, mixed>}>  $blocks
     */
    /**
     * El diseño de la seccion con el de modo oscuro ya resuelto (`dark`): lo de claro con lo propio
     * de oscuro encima, direcciones incluidas. `null` si la seccion no cambia nada en oscuro.
     *
     * @param  array<string, mixed>  $appearance
     * @return array<string, mixed>
     */
    public static function withDark(array $appearance): array
    {
        $dark = SectionAppearance::resolveDark($appearance, $appearance);
        $appearance[SectionAppearance::DARK_KEY] = $dark;

        return $appearance;
    }

    public function wantsNextSlot(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'store_finder' && ($block['is_visible'] ?? true) && ($block['data']['show_next_slot'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las sedes que pueden vender hoy, con lo que muestra su tarjeta en el buscador.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function stores(bool $withNextSlot = false): Collection
    {
        $now = CarbonImmutable::now('America/Bogota');

        $stores = StorefrontContext::sellable()->with('company.businessCategory')->orderBy('stores.name')->get();
        // Con su version publicada: el domicilio sale de ahi, todas las sedes en una consulta.
        $storefronts = Storefront::query()->withoutGlobalScopes()->with('publishedVersion')->whereIn('store_id', $stores->pluck('id'))->get()->keyBy('store_id');

        return $stores->map(function (Store $store) use ($now, $storefronts, $withNextSlot) {
            $storefront = $storefronts->get($store->id);
            $theme = $this->storefronts->shell($store)['theme'] ?? [];
            $opens = substr((string) $store->opens_at, 0, 5);
            $closes = substr((string) $store->closes_at, 0, 5);
            $time = $now->format('H:i');
            $isOpen = $opens !== '' && $closes !== '' && $time >= $opens && $time < $closes;
            $located = $store->latitude !== null && $store->longitude !== null;

            return [
                'id' => $store->id,
                'name' => $store->name,
                // El nombre que la tienda eligio en su editor; si quedo el de la sede, el de la empresa.
                'company' => filled($theme['display_name'] ?? null) && $theme['display_name'] !== $store->name ? $theme['display_name'] : $store->company->name,
                'company_name' => $store->company->name,
                'company_id' => $store->company_id,
                // Alimentos, textiles, belleza…: la de la empresa.
                'category' => $store->company->businessCategory?->toLanding(),
                'address' => $store->address,
                'city' => $store->city ?? $store->company->city,
                'latitude' => $store->latitude !== null ? (float) $store->latitude : null,
                'longitude' => $store->longitude !== null ? (float) $store->longitude : null,
                'opens_at' => $opens ?: null,
                'closes_at' => $closes ?: null,
                'is_open' => $isOpen,
                // La frase, armada aqui para que la tarjeta, el mapa y la ventana digan lo mismo.
                'hours_label' => $this->hoursLabel($opens, $closes, $now, $isOpen),
                'delivery' => $this->config->deliverySummary($store, $storefront?->publishedVersion?->snapshot ?? [], $withNextSlot),
                'accent' => $theme['accent'] ?? '#0E6E4E',
                'accent_ink' => $theme['accent_ink'] ?? '#FFFFFF',
                'logo_url' => $theme['logo']['url'] ?? null,
                'url' => route('storefront.entry', [$store->company->slug, $storefront->slug]),
                // «Cómo llegar» abre la ruta en Google Maps; el enlace se arma aqui, no en el navegador.
                'directions_url' => $located ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($store->latitude.','.$store->longitude) : null,
            ];
        })->values();
    }

    /**
     * «cierra 9:00 p. m.», «abre 7:00 a. m.» o «abre mañana 7:00 a. m.», en hora de Bogota. Vacia
     * si la sede no tiene horario.
     */
    protected function hoursLabel(string $opens, string $closes, CarbonImmutable $now, bool $isOpen): string
    {
        if ($opens === '' || $closes === '') {
            return '';
        }

        if ($isOpen) {
            return 'cierra '.StorefrontStructure::clock($now->setTimeFromTimeString($closes));
        }

        $at = StorefrontStructure::clock($now->setTimeFromTimeString($opens));

        return $now->format('H:i') < $opens ? 'abre '.$at : 'abre mañana '.$at;
    }

    /**
     * Las sedes vendibles ordenadas por distancia a un punto (Haversine). Las que no tienen
     * coordenadas van al final, sin distancia. La ubicacion no se guarda en ninguna parte.
     *
     * @return list<array<string, mixed>>
     */
    public function nearby(float $latitude, float $longitude, bool $withNextSlot = false): array
    {
        return $this->stores($withNextSlot)
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
            ->with('businessCategory')
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $stores[$company->id]->first()['company'] ?? $company->name,
                'city' => $company->city,
                'category' => $company->businessCategory?->toLanding(),
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
     * Las clasificaciones que aparecen en una lista (de tiendas o de empresas), en el orden del
     * catalogo y con cuantas de cada una. Una desactivada que aun tenga empresas tambien sale:
     * desactivar solo la saca de las opciones al crear o contratar.
     *
     * @param  Collection<int, array<string, mixed>>  $items  cada uno con su `category`
     * @return list<array{id: int, name: string, slug: string, icon: string, count: int}>
     */
    public function categoriesOf(Collection $items): array
    {
        $counts = $items->map(fn (array $item) => $item['category']['id'] ?? null)->filter()->countBy();

        if ($counts->isEmpty()) {
            return [];
        }

        return BusinessCategory::query()
            ->whereIn('id', $counts->keys())
            ->ordered()
            ->get()
            ->map(fn (BusinessCategory $category) => [...$category->toLanding(), 'count' => (int) $counts[$category->id]])
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
        $plans = MembershipPlan::query()->active()->where('is_public', true)->ordered()->get();
        $catalogue = app(PlanCatalogue::class);

        return [
            // La tabla comparativa: los modulos que trae cada plan, nunca una lista fija.
            'comparison' => $catalogue->comparison($plans),
            'cycles' => $cycles->map(fn (BillingCycle $cycle) => [
                'id' => $cycle->id,
                'code' => $cycle->code,
                'name' => $cycle->name,
                'months' => $cycle->months,
                'discount_percent' => $cycle->discount_percent,
            ])->values()->all(),
            // Sin empresa: la landing y «Contratar» nunca muestran un precio especial.
            'plans' => $plans
                ->map(fn (MembershipPlan $plan) => [
                    'id' => $plan->id,
                    'slug' => $plan->slug,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'is_featured' => $plan->is_featured,
                    'trial_days' => $plan->trial_days,
                    'limits' => ['stores' => $plan->max_stores, 'users' => $plan->max_users, 'registers' => $plan->max_registers],
                    'highlights' => $catalogue->highlights($plan),
                    'prices' => (object) collect(MembershipPricing::priceTable($plan, $cycles))->mapWithKeys(fn ($price, $id) => [(string) $id => $price])->all(),
                    'monthly' => (object) $cycles->mapWithKeys(fn (BillingCycle $cycle) => [(string) $cycle->id => MembershipPricing::monthlyEquivalent($plan, $cycle)])->all(),
                ])
                ->values()
                ->all(),
        ];
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
