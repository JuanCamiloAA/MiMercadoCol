<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identificacion ante la fuente externa
    |--------------------------------------------------------------------------
    |
    | Open Food Facts exige un User-Agent propio y reconocible. Sin el, o con uno
    | generico, bloquean las peticiones. Va aqui y no incrustado en el cliente para
    | que cada instalacion ponga su correo de contacto.
    |
    */

    'user_agent' => env('CATALOG_OFF_USER_AGENT', 'miMercadoCol/1.0 (contacto@lacanasta.co)'),

    'openfoodfacts' => [
        'base_url' => env('CATALOG_OFF_BASE_URL', 'https://world.openfoodfacts.org'),

        // La API es gratuita y de uso justo: 15 s de paciencia y tres intentos.
        'timeout' => (int) env('CATALOG_OFF_TIMEOUT', 15),
        'retries' => 3,
        'retry_delay_ms' => 1000,

        // El maximo real de la API. Pedir mas no falla: devuelve 100 y deja creyendo
        // que se trajo mas, que es peor que un error.
        'page_size' => 100,

        // Una peticion por segundo desde el worker, nunca en paralelo.
        'delay_between_pages_ms' => (int) env('CATALOG_OFF_DELAY_MS', 1000),

        // El acierto se guarda un dia; el «no existe», una hora, para no martillar la
        // API con el mismo codigo invalido sin quedarse pegado a un negativo viejo.
        'cache_hit_minutes' => 60 * 24,
        'cache_miss_minutes' => 60,
    ],

    'import' => [
        'queue' => env('CATALOG_IMPORT_QUEUE', 'imports'),

        'default_limit' => 500,
        'max_limit' => 5000,

        // Se escribe por bloques: una transaccion de quince mil filas bloquea la tabla
        // el rato entero y deja la barra de avance congelada.
        'chunk' => 200,

        'csv_max_kb' => (int) env('CATALOG_IMPORT_CSV_MAX_KB', 20480),
    ],

    /*
    |--------------------------------------------------------------------------
    | Segmentos del catalogo externo
    |--------------------------------------------------------------------------
    |
    | Lo que la fuente llama `categories_tags_en`, en castellano y como lista. Antes era un
    | campo de texto libre: nadie tiene por que adivinar que la categoria de lacteos se escribe
    | `dairies`, y un nombre mal escrito devolvia el catalogo entero sin avisar.
    |
    | Los numeros al lado son los articulos con pais Colombia que tenia cada segmento cuando se
    | armo esta lista. Estan de referencia: cambian solos a medida que la base crece.
    |
    | Open Food **Facts**: es comida. Aseo del hogar, mascotas y cuidado personal no estan, y
    | por eso no aparecen aqui.
    |
    */

    'segments' => [
        'plant-based-foods' => 'Productos de origen vegetal',   // 862
        'snacks' => 'Pasabocas',                                 // 645
        'sweet-snacks' => 'Pasabocas dulces',                    // 457
        'cereals-and-potatoes' => 'Granos, cereales y papa',     // 443
        'beverages' => 'Bebidas',                                // 426
        'dairies' => 'Lácteos',                                  // 364
        'biscuits-and-cakes' => 'Galletas y tortas',             // 237
        'desserts' => 'Postres',                                 // 207
        'breakfasts' => 'Desayuno',                              // 193
        'salty-snacks' => 'Pasabocas salados',                   // 166
        'condiments' => 'Condimentos',                           // 135
        'spreads' => 'Untables',                                 // 118
        'breads' => 'Panadería',                                 // 110
        'sauces' => 'Salsas',                                    // 101
        'milks' => 'Leches',                                     // 77
        'cheeses' => 'Quesos',                                   // 64
        'chocolates' => 'Chocolates',                            // 60
        'groceries' => 'Abarrotes',                              // 59
        'pastas' => 'Pastas',                                    // 58
        'canned-foods' => 'Enlatados',                           // 57
        'sodas' => 'Gaseosas',                                   // 54
        'seafood' => 'Pescados y mariscos',                      // 50
        'coffees' => 'Café',                                     // 48
        'legumes' => 'Legumbres',                                // 47
        'meats' => 'Carnes',                                     // 44
        'flours' => 'Harinas',                                   // 43
        'waters' => 'Aguas',                                     // 34
        'frozen-foods' => 'Congelados',                          // 32
        'rice' => 'Arroz',                                       // 23
        'sugars' => 'Azúcares',                                  // 18
        'baby-foods' => 'Alimentos para bebé',                   // 11
        'eggs' => 'Huevos',                                      // 7
    ],

    /*
    |--------------------------------------------------------------------------
    | Atribucion
    |--------------------------------------------------------------------------
    |
    | Los datos vienen de Open Food Facts bajo licencia ODbL. El uso comercial esta
    | permitido; la atribucion es obligatoria solo si se redistribuye la base
    | derivada, pero la pantalla la muestra de todos modos.
    |
    */

    'attribution' => [
        'label' => 'Datos de catálogo por Open Food Facts (ODbL)',
        'url' => 'https://openfoodfacts.org',
    ],

];
