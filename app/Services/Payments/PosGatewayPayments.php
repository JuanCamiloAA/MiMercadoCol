<?php

namespace App\Services\Payments;

use App\Models\HeldSale;
use App\Models\PaymentMethod;
use App\Models\PaymentTerminal;
use App\Models\PaymentTransaction;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Payments\Gateways\GatewayException;
use App\Services\SaleService;
use App\Services\SettingsService;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cobrar en la caja con QR, link o datafono de la pasarela.
 *
 * La venta NO se crea hasta que la pasarela aprueba:
 * 1. Se valida el tiquete completo como si se fuera a cobrar (se arma la venta y se deshace).
 * 2. El tiquete se guarda en el servidor como suspendido, para no perderlo si se recarga.
 * 3. Nace la transaccion `pending` y despues se le pide a la pasarela el QR, el link o el cobro
 *    en el datafono.
 * 4. Aprobada (por sondeo o webhook, da igual cual llegue primero): `SaleService::create` registra
 *    la venta con ese renglon ligado a la transaccion, con la fila bloqueada. Una sola venta.
 * 5. Rechazada, vencida o cancelada: la caja vuelve al cobro con el tiquete intacto.
 */
class PosGatewayPayments implements GatewayPaymentHandler
{
    public function __construct(
        protected GatewayPaymentService $payments,
        protected SaleService $sales,
        protected PaymentMethodCatalog $catalog,
        protected SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $saleData  lo de `StorePosSaleRequest::saleData()`, con los demas pagos
     * @param  array{payment_method_id: int, amount: int, mode?: ?string, terminal_id?: ?int}  $gateway
     * @param  array{label: string, total: int, customer_id?: ?int, payload: array<mixed>}|null  $held
     */
    public function start(array $saleData, array $gateway, ?array $held, User $user, Store $store, RegisterSession $session): PaymentTransaction
    {
        if (! $user->can('pos.sale.gateway')) {
            throw ValidationException::withMessages(['payments' => ['No tienes permiso para cobrar con QR, link o datáfono.']]);
        }

        $method = $this->catalog->find($store->company_id, (int) $gateway['payment_method_id']);

        if (! $method || ! in_array($method->kind, [PaymentMethod::KIND_GATEWAY_LINK, PaymentMethod::KIND_GATEWAY_TERMINAL], true)) {
            throw ValidationException::withMessages(['payments' => ['Ese medio no cobra con QR, link ni datáfono.']]);
        }

        $amount = (int) $gateway['amount'];
        $mode = $method->kind === PaymentMethod::KIND_GATEWAY_TERMINAL
            ? PaymentTransaction::MODE_TERMINAL
            : (($gateway['mode'] ?? null) === PaymentTransaction::MODE_LINK ? PaymentTransaction::MODE_LINK : PaymentTransaction::MODE_QR);
        $terminal = $mode === PaymentTransaction::MODE_TERMINAL ? $this->terminal($method, $store, $session, $gateway['terminal_id'] ?? null) : null;

        // El renglon de la pasarela va con los demas pagos del tiquete.
        $payments = array_values(array_filter($saleData['payments'] ?? [], fn (array $line) => (int) ($line['payment_method_id'] ?? 0) !== $method->id));
        $payments[] = ['payment_method_id' => $method->id, 'amount' => $amount];
        $data = array_merge($saleData, ['payments' => $payments]);

        // 1. El tiquete se valida entero ahora (existencias, cupo, descuentos, medios, totales):
        //    se arma la venta y se deshace. Si no pasaria al aprobar, no se le cobra al cliente.
        $this->preview($data, $user, $store, $session);

        // 2 y 3 se guardan antes de hablar con la pasarela: si la llamada falla o se demora, el
        // rastro (la fila pendiente) ya existe y no depende de que esta peticion termine bien.
        [$transaction, $heldSale] = DB::transaction(function () use ($data, $held, $user, $store, $session, $method, $amount, $mode, $terminal) {
            // 2. El tiquete queda guardado en el servidor mientras la pasarela responde.
            $heldSale = $held ? HeldSale::query()->create([
                'company_id' => $store->company_id,
                'store_id' => $store->id,
                'register_session_id' => $session->id,
                'user_id' => $user->id,
                'customer_id' => $held['customer_id'] ?? null,
                'label' => mb_substr($held['label'], 0, 40),
                'payload' => $held['payload'],
                'total' => (int) $held['total'],
            ]) : null;

            // 3. Primero la fila pendiente, despues la pasarela.
            $minutes = max(2, (int) $this->settings->get($store->company_id, 'payments.pos_link_minutes'));
            $transaction = $this->payments->open($method, $amount, PaymentTransaction::CHANNEL_POS, $mode, [
                'store_id' => $store->id,
                'register_session_id' => $session->id,
                'payment_terminal_id' => $terminal?->id,
                'expires_at' => now()->addMinutes($mode === PaymentTransaction::MODE_TERMINAL ? min($minutes, 5) : $minutes),
                'created_by_user_id' => $user->id,
                'context' => [
                    'sale' => $this->serializable($data),
                    'user_id' => $user->id,
                    'held_sale_id' => $heldSale?->id,
                ],
            ]);

            return [$transaction, $heldSale];
        });

        $transaction->setRelation('gatewayAccount', $method->gatewayAccount);
        $gateway = $this->payments->gatewayFor($transaction);

        try {
            if ($mode === PaymentTransaction::MODE_TERMINAL) {
                $result = $gateway->createTerminalIntent($transaction, $terminal);
                $transaction->forceFill(['terminal_id' => $result->intentId])->save();
                $terminal->forceFill(['last_seen_at' => now()])->save();
            } else {
                $link = $mode === PaymentTransaction::MODE_LINK ? $gateway->createLink($transaction) : $gateway->createQr($transaction);
                $transaction->forceFill([
                    'checkout_url' => $link->url,
                    'qr_payload' => $link->qrPayload ?? $link->url,
                    'gateway_link_id' => $link->gatewayLinkId,
                ])->save();
            }
        } catch (GatewayException $exception) {
            $heldSale?->delete();

            throw $this->payments->failToOpen($transaction, $exception);
        }

        return $transaction;
    }

    public function onApproved(PaymentTransaction $transaction): void
    {
        if ($transaction->applied_at !== null) {
            return;
        }

        // Un cobro de prueba del datafono no es una venta: se da por aplicado.
        if (($transaction->context ?? [])['test'] ?? false) {
            $transaction->forceFill(['applied_at' => now(), 'status_detail' => 'Cobro de prueba aprobado.'])->save();

            return;
        }

        // El cajero la cancelo y despues llego la plata: no se cobra dos veces, se concilia.
        if ($transaction->cancelled_at !== null) {
            throw ValidationException::withMessages(['payment' => ['El cobro se había cancelado en la caja.']]);
        }

        $session = RegisterSession::query()->withoutGlobalScopes()->find($transaction->register_session_id);

        if (! $session || $session->status !== RegisterSession::STATUS_OPEN) {
            throw ValidationException::withMessages(['payment' => ['La caja ya se cerró.']]);
        }

        $sale = $this->createSale($transaction, $session);

        $transaction->forceFill([
            'applied_at' => now(),
            'payable_type' => $sale->getMorphClass(),
            'payable_id' => $sale->id,
        ])->save();

        $this->forgetHeld($transaction);
    }

    public function onFailed(PaymentTransaction $transaction): void
    {
        // El tiquete guardado se queda: si la caja sigue abierta lo descarta ella al volver al
        // cobro; si se recargo o se cerro la pestana, aparece en «Tiquetes suspendidos».
    }

    /**
     * La venta de una transaccion aprobada, con su renglon ligado. Lo usa tambien «Pagos por
     * conciliar» para aplicar una que llego tarde, en una caja abierta.
     */
    public function createSale(PaymentTransaction $transaction, RegisterSession $session, ?User $actor = null): Sale
    {
        $context = (array) ($transaction->context ?? []);
        $data = $this->hydrate((array) ($context['sale'] ?? []));
        $user = $actor ?? User::query()->find($context['user_id'] ?? null);
        $store = Store::query()->withoutGlobalScopes()->findOrFail($session->store_id);

        if (! $user || $data === []) {
            throw ValidationException::withMessages(['payment' => ['La transacción no tiene el tiquete que se iba a cobrar.']]);
        }

        $data['payments'] = array_map(
            fn (array $line) => (int) ($line['payment_method_id'] ?? 0) === (int) $transaction->payment_method_id
                ? array_merge($line, ['payment_transaction_id' => $transaction->id, 'amount' => (int) $transaction->amount, 'reference' => $transaction->gateway_transaction_id ? mb_substr($transaction->gateway_transaction_id, 0, 60) : null])
                : $line,
            $data['payments'] ?? [],
        );

        return $this->sales->create($data, $user, $store, $session);
    }

    /** El QR del cobro como imagen, para pintarlo en la caja sin librerias en el navegador. */
    public static function qrImage(?string $payload): ?string
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            return (new QRCode(new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'outputBase64' => true,
                'eccLevel' => EccLevel::M,
                'quietzoneSize' => 2,
                'svgUseFillAttributes' => true,
                'connectPaths' => true,
            ])))->render($payload);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Arma la venta y la deshace: lo que no pasaria al aprobar se ataja antes de cobrarle al
     * cliente. No deja rastro: numero, existencias y saldos vuelven como estaban.
     *
     * @param  array<string, mixed>  $data
     */
    protected function preview(array $data, User $user, Store $store, RegisterSession $session): void
    {
        DB::beginTransaction();

        try {
            $this->sales->create(array_merge($data, ['gateway_preview' => true]), $user, $store, $session);
        } finally {
            DB::rollBack();
        }
    }

    protected function terminal(PaymentMethod $method, Store $store, RegisterSession $session, ?int $terminalId): PaymentTerminal
    {
        $terminals = PaymentTerminal::query()->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->where('gateway_account_id', $method->gateway_account_id)
            ->serving($store->id, $session->register_id)
            ->orderByRaw('register_id IS NULL')
            ->get();

        $terminal = $terminalId ? $terminals->firstWhere('id', $terminalId) : $terminals->first();

        if (! $terminal) {
            throw ValidationException::withMessages(['payments' => ['No hay un datáfono de esa pasarela para esta caja.']]);
        }

        return $terminal;
    }

    protected function forgetHeld(PaymentTransaction $transaction): void
    {
        $heldId = ($transaction->context ?? [])['held_sale_id'] ?? null;

        if ($heldId) {
            HeldSale::query()->withoutGlobalScopes()->whereKey($heldId)->delete();
        }
    }

    /**
     * Lo que se guarda del tiquete: sin modelos, con los ids de quien autorizo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function serializable(array $data): array
    {
        $approvers = [];

        foreach ((array) ($data['approvers'] ?? []) as $action => $approver) {
            if ($approver instanceof User) {
                $approvers[$action] = $approver->id;
            }
        }

        $data['approver_id'] = ($data['approver'] ?? null) instanceof User ? $data['approver']->id : null;
        $data['approver_ids'] = $approvers;
        unset($data['approver'], $data['approvers'], $data['gateway_preview']);

        $data['payments'] = array_map(fn (array $line) => array_diff_key($line, ['payment_method' => true]), $data['payments'] ?? []);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function hydrate(array $data): array
    {
        if ($data === []) {
            return [];
        }

        $data['approver'] = ! empty($data['approver_id']) ? User::query()->find($data['approver_id']) : null;
        $data['approvers'] = collect((array) ($data['approver_ids'] ?? []))
            ->map(fn ($id) => User::query()->find($id))
            ->filter()
            ->all();

        unset($data['approver_id'], $data['approver_ids']);

        return $data;
    }
}
