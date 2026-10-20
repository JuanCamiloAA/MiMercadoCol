<?php

/*
 * ePayco, con la cuenta de cada empresa (Medios de pago > Pasarelas).
 *
 * Contrato verificado contra la documentacion oficial (octubre de 2026):
 * - Checkout: el estandar de ePayco con la llave publica (`checkout.js`), que abre la pasarela
 *   desde la pagina con invoice, amount, currency, response (vuelta del cliente), confirmation
 *   (servidor a servidor) y `test` para el entorno de pruebas.
 * - Confirmacion (el webhook): llega por GET o POST con x_ref_payco, x_transaction_id, x_amount,
 *   x_currency_code, x_id_invoice, x_cod_response y x_signature. La firma es
 *   SHA256("p_cust_id_cliente^p_key^x_ref_payco^x_transaction_id^x_amount^x_currency_code").
 * - Relectura: GET https://secure.epayco.co/validation/v1/reference/{ref_payco}. x_cod_response:
 *   1 aceptada, 2 rechazada, 3 pendiente, 4 fallida (y otros de reverso o abandono).
 * - Links de pago, QR y datafono integrado: no hay API publica para integrarlos; no se declaran.
 */
return [
    'script' => 'https://checkout.epayco.co/checkout.js',

    'validation_url' => 'https://secure.epayco.co/validation/v1/reference/',
];
