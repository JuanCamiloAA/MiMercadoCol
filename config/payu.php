<?php

/*
 * PayU Latam, con la cuenta de cada empresa (Medios de pago > Pasarelas).
 *
 * Contrato verificado contra la documentacion oficial (octubre de 2026):
 * - WebCheckout: formulario POST a la pasarela con merchantId, accountId, description,
 *   referenceCode, amount, tax, taxReturnBase, currency, signature, algorithmSignature, test,
 *   buyerEmail, responseUrl y confirmationUrl.
 * - Firma del formulario: "apiKey~merchantId~referenceCode~amount~currency" con MD5, SHA1,
 *   SHA256 o HMAC-SHA256 (aqui SHA256, declarado en `algorithmSignature`).
 * - Pagina de confirmacion (el webhook): POST con merchant_id, reference_sale, reference_pol,
 *   transaction_id, state_pol (4 aprobada, 6 rechazada, 5 expirada), value, currency y sign.
 *   La firma es "apiKey~merchant_id~reference_sale~new_value~currency~state_pol", donde
 *   new_value lleva un decimal si el segundo es cero (150.00 → 150.0) y dos si no. Responder 200.
 *   Cada reintento del cliente llega con otro transaction_id y la misma referencia: lo aprobado
 *   no se vuelve a procesar.
 * - Relectura: API de consultas, comando ORDER_DETAIL_BY_REFERENCE_CODE con apiLogin y apiKey.
 *   Estados: APPROVED, DECLINED, PENDING, EXPIRED.
 * - Links de pago, QR y datafono integrado: no hay API publica para Colombia; no se declaran.
 */
return [
    'checkout' => [
        'sandbox' => 'https://sandbox.checkout.payulatam.com/ppp-web-gateway-payu/',
        'production' => 'https://checkout.payulatam.com/ppp-web-gateway-payu/',
    ],

    'queries' => [
        'sandbox' => 'https://sandbox.api.payulatam.com/reports-api/4.0/service.cgi',
        'production' => 'https://api.payulatam.com/reports-api/4.0/service.cgi',
    ],
];
