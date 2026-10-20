<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTerminal;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;

/**
 * Un conector de pasarela. Todas las pasarelas de las empresas (Wompi, Mercado Pago, PayU, ePayco,
 * Bold) hablan con el sistema por aqui; el contrato verificado de cada una vive en su
 * `config/<proveedor>.php`.
 *
 * Reglas de todos los conectores:
 * - La plata va en COP entero. Si la pasarela pide centavos, se multiplica AQUI y en ningun otro
 *   lado.
 * - Nada de llaves en logs, en `payload` ni en los mensajes de error que se guardan.
 * - `capabilities()` solo declara lo que de verdad existe y funciona en Colombia: la interfaz no
 *   ofrece lo que el conector no declara.
 */
interface PaymentGateway
{
    public const CAPABILITY_CHECKOUT = 'checkout';

    public const CAPABILITY_LINK = 'link';

    public const CAPABILITY_QR = 'qr';

    public const CAPABILITY_TERMINAL = 'terminal';

    public const CAPABILITY_REFUND = 'refund';

    public const CAPABILITY_VOID = 'void';

    public const CAPABILITY_LABELS = [
        self::CAPABILITY_CHECKOUT => 'Pago en la tienda',
        self::CAPABILITY_LINK => 'Link de pago',
        self::CAPABILITY_QR => 'QR en la caja',
        self::CAPABILITY_TERMINAL => 'Datáfono integrado',
        self::CAPABILITY_REFUND => 'Reembolsos',
        self::CAPABILITY_VOID => 'Anulaciones',
    ];

    public function provider(): string;

    public function label(): string;

    /**
     * Las llaves que pide, en orden. La pantalla de la cuenta se arma solo con esto.
     *
     * @return list<array{key: string, label: string, secret: bool, required: bool, hint?: string}>
     */
    public function fields(): array;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    /** Como se configuran los eventos en el panel de la pasarela, en una o dos frases. */
    public function webhookInstructions(): string;

    public function test(PaymentGatewayAccount $account): TestResult;

    /**
     * Abre el cobro de un pedido de la tienda: a donde se manda al cliente (o que widget se pinta).
     *
     * @param  array{return_url: string, customer?: array<string, ?string>, description?: string}  $context
     */
    public function createCheckout(PaymentTransaction $transaction, array $context): CheckoutSession;

    public function createLink(PaymentTransaction $transaction): LinkResult;

    public function createQr(PaymentTransaction $transaction): LinkResult;

    public function createTerminalIntent(PaymentTransaction $transaction, PaymentTerminal $terminal): TerminalResult;

    /** Relee el estado en la pasarela: la fuente de la verdad, por encima de lo que diga un evento. */
    public function fetch(PaymentTransaction $transaction): ?GatewayStatus;

    /**
     * Cuando el evento solo trae el id de la pasarela (Mercado Pago avisa con el id del pago), se
     * relee por ahi para saber a que referencia corresponde. Null si no aplica o no se pudo.
     */
    public function lookup(PaymentGatewayAccount $account, GatewayEvent $event): ?GatewayStatus;

    /** Firma valida y a que transaccion se refiere, o null si no se puede creer. */
    public function verifyWebhook(Request $request, PaymentGatewayAccount $account): ?GatewayEvent;

    /** Anula un link, QR o cobro de datafono pendiente, si la pasarela lo permite. */
    public function cancel(PaymentTransaction $transaction): bool;

    /** Reembolsa por la pasarela, si el conector declara `refund`. */
    public function refund(PaymentTransaction $transaction, int $amount): bool;
}
