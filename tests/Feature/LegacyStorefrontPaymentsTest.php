<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Services\Payments\LegacyStorefrontPayments;
use App\Services\Payments\PaymentContext;
use App\Services\Payments\PaymentMethodAvailability;
use App\Services\Storefront\StorefrontManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Los interruptores de pago que tenia cada tienda publicada pasan a reglas de los medios de pago
 * sin cambiar lo que cada sede ofrece en linea, y sin tocar la caja.
 */
class LegacyStorefrontPaymentsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    protected function publishWithPayments(Store $store, array $payments): void
    {
        $storefront = app(StorefrontManager::class)->for($store);
        $storefront->settings()->updateOrCreate(['key' => 'payments'], ['value' => $payments]);
        $this->publishStorefront($store);
    }

    /** @return list<string> */
    protected function offeredNow(Store $store): array
    {
        $keys = app(PaymentMethodAvailability::class)->configuredOnline($this->company->id, $store->id)
            ->reject(fn (PaymentMethod $method) => $method->kind === PaymentMethod::KIND_STORE_CREDIT)
            ->map(fn (PaymentMethod $method) => Order::paymentKeyFor($method->kind))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $keys;
    }

    public function test_cada_sede_ofrece_en_linea_lo_mismo_que_antes(): void
    {
        $north = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Norte', 'code' => 'NOR', 'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'is_active' => true,
        ]);
        $quiet = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Sin tienda', 'code' => 'SIN', 'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'is_active' => true,
        ]);

        $this->publishWithPayments($this->store, ['cash_on_delivery' => true, 'card_on_delivery' => false, 'transfer' => true, 'customer_credit' => false]);
        $this->publishWithPayments($north, ['cash_on_delivery' => true, 'card_on_delivery' => true, 'transfer' => false, 'customer_credit' => false]);

        $legacy = app(LegacyStorefrontPayments::class);
        $before = [];

        foreach ([$this->store, $north, $quiet] as $store) {
            $before[$store->id] = collect($legacy->legacyOffer($store))->sort()->values()->all();
        }

        $legacy->migrateCompany($this->company->id);

        foreach ([$this->store, $north, $quiet] as $store) {
            $this->assertSame($before[$store->id], $this->offeredNow($store), "La sede {$store->name} cambió lo que ofrece en línea.");
        }

        // La caja sigue igual en todas las sedes: la transferencia, el datafono y el credito.
        foreach ([$this->store, $north] as $store) {
            $pos = app(PaymentMethodAvailability::class)->available($this->company->id, PaymentContext::pos($store->id, null))->pluck('system_key')->filter()->all();

            foreach (['cash', 'card', 'transfer', 'credit', 'store_credit'] as $key) {
                $this->assertContains($key, $pos, "La caja de {$store->name} perdió «{$key}».");
            }
        }
    }

    public function test_correr_dos_veces_da_lo_mismo(): void
    {
        $this->publishWithPayments($this->store, ['cash_on_delivery' => false, 'card_on_delivery' => true, 'transfer' => true, 'customer_credit' => true]);
        $legacy = app(LegacyStorefrontPayments::class);

        $legacy->migrateCompany($this->company->id);
        $first = $this->offeredNow($this->store);
        $legacy->migrateCompany($this->company->id);

        $this->assertSame($first, $this->offeredNow($this->store));
        $this->assertSame(collect($legacy->legacyOffer($this->store))->sort()->values()->all(), $first);
    }
}
