<?php

namespace App\Landing\Blocks;

/**
 * El buscador de sede cercana. Obligatorio: es la puerta de entrada a todas las tiendas. Las
 * sedes las trae `LandingRenderer`: solo las que pueden vender hoy.
 *
 * Tres diseños (`layout`) con sus propios ajustes; el formulario solo muestra los del elegido
 * (`when`), pero los de los otros se conservan para volver sin perder nada.
 */
class StoreFinderBlock extends LandingBlock
{
    public const LAYOUT_LIST_MAP = 'list_map';

    public const LAYOUT_NEAREST_FIRST = 'nearest_first';

    public const LAYOUT_BY_COMPANY = 'by_company';

    /**
     * El mapa que admite cada diseño. El primero es el que queda si llega otro: lista + mapa es,
     * por definicion, con el mapa al lado.
     */
    public const MAP_LAYOUTS = [
        self::LAYOUT_LIST_MAP => ['side'],
        self::LAYOUT_NEAREST_FIRST => ['button', 'top', 'none'],
        self::LAYOUT_BY_COMPANY => ['button', 'none'],
    ];

    public function type(): string
    {
        return 'store_finder';
    }

    public function label(): string
    {
        return 'Buscador de tiendas';
    }

    public function icon(): string
    {
        return 'MapPin';
    }

    public function help(): string
    {
        return 'Elige el diseño: lista con mapa, la más cercana primero o por comercio.';
    }

    public function unique(): bool
    {
        return true;
    }

    public function deletable(): bool
    {
        return false;
    }

    public function hideWarning(): ?string
    {
        return 'Sin el buscador nadie puede elegir tienda desde la landing, y «Cambiar de tienda» en las tiendas lleva aquí.';
    }

    public function fields(): array
    {
        $listMap = ['layout' => [self::LAYOUT_LIST_MAP]];
        $nearestFirst = ['layout' => [self::LAYOUT_NEAREST_FIRST]];
        $byCompany = ['layout' => [self::LAYOUT_BY_COMPANY]];

        return [
            'layout' => ['type' => 'select', 'label' => 'Diseño', 'section' => 'Diseño', 'options' => [
                ['value' => self::LAYOUT_LIST_MAP, 'label' => 'Lista compacta + mapa'],
                ['value' => self::LAYOUT_NEAREST_FIRST, 'label' => 'La más cercana primero'],
                ['value' => self::LAYOUT_BY_COMPANY, 'label' => 'Agrupado por comercio'],
            ]],

            // A · Lista compacta + mapa.
            'list_numbered' => ['type' => 'toggle', 'label' => 'Numerar sedes y pines del mapa', 'section' => 'Diseño', 'when' => $listMap],
            'list_width' => ['type' => 'select', 'label' => 'Ancho de la lista', 'section' => 'Diseño', 'when' => $listMap, 'options' => [
                ['value' => 'narrow', 'label' => 'Angosta'],
                ['value' => 'medium', 'label' => 'Media'],
            ]],
            'cta_mode' => ['type' => 'select', 'label' => 'Botón de compra', 'section' => 'Diseño', 'when' => $listMap, 'options' => [
                ['value' => 'selected', 'label' => 'Solo en la sede elegida'],
                ['value' => 'all', 'label' => 'En todas'],
            ]],
            'preselect_nearest' => ['type' => 'toggle', 'label' => 'Dejar elegida la primera de la lista', 'section' => 'Diseño', 'when' => $listMap],
            'mobile_mode' => ['type' => 'select', 'label' => 'En el celular', 'section' => 'Diseño', 'when' => $listMap, 'options' => [
                ['value' => 'tabs', 'label' => 'Selector Lista | Mapa'],
                ['value' => 'sheet', 'label' => 'Mapa con hoja deslizable'],
            ]],

            // B · La más cercana primero.
            'hero_badge' => ['type' => 'text', 'label' => 'Etiqueta de la tienda destacada', 'section' => 'Diseño', 'max' => 30, 'when' => $nearestFirst],
            'hero_fallback' => ['type' => 'select', 'label' => 'Sin ubicación, destacar', 'section' => 'Diseño', 'when' => $nearestFirst, 'options' => [
                ['value' => 'last_store', 'label' => 'La última tienda del visitante'],
                ['value' => 'prompt', 'label' => 'Una invitación a compartir la ubicación'],
                ['value' => 'none', 'label' => 'Nada'],
            ]],
            'hero_map' => ['type' => 'toggle', 'label' => 'Mapa pequeño en la tienda destacada', 'section' => 'Diseño', 'when' => $nearestFirst],
            'others_title' => ['type' => 'text', 'label' => 'Título del resto', 'section' => 'Diseño', 'max' => 60, 'when' => $nearestFirst],
            'others_columns' => ['type' => 'select', 'label' => 'Columnas del resto (computador)', 'section' => 'Diseño', 'when' => $nearestFirst, 'options' => [
                ['value' => '2', 'label' => '2'],
                ['value' => '3', 'label' => '3'],
                ['value' => '4', 'label' => '4'],
            ]],
            'others_limit' => ['type' => 'select', 'label' => 'Cuántas mostrar antes de «Ver todas»', 'section' => 'Diseño', 'when' => $nearestFirst, 'options' => [
                ['value' => '6', 'label' => '6'],
                ['value' => '9', 'label' => '9'],
                ['value' => '12', 'label' => '12'],
                ['value' => 'all', 'label' => 'Todas'],
            ]],

            // C · Agrupado por comercio.
            'company_order' => ['type' => 'select', 'label' => 'Orden de los comercios', 'section' => 'Diseño', 'when' => $byCompany, 'options' => [
                ['value' => 'nearest', 'label' => 'El más cercano primero'],
                ['value' => 'name', 'label' => 'Por nombre'],
                ['value' => 'stores_count', 'label' => 'Por cantidad de sedes'],
            ], 'help' => 'Sin la ubicación del visitante, «el más cercano» se ordena por nombre.'],
            'company_header' => ['type' => 'select', 'label' => 'Encabezado de la tarjeta', 'section' => 'Diseño', 'when' => $byCompany, 'options' => [
                ['value' => 'accent', 'label' => 'Con el color de la marca'],
                ['value' => 'neutral', 'label' => 'Neutro'],
            ]],
            'stores_per_company' => ['type' => 'select', 'label' => 'Sedes visibles por comercio', 'section' => 'Diseño', 'when' => $byCompany, 'options' => [
                ['value' => '3', 'label' => '3'],
                ['value' => '5', 'label' => '5'],
                ['value' => 'all', 'label' => 'Todas'],
            ]],
            'company_cta' => ['type' => 'toggle', 'label' => 'Botón «Comprar en ‹sede más cercana›» al pie', 'section' => 'Diseño', 'when' => $byCompany],

            'title' => ['type' => 'text', 'label' => 'Título', 'section' => 'Contenido', 'max' => 60],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'section' => 'Contenido', 'max' => 200],
            'default_city' => ['type' => 'text', 'label' => 'Ciudad por defecto', 'section' => 'Contenido', 'max' => 60, 'help' => 'La que se filtra al entrar; vacío muestra todas.'],
            'cta_label' => ['type' => 'text', 'label' => 'Texto del botón de compra', 'section' => 'Contenido', 'max' => 40],
            'last_store_style' => ['type' => 'select', 'label' => '«Seguir comprando en…»', 'section' => 'Contenido', 'options' => [
                ['value' => 'chip', 'label' => 'Al lado del título'],
                ['value' => 'banner', 'label' => 'Franja destacada'],
                ['value' => 'off', 'label' => 'No mostrar'],
            ]],
            'filter_open_now' => ['type' => 'toggle', 'label' => 'Ofrecer el filtro «Abiertas ahora»', 'section' => 'Contenido'],
            'filter_delivery' => ['type' => 'toggle', 'label' => 'Ofrecer el filtro «Con domicilio»', 'section' => 'Contenido'],
            'show_hours' => ['type' => 'toggle', 'label' => 'Mostrar horario y «Abierta ahora»', 'section' => 'Contenido'],
            'show_distance' => ['type' => 'toggle', 'label' => 'Mostrar la distancia', 'section' => 'Contenido'],
            'show_delivery' => ['type' => 'toggle', 'label' => 'Mostrar si lleva domicilio y desde cuánto', 'section' => 'Contenido'],
            'show_next_slot' => ['type' => 'toggle', 'label' => 'Mostrar la próxima franja de entrega', 'section' => 'Contenido'],
            'empty_text' => ['type' => 'text', 'label' => 'Texto cuando no hay tiendas', 'section' => 'Contenido', 'max' => 120],

            // El mapa. El proveedor no se elige aqui: es de la plataforma (Plataforma > Mapas).
            'map_layout' => ['type' => 'select', 'label' => 'Mapa', 'section' => 'Mapa', 'options' => $this->mapLayoutOptions(), 'help' => 'Con «Lista compacta + mapa» el mapa va siempre al lado.'],
            'map_height' => ['type' => 'select', 'label' => 'Alto del mapa', 'section' => 'Mapa', 'options' => [
                ['value' => 'sm', 'label' => 'Bajo'],
                ['value' => 'md', 'label' => 'Medio'],
                ['value' => 'lg', 'label' => 'Alto'],
            ]],
            'map_theme' => ['type' => 'select', 'label' => 'Estilo del mapa', 'section' => 'Mapa', 'options' => [
                ['value' => 'auto', 'label' => 'Igual que la página (claro u oscuro)'],
                ['value' => 'light', 'label' => 'Siempre claro'],
                ['value' => 'dark', 'label' => 'Siempre oscuro'],
            ]],
            'map_cluster' => ['type' => 'toggle', 'label' => 'Agrupar sedes cercanas en un círculo con el número', 'section' => 'Mapa'],
            'map_expand' => ['type' => 'toggle', 'label' => 'Permitir ampliar el mapa en una ventana', 'section' => 'Mapa'],
            'map_search_area' => ['type' => 'toggle', 'label' => 'Ofrecer «Buscar en esta zona» al mover el mapa', 'section' => 'Mapa'],
        ];
    }

    public function defaults(): array
    {
        return [
            'layout' => self::LAYOUT_LIST_MAP,
            'list_numbered' => true,
            'list_width' => 'medium',
            'cta_mode' => 'selected',
            'preselect_nearest' => true,
            'mobile_mode' => 'sheet',
            'hero_badge' => 'La más cercana',
            'hero_fallback' => 'prompt',
            'hero_map' => true,
            'others_title' => 'Otras tiendas cerca',
            'others_columns' => '3',
            'others_limit' => '9',
            'company_order' => 'nearest',
            'company_header' => 'accent',
            'stores_per_company' => '3',
            'company_cta' => true,
            'title' => 'Encuentra tu tienda',
            'text' => 'Comparte tu ubicación o busca por ciudad o barrio. Cada tienda tiene sus precios y su domicilio.',
            'default_city' => '',
            'cta_label' => 'Comprar en esta tienda',
            'last_store_style' => 'chip',
            'filter_open_now' => true,
            'filter_delivery' => true,
            'show_hours' => true,
            'show_distance' => true,
            'show_delivery' => true,
            'show_next_slot' => false,
            'empty_text' => 'Todavía no hay tiendas en línea aquí.',
            'map_layout' => 'side',
            'map_height' => 'md',
            'map_theme' => 'auto',
            'map_cluster' => true,
            'map_expand' => true,
            'map_search_area' => true,
        ];
    }

    /**
     * Ademas de la forma, el mapa que el diseño admite (`MAP_LAYOUTS`). Lo de los otros diseños
     * no se toca: cambiar de diseño y volver deja todo como estaba.
     */
    public function normalize(array $data): array
    {
        $clean = parent::normalize($data);
        $allowed = self::MAP_LAYOUTS[$clean['layout']];

        if (! in_array($clean['map_layout'], $allowed, true)) {
            $clean['map_layout'] = $allowed[0];
        }

        return $clean;
    }

    /**
     * Las opciones del mapa, cada una con los diseños que la admiten, sacados de `MAP_LAYOUTS`.
     *
     * @return list<array{value: string, label: string, when: array<string, list<string>>}>
     */
    protected function mapLayoutOptions(): array
    {
        // En este orden, la primera que ofrece cada diseño es la misma que pone `normalize()`.
        $labels = [
            'side' => 'Al lado de la lista',
            'button' => 'Solo un botón «Ver mapa»',
            'top' => 'Franja arriba de la lista',
            'none' => 'Sin mapa',
        ];

        return array_map(fn (string $value) => [
            'value' => $value,
            'label' => $labels[$value],
            'when' => ['layout' => array_keys(array_filter(self::MAP_LAYOUTS, fn (array $allowed) => in_array($value, $allowed, true)))],
        ], array_keys($labels));
    }
}
