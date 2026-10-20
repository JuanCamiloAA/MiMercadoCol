<?php

namespace App\Services\Payments;

use App\Models\PaymentMethod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Los medios de pago con que nace toda empresa y como se encuentra el medio de un `kind`.
 *
 * Una empresa sin «Efectivo» revienta el primer cobro: por eso nacen todos con la empresa (gancho
 * `created` del modelo `Company`, que cubre Empresas, la contratacion, la carga masiva y las
 * semillas) y, por si acaso, `ensureDefaults()` los repone cuando se busca uno que falta.
 *
 * Los de la tienda en linea salen como sus defectos de siempre: contraentrega en efectivo, con
 * datafono y a credito; la transferencia, solo en la caja.
 */
class PaymentMethodCatalog
{
    public const DEFAULTS = [
        'cash' => [
            'name' => 'Efectivo', 'kind' => PaymentMethod::KIND_CASH, 'is_system' => true, 'sort_order' => 10,
            'availability' => ['channels' => [PaymentMethod::CHANNEL_POS]],
        ],
        'card' => [
            'name' => 'Tarjeta (datáfono)', 'kind' => PaymentMethod::KIND_CARD, 'is_system' => false, 'sort_order' => 20,
            'availability' => ['channels' => [PaymentMethod::CHANNEL_POS]],
            'reference_label' => 'Número de aprobación',
        ],
        'transfer' => [
            'name' => 'Transferencia', 'kind' => PaymentMethod::KIND_TRANSFER, 'is_system' => false, 'sort_order' => 30,
            'availability' => ['channels' => [PaymentMethod::CHANNEL_POS]],
            'reference_label' => 'Referencia de la transferencia',
        ],
        'credit' => [
            'name' => 'Crédito', 'kind' => PaymentMethod::KIND_CREDIT, 'is_system' => false, 'sort_order' => 40,
            'availability' => ['channels' => [PaymentMethod::CHANNEL_POS, PaymentMethod::CHANNEL_ONLINE]],
        ],
        'cash_on_delivery' => [
            'name' => 'Efectivo al recibir', 'kind' => PaymentMethod::KIND_CASH, 'is_system' => false, 'sort_order' => 50,
            'availability' => ['channels' => [PaymentMethod::CHANNEL_ONLINE]],
        ],
        'card_on_delivery' => [
            'name' => 'Datáfono al recibir', 'kind' => PaymentMethod::KIND_CARD, 'is_system' => false, 'sort_order' => 60,
            'availability' => ['channels' => [PaymentMethod::CHANNEL_ONLINE]],
        ],
        'store_credit' => [
            'name' => 'Saldo a favor', 'kind' => PaymentMethod::KIND_STORE_CREDIT, 'is_system' => true, 'sort_order' => 80,
            'availability' => ['channels' => [PaymentMethod::CHANNEL_POS, PaymentMethod::CHANNEL_ONLINE]],
        ],
        'exchange' => [
            'name' => 'Cambio por devolución', 'kind' => PaymentMethod::KIND_EXCHANGE, 'is_system' => true, 'sort_order' => 90,
            'availability' => null,
        ],
    ];

    /** Cual de los de fabrica se usa cuando solo se dice el `kind` y el canal. */
    protected const FALLBACK_KEYS = [
        PaymentMethod::CHANNEL_POS => [
            PaymentMethod::KIND_CASH => 'cash',
            PaymentMethod::KIND_CARD => 'card',
            PaymentMethod::KIND_TRANSFER => 'transfer',
            PaymentMethod::KIND_CREDIT => 'credit',
            PaymentMethod::KIND_STORE_CREDIT => 'store_credit',
            PaymentMethod::KIND_EXCHANGE => 'exchange',
        ],
        PaymentMethod::CHANNEL_ONLINE => [
            PaymentMethod::KIND_CASH => 'cash_on_delivery',
            PaymentMethod::KIND_CARD => 'card_on_delivery',
            PaymentMethod::KIND_TRANSFER => 'transfer',
            PaymentMethod::KIND_CREDIT => 'credit',
            PaymentMethod::KIND_STORE_CREDIT => 'store_credit',
            PaymentMethod::KIND_EXCHANGE => 'exchange',
        ],
    ];

    /** @var array<int, Collection<int, PaymentMethod>> */
    protected array $byCompany = [];

    /**
     * Crea los de fabrica que le falten a la empresa. Idempotente por `system_key`.
     *
     * @param  array<string, array<string, mixed>>  $overrides  `system_key` => atributos distintos
     */
    public function ensureDefaults(int $companyId, array $overrides = []): void
    {
        $existing = PaymentMethod::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('system_key')
            ->pluck('system_key')
            ->all();

        foreach (self::DEFAULTS as $key => $definition) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            PaymentMethod::query()->withoutGlobalScopes()->create(array_merge([
                'company_id' => $companyId,
                'system_key' => $key,
                'icon' => PaymentMethod::DEFAULT_ICONS[$definition['kind']] ?? null,
                'is_active' => true,
            ], $definition, $overrides[$key] ?? []));
        }

        unset($this->byCompany[$companyId]);
    }

    /**
     * Los medios de la empresa, en orden. Se recuerdan por peticion del servicio.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function forCompany(int $companyId, bool $fresh = false): Collection
    {
        if ($fresh || ! isset($this->byCompany[$companyId])) {
            $this->byCompany[$companyId] = PaymentMethod::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->with('gatewayAccount')
                ->ordered()
                ->get();
        }

        return $this->byCompany[$companyId];
    }

    public function forget(?int $companyId = null): void
    {
        if ($companyId === null) {
            $this->byCompany = [];

            return;
        }

        unset($this->byCompany[$companyId]);
    }

    public function find(int $companyId, int $id): ?PaymentMethod
    {
        return $this->forCompany($companyId)->firstWhere('id', $id)
            ?? PaymentMethod::query()->withoutGlobalScopes()->where('company_id', $companyId)->find($id);
    }

    /**
     * El medio que se usa cuando quien cobra solo dice el `kind` (una devolucion, un pedido viejo,
     * un llamado interno): primero el de fabrica de ese canal, despues cualquiera activo de ese
     * `kind` que valga en el canal, y por ultimo el de fabrica aunque este apagado.
     */
    public function defaultFor(int $companyId, string $kind, string $channel = PaymentMethod::CHANNEL_POS): PaymentMethod
    {
        $key = self::FALLBACK_KEYS[$channel][$kind] ?? null;
        $methods = $this->forCompany($companyId);

        if ($key !== null && ! $methods->contains('system_key', $key)) {
            $this->ensureDefaults($companyId);
            $methods = $this->forCompany($companyId, fresh: true);
        }

        $method = ($key !== null ? $methods->first(fn (PaymentMethod $item) => $item->system_key === $key && $item->is_active) : null)
            ?? $methods->first(fn (PaymentMethod $item) => $item->kind === $kind && $item->is_active
                && in_array($channel, (array) $item->rule('channels', PaymentMethod::KIND_CHANNELS[$kind] ?? []), true))
            ?? $methods->first(fn (PaymentMethod $item) => $item->kind === $kind && $item->is_active)
            ?? ($key !== null ? $methods->firstWhere('system_key', $key) : null)
            ?? $methods->firstWhere('kind', $kind);

        if (! $method) {
            throw ValidationException::withMessages(['payments' => ['Medio de pago no reconocido.']]);
        }

        return $method;
    }
}
