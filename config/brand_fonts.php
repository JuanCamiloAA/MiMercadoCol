<?php

/*
 * Tipografias que se pueden elegir en Ajustes > Apariencia.
 *
 * Lista cerrada y servida desde Google Fonts, como las de fabrica. La clave es el nombre de la
 * familia tal como se escribe en CSS: `BrandThemeService` la pone entre comillas en
 * `--brand-font-*` y arma con ella la direccion de Google Fonts. Por eso aqui solo entran
 * nombres de familia de verdad, sin comillas ni punto y coma.
 *
 *   label   texto que se lee en el selector
 *   weights pesos que se descargan
 *   axis    eje extra con su rango, para las familias que lo piden (opsz)
 *   generic familia generica de respaldo
 */
return [
    // Texto corrido: menus, tablas, formularios.
    'body' => [
        'IBM Plex Sans' => ['label' => 'IBM Plex Sans (de fábrica)', 'weights' => [400, 500, 600, 700], 'generic' => 'sans-serif'],
        'Inter' => ['label' => 'Inter', 'weights' => [400, 500, 600, 700], 'generic' => 'sans-serif'],
        'Source Sans 3' => ['label' => 'Source Sans 3', 'weights' => [400, 500, 600, 700], 'generic' => 'sans-serif'],
        'Nunito Sans' => ['label' => 'Nunito Sans', 'weights' => [400, 500, 600, 700], 'generic' => 'sans-serif'],
        'Figtree' => ['label' => 'Figtree', 'weights' => [400, 500, 600, 700], 'generic' => 'sans-serif'],
        'Work Sans' => ['label' => 'Work Sans', 'weights' => [400, 500, 600, 700], 'generic' => 'sans-serif'],
        'Lexend' => ['label' => 'Lexend (muy legible)', 'weights' => [400, 500, 600, 700], 'generic' => 'sans-serif'],
    ],

    // Titulos de pantalla y de tarjeta.
    'display' => [
        'Bricolage Grotesque' => ['label' => 'Bricolage Grotesque (de fábrica)', 'weights' => [700], 'axis' => ['opsz', '12..96'], 'generic' => 'sans-serif'],
        'Plus Jakarta Sans' => ['label' => 'Plus Jakarta Sans', 'weights' => [700], 'generic' => 'sans-serif'],
        'Outfit' => ['label' => 'Outfit', 'weights' => [700], 'generic' => 'sans-serif'],
        'Manrope' => ['label' => 'Manrope', 'weights' => [700], 'generic' => 'sans-serif'],
        'Space Grotesk' => ['label' => 'Space Grotesk', 'weights' => [700], 'generic' => 'sans-serif'],
        'Fraunces' => ['label' => 'Fraunces (con serifa)', 'weights' => [700], 'axis' => ['opsz', '9..144'], 'generic' => 'serif'],
        'IBM Plex Sans' => ['label' => 'Igual al texto (IBM Plex Sans)', 'weights' => [700], 'generic' => 'sans-serif'],
    ],

    // Cifras: precios, cantidades, totales. Solo familias con cifras de ancho fijo, para que
    // las columnas de plata no bailen al cambiar.
    'numeric' => [
        'IBM Plex Mono' => ['label' => 'IBM Plex Mono (de fábrica)', 'weights' => [400, 500, 600], 'generic' => 'monospace'],
        'JetBrains Mono' => ['label' => 'JetBrains Mono', 'weights' => [400, 500, 600], 'generic' => 'monospace'],
        'Roboto Mono' => ['label' => 'Roboto Mono', 'weights' => [400, 500, 600], 'generic' => 'monospace'],
        'Source Code Pro' => ['label' => 'Source Code Pro', 'weights' => [400, 500, 600], 'generic' => 'monospace'],
    ],
];
