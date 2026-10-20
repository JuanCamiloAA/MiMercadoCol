<?php

namespace App\Services\Payments;

use App\Models\PaymentMethod;
use App\Models\PaymentTerminal;
use App\Payments\Gateways\GatewayRegistry;
use App\Services\Membership\EntitlementService;
use App\Services\StoreCreditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * La UNICA respuesta a «¿que medios de pago valen aqui y ahora?».
 *
 * La usan las props de la caja, la validacion del cobro en el servidor, el checkout de la tienda y
 * la creacion del pedido. Nadie mas filtra medios de pago: si la pantalla y el cobro preguntaran a
 * lugares distintos, el cobro no cuadraria (la misma leccion que `PromotionService`).
 *
 * Reglas de `availability`, todas opcionales (vacio = sin restriccion):
 *
 *     channels        ['pos', 'online']; nunca mas de lo que permite el `kind`
 *     stores          ids de sede
 *     channel_stores  {online: [ids]}: acota un canal a unas sedes (la contraentrega de una sola sede)
 *     registers       ids de caja (solo en la caja)
 *     schedule        [{days: [1..5], from: '07:00', to: '21:00'}]: varias franjas; el fin no cuenta
 *     valid_from      'Y-m-d', inclusivo
 *     valid_until     'Y-m-d', inclusivo
 *     min_amount      contra lo que se cobra con ESE medio, no contra el total de la venta
 *     max_amount      idem
 *     delivery_modes  ['delivery', 'pickup'] (solo en la tienda)
 *     customer        {registered: bool, credit_line: bool, store_credit: bool}
 *     combinable      si se mezcla con otros medios en el mismo cobro (por defecto si)
 */
class PaymentMethodAvailability
{
    public function __construct(
        protected PaymentMethodCatalog $catalog,
        protected EntitlementService $entitlements,
        protected GatewayRegistry $gateways,
        protected StoreCreditService $storeCredits,
    ) {}

    /**
     * Los medios que valen en ese contexto, en el orden que eligio la empresa.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function available(int $companyId, PaymentContext $context): Collection
    {
        return $this->catalog->forCompany($companyId)
            ->filter(fn (PaymentMethod $method) => $this->reason($method, $context) === null)
            ->values();
    }

    /**
     * Lo que la caja ofrece al abrir el cobro. Las reglas del cliente no se miden aqui porque el
     * cliente se elige despues: viajan como banderas (`present`) y el servidor las vuelve a mirar
     * al cobrar. Las pasarelas solo salen si quien cobra puede usarlas.
     *
     * @return list<array<string, mixed>>
     */
    public function forRegister(int $companyId, int $storeId, ?int $registerId, bool $withGateways): array
    {
        $context = PaymentContext::pos($storeId, $registerId);

        return $this->catalog->forCompany($companyId)
            ->filter(fn (PaymentMethod $method) => $method->kind !== PaymentMethod::KIND_EXCHANGE
                && ($withGateways || ! $method->isGateway())
                && $this->reason($method, $context, withCustomer: false) === null)
            ->map(function (PaymentMethod $method) use ($storeId, $registerId) {
                $item = $this->present($method, PaymentMethod::CHANNEL_POS);

                if ($method->kind === PaymentMethod::KIND_GATEWAY_TERMINAL) {
                    $item['terminals'] = PaymentTerminal::query()->withoutGlobalScopes()
                        ->where('gateway_account_id', $method->gateway_account_id)
                        ->serving($storeId, $registerId)
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn (PaymentTerminal $terminal) => ['id' => $terminal->id, 'name' => $terminal->name])
                        ->all();
                }

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * Con que se cobra un pedido en la puerta: cualquier medio activo que sea plata (efectivo,
     * datafono, transferencia, otro). No mira las reglas de la tienda: el cliente ya pidio, y lo
     * que se registra es como pago de verdad. Nunca credito: una deuda no se paga con otra.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function collectable(int $companyId): Collection
    {
        return $this->catalog->forCompany($companyId)
            ->filter(fn (PaymentMethod $method) => $method->is_active && in_array($method->kind, PaymentMethod::COLLECTABLE_KINDS, true))
            ->values();
    }

    /**
     * Los que valen en la tienda de una sede sin mirar hora, monto ni cliente: lo que dice la
     * revision antes de publicar y el pie con los logos de pago.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function configuredOnline(int $companyId, int $storeId): Collection
    {
        return $this->catalog->forCompany($companyId)
            ->filter(fn (PaymentMethod $method) => $this->reason($method, PaymentContext::online($storeId, null, null, null), static: true) === null)
            ->values();
    }

    /**
     * Por que un medio no vale en ese contexto, o null si vale.
     *
     * @param  ?int  $amount  lo que se cobra con este medio; sin el, en la tienda se mide el del contexto
     * @param  bool  $static  solo las reglas de configuracion (sin hora, montos ni cliente)
     */
    public function reason(PaymentMethod $method, PaymentContext $context, ?int $amount = null, bool $static = false, bool $withCustomer = true): ?string
    {
        $channel = $context->channel;
        $where = $channel === PaymentMethod::CHANNEL_POS ? 'en la caja' : 'en la tienda en línea';

        if (! $method->is_active) {
            return "«{$method->name}» está apagado.";
        }

        if ($method->kind === PaymentMethod::KIND_EXCHANGE) {
            return 'El cambio por devolución solo lo registra la devolución.';
        }

        if (! in_array($channel, PaymentMethod::KIND_CHANNELS[$method->kind] ?? [], true)) {
            return "«{$method->name}» no se usa {$where}.";
        }

        $channels = $this->list($method->rule('channels'));

        if ($channels !== [] && ! in_array($channel, $channels, true)) {
            return "«{$method->name}» no está disponible {$where}.";
        }

        if ($method->isGateway() && ($gatewayReason = $this->gatewayReason($method, $context)) !== null) {
            return $gatewayReason;
        }

        $stores = $this->ids($method->rule('stores'));
        $channelStores = $this->ids(($method->rule('channel_stores') ?? [])[$channel] ?? null);

        if (($stores !== [] || $channelStores !== []) && $context->storeId === null) {
            return "«{$method->name}» no está disponible en esta sede.";
        }

        if (($stores !== [] && ! in_array($context->storeId, $stores, true)) || ($channelStores !== [] && ! in_array($context->storeId, $channelStores, true))) {
            return "«{$method->name}» no está disponible en esta sede.";
        }

        if ($channel === PaymentMethod::CHANNEL_POS) {
            $registers = $this->ids($method->rule('registers'));

            if ($registers !== [] && ! in_array($context->registerId, $registers, true)) {
                return "«{$method->name}» no está disponible en esta caja.";
            }
        }

        if ($static) {
            return null;
        }

        if (($validity = $this->validityReason($method, $context->at)) !== null) {
            return $validity;
        }

        if (! $this->inSchedule($method, $context->at)) {
            return "«{$method->name}» no está disponible a esta hora.";
        }

        $measured = $amount ?? ($channel === PaymentMethod::CHANNEL_ONLINE ? $context->amount : null);

        if ($measured !== null) {
            $min = $this->money($method->rule('min_amount'));
            $max = $this->money($method->rule('max_amount'));

            if ($min !== null && $measured < $min) {
                return "Con «{$method->name}» se cobra desde ".self::format($min).'.';
            }

            if ($max !== null && $measured > $max) {
                return "Con «{$method->name}» se cobra hasta ".self::format($max).'.';
            }
        }

        $modes = $this->list($method->rule('delivery_modes'));

        if ($channel === PaymentMethod::CHANNEL_ONLINE && $modes !== [] && $context->deliveryMode !== null && ! in_array($context->deliveryMode, $modes, true)) {
            return $context->deliveryMode === 'pickup'
                ? "«{$method->name}» es solo para domicilios."
                : "«{$method->name}» es solo para recoger en la tienda.";
        }

        return $withCustomer ? $this->customerReason($method, $context) : null;
    }

    /**
     * Valida un cobro y lo deja listo para guardar: cada linea con su medio configurado y su
     * `kind`. La lista que pinta el navegador es una sugerencia; esto es lo que vale.
     *
     * Una linea puede traer `payment_method_id` o solo `method` (el `kind`): esa la usan quienes
     * cobran por dentro (una devolucion, un pedido, las semillas) y se resuelve al medio de fabrica.
     *
     * @param  list<array<string, mixed>>  $payments
     * @return list<array<string, mixed>> las mismas lineas con `payment_method_id`, `method` y `payment_method`
     */
    public function resolve(int $companyId, PaymentContext $context, array $payments, bool $enforce = true): array
    {
        $resolved = [];
        $gatewayLines = 0;

        foreach ($payments as $payment) {
            $id = isset($payment['payment_method_id']) && $payment['payment_method_id'] !== '' && $payment['payment_method_id'] !== null ? (int) $payment['payment_method_id'] : null;
            $kind = isset($payment['method']) ? (string) $payment['method'] : null;

            if ($id !== null) {
                $method = $this->catalog->find($companyId, $id);

                if (! $method) {
                    throw ValidationException::withMessages(['payments' => ['Medio de pago no reconocido.']]);
                }
            } elseif ($kind !== null && $kind !== '' && in_array($kind, PaymentMethod::KINDS, true)) {
                $method = $this->catalog->defaultFor($companyId, $kind, $context->channel);
            } else {
                throw ValidationException::withMessages(['payments' => ['Medio de pago no reconocido.']]);
            }

            $amount = (int) ($payment['amount'] ?? 0);

            if ($enforce && $amount > 0 && $method->kind !== PaymentMethod::KIND_EXCHANGE) {
                // El minimo y el maximo se miden contra lo que se cobra con ESTE medio.
                $reason = $this->reason($method, $context, $amount);

                if ($reason !== null) {
                    throw ValidationException::withMessages(['payments' => [$reason]]);
                }

                $reference = trim((string) ($payment['reference'] ?? ''));

                if ($method->requires_reference && $reference === '') {
                    $label = mb_strtolower($method->reference_label ?: 'la referencia');

                    throw ValidationException::withMessages(['payments' => ["Falta {$label} de «{$method->name}»."]]);
                }
            }

            if ($method->isGateway() && $amount > 0) {
                $gatewayLines++;
            }

            $resolved[] = array_merge($payment, [
                'payment_method_id' => $method->id,
                'method' => $method->kind,
                'payment_method' => $method,
            ]);
        }

        $paying = array_values(array_filter($resolved, fn (array $line) => (int) ($line['amount'] ?? 0) > 0));

        if ($enforce && count($paying) > 1) {
            foreach ($paying as $line) {
                if (! $line['payment_method']->isCombinable()) {
                    throw ValidationException::withMessages(['payments' => ["«{$line['payment_method']->name}» no se combina con otros medios en el mismo cobro."]]);
                }
            }
        }

        if ($gatewayLines > 1) {
            throw ValidationException::withMessages(['payments' => ['Un cobro admite un solo pago por pasarela.']]);
        }

        return $resolved;
    }

    /**
     * Como viaja un medio a la caja: lo necesario para pintarlo y para que el cajero no arme un
     * cobro que el servidor despues rechaza. Las reglas del cliente viajan como banderas.
     *
     * @return array<string, mixed>
     */
    public function present(PaymentMethod $method, string $channel): array
    {
        $customer = (array) ($method->rule('customer') ?? []);

        return [
            'id' => $method->id,
            'name' => $method->name,
            'kind' => $method->kind,
            'icon' => $method->iconName(),
            'description' => $method->description($channel),
            'instructions' => $method->instructions(),
            'highlight' => (bool) (($method->display ?? [])['highlight'] ?? false),
            'requires_reference' => (bool) $method->requires_reference,
            'reference_label' => $method->reference_label,
            'combinable' => $method->isCombinable(),
            'min_amount' => $this->money($method->rule('min_amount')),
            'max_amount' => $this->money($method->rule('max_amount')),
            'requires_customer' => (bool) ($customer['registered'] ?? false),
            'requires_credit_line' => (bool) ($customer['credit_line'] ?? false),
            'requires_store_credit' => (bool) ($customer['store_credit'] ?? false),
            'group' => $channel === PaymentMethod::CHANNEL_ONLINE ? $method->onlineGroup() : null,
            'provider' => $method->gatewayAccount?->provider,
        ];
    }

    /**
     * Los medios de la tienda, agrupados como se muestran: en linea, al recibir y credito.
     *
     * @return list<array{key: string, label: string, methods: list<array<string, mixed>>}>
     */
    public function groupedForCheckout(Collection $methods): array
    {
        $groups = [];

        foreach (PaymentMethod::GROUP_LABELS as $key => $label) {
            $items = $methods->filter(fn (PaymentMethod $method) => $method->onlineGroup() === $key)
                ->map(fn (PaymentMethod $method) => $this->present($method, PaymentMethod::CHANNEL_ONLINE))
                ->values()
                ->all();

            if ($items !== []) {
                $groups[] = ['key' => $key, 'label' => $label, 'methods' => $items];
            }
        }

        return $groups;
    }

    protected function gatewayReason(PaymentMethod $method, PaymentContext $context): ?string
    {
        $entitlement = $this->entitlements->forCompanyId((int) $method->company_id);

        if ($entitlement && ! $entitlement->allowsNode('payment_methods.gateways.*')) {
            return 'Tu plan no incluye cobrar por pasarela.';
        }

        if ($method->kind === PaymentMethod::KIND_GATEWAY_TERMINAL && $entitlement && ! $entitlement->allowsNode('payment_methods.terminals.*')) {
            return 'Tu plan no incluye datáfonos integrados.';
        }

        $account = $method->gatewayAccount;

        if (! $account || ! $account->is_enabled) {
            return "«{$method->name}» no tiene una cuenta de pasarela encendida.";
        }

        if (! $this->gateways->isEnabled($account->provider)) {
            return "«{$method->name}» no está disponible por ahora.";
        }

        $needed = match ($method->kind) {
            PaymentMethod::KIND_GATEWAY_CHECKOUT => ['checkout'],
            PaymentMethod::KIND_GATEWAY_LINK => ['link', 'qr'],
            default => ['terminal'],
        };

        if (array_intersect($needed, $this->gateways->for($account->provider)->capabilities()) === []) {
            return "La pasarela de «{$method->name}» no ofrece ese cobro.";
        }

        if ($method->kind === PaymentMethod::KIND_GATEWAY_TERMINAL && $context->storeId !== null) {
            $terminals = PaymentTerminal::query()->withoutGlobalScopes()
                ->where('gateway_account_id', $account->id)
                ->serving($context->storeId, $context->registerId)
                ->exists();

            if (! $terminals) {
                return "No hay un datáfono de «{$method->name}» para esta caja.";
            }
        }

        return null;
    }

    protected function validityReason(PaymentMethod $method, CarbonImmutable $at): ?string
    {
        $today = $at->toDateString();
        $from = $method->rule('valid_from');
        $until = $method->rule('valid_until');

        if (is_string($from) && $from !== '' && $today < substr($from, 0, 10)) {
            return "«{$method->name}» empieza a regir el ".CarbonImmutable::parse($from)->format('d/m/Y').'.';
        }

        if (is_string($until) && $until !== '' && $today > substr($until, 0, 10)) {
            return "«{$method->name}» ya no está vigente.";
        }

        return null;
    }

    /**
     * Si el instante cae en alguna franja. Sin franjas, siempre. «De 7:00 a 21:00» incluye las
     * 7:00 en punto y no las 21:00. Una franja que pasa la medianoche (22:00 a 02:00) tambien vale.
     */
    public function inSchedule(PaymentMethod $method, CarbonImmutable $at): bool
    {
        $slots = $method->rule('schedule');

        if (! is_array($slots) || $slots === []) {
            return true;
        }

        $now = $at->format('H:i');
        $day = (int) $at->dayOfWeek;
        $yesterday = ($day + 6) % 7;

        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $days = array_map('intval', (array) ($slot['days'] ?? []));
            $from = self::time($slot['from'] ?? null) ?? '00:00';
            $to = self::time($slot['to'] ?? null) ?? '24:00';

            if ($from <= $to) {
                if (in_array($day, $days, true) && $now >= $from && $now < $to) {
                    return true;
                }

                continue;
            }

            // Pasa la medianoche: la parte de hoy despues de `from`, o la de ayer antes de `to`.
            if ((in_array($day, $days, true) && $now >= $from) || (in_array($yesterday, $days, true) && $now < $to)) {
                return true;
            }
        }

        return false;
    }

    protected function customerReason(PaymentMethod $method, PaymentContext $context): ?string
    {
        $rules = (array) ($method->rule('customer') ?? []);
        $customer = $context->customer;

        if (($rules['registered'] ?? false) && (! $customer || $customer->isGenericConsumer())) {
            return "«{$method->name}» pide un cliente registrado.";
        }

        if (($rules['credit_line'] ?? false) && (! $customer || ! $customer->credit_enabled || $customer->credit_blocked)) {
            return "«{$method->name}» pide un cliente con cupo de crédito.";
        }

        if (($rules['store_credit'] ?? false) && (! $customer || $this->storeCredits->available($customer) <= 0)) {
            return "«{$method->name}» pide un cliente con saldo a favor.";
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map('strval', $value), fn (string $item) => $item !== '')) : [];
    }

    /**
     * @return list<int>
     */
    protected function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map('intval', $value), fn (int $id) => $id > 0)) : [];
    }

    protected function money(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public static function time(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $match)) {
            return null;
        }

        $hours = min(24, (int) $match[1]);

        return sprintf('%02d:%02d', $hours, $hours === 24 ? 0 : min(59, (int) $match[2]));
    }

    protected static function format(int $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }
}
