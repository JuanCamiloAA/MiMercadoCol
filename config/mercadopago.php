<?php

/*
 * Mercado Pago, con la cuenta de cada empresa (Medios de pago > Pasarelas).
 *
 * Contrato verificado contra la documentacion oficial (octubre de 2026):
 * - Autenticacion: `Authorization: Bearer <access_token>`. Las credenciales de prueba y las de
 *   produccion son distintas; el host es el mismo.
 * - Checkout (tienda): Checkout Pro con preferencias, POST /checkout/preferences (items,
 *   `external_reference`, `notification_url`, `back_urls`, `auto_return`, `expires`,
 *   `expiration_date_to`). Responde `init_point` (y `sandbox_init_point` con credenciales de
 *   prueba). Mercado Pago anuncia la API de ordenes como el camino nuevo; las preferencias siguen
 *   soportadas y son las que se usan aqui.
 * - Relectura: GET /v1/payments/{id} y GET /v1/payments/search?external_reference=… . Estados:
 *   approved; pending, in_process, authorized (pendiente); rejected; cancelled; refunded y
 *   charged_back (anulado).
 * - Webhook: POST con `type` = payment y `data.id`; el `data.id` llega tambien en la consulta
 *   (`?data.id=`). Firma en `x-signature` = "ts=<ms>,v1=<hex>" y `x-request-id`. Se arma
 *   "id:<data.id en minusculas>;request-id:<x-request-id>;ts:<ts>;" (sin las partes que no
 *   vengan) y se compara HMAC-SHA256 en hex con la clave secreta de webhooks. Hay que responder
 *   200/201 en menos de 22 segundos; si no, reintenta cada 15 minutos.
 * - Reembolso: POST /v1/payments/{id}/refunds con `amount` (con `X-Idempotency-Key`).
 * - Links y QR de la caja: el `init_point` de una preferencia se muestra como link y como QR.
 *   El QR dinamico "en tienda" exige montar sucursales y cajas en Mercado Pago, y Point
 *   (datafono) no esta disponible en Colombia: ninguno de los dos se declara.
 * - Montos en pesos (decimales permitidos por la API); aqui siempre COP entero.
 */
return [
    'hosts' => [
        'sandbox' => 'https://api.mercadopago.com',
        'production' => 'https://api.mercadopago.com',
    ],
];
