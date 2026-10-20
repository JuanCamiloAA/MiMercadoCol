<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Services\Payments\GatewayPaymentService;
use Illuminate\Http\Response;

/**
 * La pagina a la que vuelve el celular del cliente despues de pagar un QR o link de la caja.
 * Publica y sin datos personales: dice si el pago entro. La caja se entera sola.
 */
class PublicPaymentController extends Controller
{
    public function __construct(protected GatewayPaymentService $payments) {}

    public function __invoke(string $reference): Response
    {
        $transaction = PaymentTransaction::query()->withoutGlobalScopes()->with('gatewayAccount')->where('reference', $reference)->first();

        abort_if($transaction === null, 404);

        $transaction = $this->payments->refresh($transaction);

        return response()->view('payments.public', [
            'status' => $transaction->status,
            'label' => match ($transaction->status) {
                PaymentTransaction::STATUS_APPROVED => 'Tu pago fue aprobado. Ya puedes cerrar esta ventana.',
                PaymentTransaction::STATUS_PENDING => 'Estamos confirmando tu pago. La caja lo verá en unos segundos.',
                default => 'El pago no se completó. Pídele a la caja que intente de nuevo.',
            },
            'amount' => $transaction->amount,
        ]);
    }
}
