<?php

/*
 * Bold, con la cuenta de cada empresa (Medios de pago > Pasarelas).
 *
 * Contrato verificado contra la documentacion oficial (octubre de 2026):
 * - Autenticacion: `Authorization: x-api-key <llave de identidad>`. Las llaves de prueba se
 *   sacan de «Llaves de pruebas»; el host es el mismo en los dos entornos.
 * - Link de pago (tienda, link y QR de la caja): POST /online/link/v1 con amount_type CLOSE,
 *   amount {currency, total_amount, taxes}, reference (hasta 60), description, expiration_date
 *   (nanosegundos) y callback_url. Responde payload.payment_link (LNK_…) y payload.url.
 *   Estado del link: GET /online/link/v1/{link} (ACTIVE, PROCESSING, PAID, REJECTED, CANCELLED,
 *   EXPIRED). Montos en pesos.
 * - Relectura de la venta: GET https://payments.api.bold.co/v2/payment-voucher/{referencia}.
 *   payment_status: APPROVED, REJECTED, FAILED, VOIDED (finales), PROCESSING, PENDING (solo PSE)
 *   y NO_TRANSACTION_FOUND.
 * - Webhook: CloudEvents con type SALE_APPROVED, SALE_REJECTED, VOID_APPROVED o VOID_REJECTED y
 *   data.metadata.reference. Firma en `x-bold-signature` = hex(HMAC-SHA256(secreto, base64(cuerpo
 *   crudo))); en pruebas el secreto es la cadena vacia. Responder 200 en menos de 2 segundos;
 *   reintenta 5 veces (15 min, 1 h, 4 h, 8 h, 24 h).
 * - Datafono integrado (API Integrations, SmartPro): GET /payments/binded-terminals y
 *   POST /payments/app-checkout con amount, payment_method POS, terminal_model, terminal_serial,
 *   reference y user_email; responde integration_id y el resultado llega por webhook. En pruebas
 *   el monto decide el resultado (de $1.000 a $2.000.000 aprueba; 111111 rechaza por fondos).
 *   La documentacion no trae como cancelar un cobro ya enviado al datafono.
 */
return [
    'hosts' => [
        'sandbox' => 'https://integrations.api.bold.co',
        'production' => 'https://integrations.api.bold.co',
    ],

    'voucher_url' => 'https://payments.api.bold.co/v2/payment-voucher/',
];
