<?php

namespace App\Services\Storefront;

use App\Models\Order;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\StorefrontTemplate;
use Carbon\CarbonImmutable;

/**
 * Lo que la tienda publicada dice sobre domicilio, cobertura y franjas. Los medios de pago ya no
 * viven aqui: son operacion, no se publican con la tienda (`PaymentMethodAvailability`).
 *
 * El checkout no puede leer el borrador: si el administrador esta a mitad de un cambio, el
 * cliente pagaria con reglas que todavia no se publicaron. Por eso todo sale del retrato de la
 * version publicada, con los valores por defecto como respaldo cuando una sede aun no publica.
 *
 * Si la sede sigue la plantilla, lo que es diseño (tarjeta, paginas, busqueda) sale de la
 * plantilla publicada; el domicilio y los pagos siguen siendo de la sede.
 */
class StorefrontConfig
{
    /** @var array<int, array<string, mixed>> */
    protected array $snapshots = [];

    public function __construct(protected StorefrontManager $manager) {}

    public function storefront(Store $store): ?Storefront
    {
        return Storefront::query()->withoutGlobalScopes()->with(['publishedVersion', 'template'])->where('store_id', $store->id)->first();
    }

    /**
     * El retrato publicado que manda en la sede, con el diseño de la plantilla si la sigue.
     *
     * @return array<string, mixed>
     */
    public function published(Store $store): array
    {
        $storefront = $this->storefront($store);

        // Se recuerda por version: si alguien publica (la sede o su plantilla), la llave cambia.
        $key = $store->id.':'.$storefront?->published_version_id.':'.($storefront?->follows_template ? $storefront->template?->published_at?->timestamp : '-');

        if (isset($this->snapshots[$key])) {
            return $this->snapshots[$key];
        }

        $snapshot = $storefront?->publishedVersion?->snapshot ?? [];

        if ($storefront?->follows_template && $storefront->template?->published) {
            $snapshot = StorefrontTemplate::compose($snapshot, $storefront->template->published);
        }

        return $this->snapshots[$key] = $snapshot;
    }

    /**
     * Ajustes publicados de la sede, completados con los valores por defecto.
     *
     * @return array<string, array<string, mixed>>
     */
    public function settings(Store $store): array
    {
        return $this->settingsFrom($this->published($store));
    }

    /**
     * Lo mismo que `settings()`, desde un retrato ya cargado: el buscador de la landing trae los
     * de todas las sedes en una sola consulta. Domicilio, zonas y franjas son siempre de la sede
     * (la plantilla no los toca), asi que basta con el retrato de su version publicada.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    public function settingsFrom(array $snapshot): array
    {
        $published = $snapshot['settings'] ?? [];
        $settings = [];

        foreach ($this->manager->defaultSettings() as $key => $defaults) {
            $settings[$key] = array_merge($defaults, is_array($published[$key] ?? null) ? $published[$key] : []);
        }

        return $settings;
    }

    /**
     * Zonas de cobertura publicadas y activas.
     *
     * Se devuelven tal como quedaron en el retrato —no de la tabla— para que una zona que se
     * agrego al borrador y todavia no se publica no aparezca en el checkout.
     *
     * @return list<array{index: int, name: string, fee: int, eta_minutes: int}>
     */
    public function zones(Store $store): array
    {
        return $this->zonesFrom($this->published($store));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{index: int, name: string, fee: int, eta_minutes: int}>
     */
    public function zonesFrom(array $snapshot): array
    {
        $rows = $snapshot['zones'] ?? [];

        return collect($rows)
            ->filter(fn (array $zone) => (bool) ($zone['is_active'] ?? true))
            ->sortBy('sort_order')
            ->values()
            ->map(fn (array $zone, int $index) => [
                'index' => $index,
                'name' => (string) $zone['name'],
                'fee' => (int) ($zone['fee'] ?? 0),
                'eta_minutes' => (int) ($zone['eta_minutes'] ?? 0),
            ])
            ->all();
    }

    /**
     * Franjas disponibles en los proximos dias, con su cupo restante.
     *
     * @return list<array{key: string, label: string, day: string, starts_at: string, ends_at: string, remaining: int|null, full: bool}>
     */
    public function slots(Store $store, int $days = 3): array
    {
        return $this->slotsFrom($store, $this->published($store), $days);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{key: string, label: string, day: string, starts_at: string, ends_at: string, remaining: int|null, full: bool}>
     */
    public function slotsFrom(Store $store, array $snapshot, int $days = 3): array
    {
        $settings = $this->settingsFrom($snapshot);

        if (! ($settings['slots']['enabled'] ?? false)) {
            return [];
        }

        $rows = collect($snapshot['slots'] ?? [])
            ->filter(fn (array $slot) => (bool) ($slot['is_active'] ?? true));

        if ($rows->isEmpty()) {
            return [];
        }

        $now = CarbonImmutable::now();
        $slots = [];

        for ($offset = 0; $offset <= $days; $offset++) {
            $day = $now->addDays($offset)->startOfDay();

            foreach ($rows->where('weekday', (int) $day->dayOfWeek) as $slot) {
                $starts = $day->setTimeFromTimeString((string) $slot['starts_at']);
                $ends = $day->setTimeFromTimeString((string) $slot['ends_at']);

                // Una franja que ya empezo no se ofrece: nadie alcanza a alistar ese pedido.
                if ($starts->lte($now)) {
                    continue;
                }

                $capacity = (int) ($slot['capacity'] ?? 0);
                $taken = $this->taken($store, $starts);

                $slots[] = [
                    'key' => $starts->format('Y-m-d H:i').'|'.$ends->format('H:i'),
                    'label' => $this->dayLabel($starts, $now).' '.$starts->format('g:i a').' a '.$ends->format('g:i a'),
                    'day' => $starts->toDateString(),
                    'starts_at' => $starts->toDateTimeString(),
                    'ends_at' => $ends->toDateTimeString(),
                    'remaining' => $capacity > 0 ? max(0, $capacity - $taken) : null,
                    'full' => $capacity > 0 && $taken >= $capacity,
                ];
            }
        }

        return $slots;
    }

    /**
     * Busca una franja por su clave y confirma que todavia tenga cupo.
     *
     * @return array<string, mixed>|null
     */
    public function findSlot(Store $store, string $key, int $days = 3): ?array
    {
        foreach ($this->slots($store, $days) as $slot) {
            if ($slot['key'] === $key) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Lo que cuesta el domicilio: la tarifa de la zona, o la general, y gratis desde cierto monto.
     */
    public function deliveryFee(Store $store, int $subtotal, ?int $zoneIndex = null): int
    {
        $delivery = $this->settings($store)['delivery'];

        if (! ($delivery['enabled'] ?? true)) {
            return 0;
        }

        $freeFrom = (int) ($delivery['free_from'] ?? 0);

        if ($freeFrom > 0 && $subtotal >= $freeFrom) {
            return 0;
        }

        $zones = $this->zones($store);

        if ($zoneIndex !== null && isset($zones[$zoneIndex])) {
            return $zones[$zoneIndex]['fee'];
        }

        return (int) ($delivery['fee'] ?? 0);
    }

    /**
     * El avance hacia el domicilio gratis. `null` cuando no hay: sin domicilio, o con
     * `free_from = 0`, que significa «nunca es gratis» y no «gratis desde $ 0».
     *
     * @return array{from: int, missing: int, reached: bool}|null
     */
    public function freeDelivery(Store $store, int $subtotal): ?array
    {
        $delivery = $this->settings($store)['delivery'];
        $from = (int) ($delivery['free_from'] ?? 0);

        if (! ($delivery['enabled'] ?? true) || $from <= 0) {
            return null;
        }

        return ['from' => $from, 'missing' => max(0, $from - $subtotal), 'reached' => $subtotal >= $from];
    }

    /** El pedido minimo de la sede; 0 es sin minimo. */
    public function minOrderTotal(Store $store): int
    {
        return max(0, (int) ($this->settings($store)['delivery']['min_order_total'] ?? 0));
    }

    /**
     * El domicilio en corto, para el buscador de la landing. Es un «desde»: el valor exacto lo da
     * el checkout con la zona de cada quien.
     *
     * `fee_from` es lo menos que alguien paga: con zonas, el checkout obliga a elegir una y cobra
     * la suya, asi que la tarifa general no cuenta; sin zonas, la general. `free_from` en `null`
     * cuando no hay, igual que `freeDelivery()`: 0 es «nunca gratis», no «gratis desde $ 0».
     * La proxima franja cuenta cupos (una consulta por franja): solo se pide si alguien la muestra.
     *
     * @param  array<string, mixed>  $snapshot  El retrato publicado de la sede.
     * @return array{enabled: bool, fee_from: int|null, free_from: int|null, min_order_total: int, next_slot: string|null}
     */
    public function deliverySummary(Store $store, array $snapshot, bool $withNextSlot = false): array
    {
        $delivery = $this->settingsFrom($snapshot)['delivery'];
        $enabled = (bool) ($delivery['enabled'] ?? true);
        $zoneFees = array_column($this->zonesFrom($snapshot), 'fee');
        $freeFrom = (int) ($delivery['free_from'] ?? 0);

        return [
            'enabled' => $enabled,
            'fee_from' => $enabled ? ($zoneFees !== [] ? min($zoneFees) : (int) ($delivery['fee'] ?? 0)) : null,
            'free_from' => $enabled && $freeFrom > 0 ? $freeFrom : null,
            'min_order_total' => max(0, (int) ($delivery['min_order_total'] ?? 0)),
            'next_slot' => $withNextSlot ? $this->nextSlotLabel($this->slotsFrom($store, $snapshot)) : null,
        ];
    }

    /**
     * La primera franja con cupo, como la escribe la tienda: «hoy entre 4:00 y 6:00 p. m.».
     *
     * @param  list<array{starts_at: string, ends_at: string, full: bool}>  $slots
     */
    public function nextSlotLabel(array $slots): ?string
    {
        foreach ($slots as $slot) {
            if ($slot['full']) {
                continue;
            }

            $starts = CarbonImmutable::parse($slot['starts_at']);
            $ends = CarbonImmutable::parse($slot['ends_at']);
            $now = CarbonImmutable::now();
            $day = $starts->isSameDay($now) ? 'hoy' : ($starts->isSameDay($now->addDay()) ? 'mañana' : $starts->locale('es')->isoFormat('dddd'));

            return $day.' entre '.StorefrontStructure::clock($starts, false).' y '.StorefrontStructure::clock($ends);
        }

        return null;
    }

    /**
     * Una pieza de la estructura publicada (`card`, `category_page`, `product_page`, `search`,
     * `cart_bar`...), completa con sus valores por defecto.
     *
     * @return array<string, mixed>
     */
    public function structure(Store $store, string $key): array
    {
        return (array) ($this->settings($store)[$key] ?? []);
    }

    /** Cuantos pedidos vivos ya tomaron esa franja. */
    protected function taken(Store $store, CarbonImmutable $starts): int
    {
        return Order::query()
            ->withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->where('delivery_slot_start', $starts->toDateTimeString())
            ->whereNotIn('status', [Order::STATUS_CANCELLED])
            ->count();
    }

    protected function dayLabel(CarbonImmutable $day, CarbonImmutable $now): string
    {
        if ($day->isSameDay($now)) {
            return 'Hoy';
        }

        if ($day->isSameDay($now->addDay())) {
            return 'Mañana';
        }

        return ucfirst($day->locale('es')->isoFormat('dddd D [de] MMMM'));
    }
}
