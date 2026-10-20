<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Services\Storefront\StorefrontConfig;

/**
 * Los cuatro interruptores de pago que tenia cada sede en su tienda publicada
 * (`storefront_settings.payments`), pasados a reglas de los medios de pago:
 *
 *     cash_on_delivery  → «Efectivo al recibir»
 *     card_on_delivery  → «Datáfono al recibir»
 *     transfer          → «Transferencia» (canal en linea)
 *     customer_credit   → «Crédito» (canal en linea)
 *
 * Lo encendido queda con el canal en linea en esa sede; lo apagado, sin el. Cada sede ofrece en
 * linea exactamente lo mismo que antes. Lo usa la migracion; desde entonces esos interruptores
 * quedan en desuso: los medios de pago son operacion y no se publican con la tienda.
 */
class LegacyStorefrontPayments
{
    /** Interruptor viejo => `system_key` del medio que lo reemplaza. */
    public const MAP = [
        'cash_on_delivery' => 'cash_on_delivery',
        'card_on_delivery' => 'card_on_delivery',
        'transfer' => 'transfer',
        'customer_credit' => 'credit',
    ];

    /** Interruptor viejo => la clave del pedido que ofrecia. */
    public const ORDER_KEYS = [
        'cash_on_delivery' => Order::PAYMENT_CASH_ON_DELIVERY,
        'card_on_delivery' => Order::PAYMENT_CARD,
        'transfer' => Order::PAYMENT_TRANSFER,
        'customer_credit' => Order::PAYMENT_CREDIT,
    ];

    public function __construct(
        protected StorefrontConfig $config,
        protected PaymentMethodCatalog $catalog,
    ) {}

    /**
     * Lo que una sede ofrecia en linea antes, en las claves de `Order`.
     *
     * @return list<string>
     */
    public function legacyOffer(Store $store): array
    {
        $payments = $this->config->settings($store)['payments'] ?? [];

        return array_values(array_map(
            fn (string $setting) => self::ORDER_KEYS[$setting],
            array_filter(array_keys(self::ORDER_KEYS), fn (string $setting) => (bool) ($payments[$setting] ?? false)),
        ));
    }

    /** Pasa los interruptores de todas las sedes de una empresa a sus medios de pago. */
    public function migrateCompany(int $companyId): void
    {
        $this->catalog->ensureDefaults($companyId);

        $stores = Store::query()->withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get();

        if ($stores->isEmpty()) {
            return;
        }

        $on = array_fill_keys(array_keys(self::MAP), []);

        foreach ($stores as $store) {
            $payments = $this->config->settings($store)['payments'] ?? [];

            foreach (array_keys(self::MAP) as $setting) {
                if ($payments[$setting] ?? false) {
                    $on[$setting][] = (int) $store->id;
                }
            }
        }

        $all = $stores->pluck('id')->map(fn ($id) => (int) $id)->all();
        $methods = PaymentMethod::query()->withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('system_key')->get()->keyBy('system_key');

        foreach (self::MAP as $setting => $key) {
            $method = $methods->get($key);

            if (! $method) {
                continue;
            }

            $stores = $on[$setting];
            $everywhere = count($stores) === count($all);
            $availability = (array) ($method->availability ?? []);
            $channels = array_values(array_diff((array) ($availability['channels'] ?? []), [PaymentMethod::CHANNEL_ONLINE]));
            $channelStores = (array) ($availability['channel_stores'] ?? []);
            unset($channelStores[PaymentMethod::CHANNEL_ONLINE]);

            if ($stores !== []) {
                $channels[] = PaymentMethod::CHANNEL_ONLINE;

                if (! $everywhere) {
                    $channelStores[PaymentMethod::CHANNEL_ONLINE] = $stores;
                }
            }

            $availability['channels'] = array_values(array_unique($channels));

            if ($channelStores === []) {
                unset($availability['channel_stores']);
            } else {
                $availability['channel_stores'] = $channelStores;
            }

            // Los «al recibir» solo existen en linea: si ninguna sede los tenia, quedan apagados.
            $active = $availability['channels'] !== [] ? $method->is_active : false;

            $method->forceFill(['availability' => $availability, 'is_active' => $active])->save();
        }

        $this->catalog->forget($companyId);
    }
}
