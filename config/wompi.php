<?php

/*
 * Pasarela de pagos Wompi.
 *
 * Solo el respaldo: la configuracion viva la edita el super usuario en Plataforma > Pasarela
 * y se guarda cifrada en `payment_gateway_credentials`. El `.env` siembra la primera vez.
 *
 * Contrato verificado contra la documentacion de Wompi (septiembre de 2026):
 * - Tokens de aceptacion: GET /merchants/info con el encabezado `x-merchant-public-key`. La
 *   forma vieja GET /merchants/{llave} deja de existir el 31 de octubre de 2026.
 * - Tokenizar: POST /tokens/cards con la llave publica, desde el navegador.
 * - Fuente de pago: POST /payment_sources con la llave privada (type, token, customer_email,
 *   acceptance_token, accept_personal_auth). Devuelve `public_data` con bin, last_four,
 *   exp_month, exp_year y card_holder.
 * - Cobro: POST /transactions con la llave privada, `payment_source_id` y firma de integridad.
 * - Consulta: GET /transactions/{id}; busqueda: GET /transactions?reference= con la privada.
 * - Firma de integridad: SHA256 de "<referencia><monto><moneda>[<expiracion>]<secreto>".
 * - Eventos: SHA256 de los valores de `signature.properties` + `timestamp` + secreto de eventos.
 *   Wompi manda el checksum en mayusculas. Hay que responder 200; si no, reintenta a los 30
 *   minutos, a las 3 horas y a las 24 horas.
 *
 * Con la cuenta de una EMPRESA (Medios de pago > Pasarelas, `WompiGateway`) se usa el mismo
 * cliente con sus llaves:
 * - Checkout de la tienda: Web Checkout firmado (`checkout_url`), con `redirect-url` a la pagina de
 *   estado y `expiration-time`. La vuelta trae `?id=`, que no se cree: manda la relectura.
 * - Link y QR de la caja: el mismo Web Checkout firmado con NUESTRA referencia y expiracion; el QR
 *   lo codifica. (La API de links de pago de Wompi genera su propia referencia por transaccion:
 *   por eso no se usa aqui.)
 * - Datafono integrado y reembolsos por API: no se declaran.
 * - El evento de cada empresa llega a /webhooks/pagos/wompi/{token}; el de la membresia sigue en
 *   /webhooks/wompi.
 */
return [
    'environment' => env('WOMPI_ENVIRONMENT', 'sandbox'),

    'public_key' => env('WOMPI_PUBLIC_KEY'),
    'private_key' => env('WOMPI_PRIVATE_KEY'),
    'events_secret' => env('WOMPI_EVENTS_SECRET'),
    'integrity_secret' => env('WOMPI_INTEGRITY_SECRET'),

    /*
     * Hosts oficiales. No se leen del entorno a proposito: apuntar la pasarela a un host
     * arbitrario es la forma mas facil de desviar los pagos.
     */
    'hosts' => [
        'sandbox' => 'https://sandbox.wompi.co/v1',
        'production' => 'https://production.wompi.co/v1',
    ],

    /** El checkout web es el mismo en los dos entornos; lo que cambia es la llave publica. */
    'checkout_url' => 'https://checkout.wompi.co/p/',

    /** Minutos que vive un enlace de pago. */
    'checkout_expiration_minutes' => 60,

    /** Prefijos de la llave publica por entorno, para atajar una llave pegada donde no va. */
    'public_key_prefixes' => [
        'sandbox' => 'pub_test_',
        'production' => 'pub_prod_',
    ],
];
