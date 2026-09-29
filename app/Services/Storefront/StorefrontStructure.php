<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Store;
use App\Storefront\Targets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La estructura global de la tienda: barra superior, encabezado, menu de categorias, tarjeta de
 * producto, barra del carrito, navegacion del celular, pie y las paginas de categoria,
 * producto y busqueda.
 *
 * Vive en `storefront_settings` (una clave por pieza) con sus valores por defecto aqui, y se
 * versiona y publica con los bloques. Este servicio la resuelve para pintarla: destinos con su
 * direccion, variables de la barra con su valor, categorias con su icono, datos legales de la
 * empresa. El `.tsx` no decide nada de esto.
 */
class StorefrontStructure
{
    /** Variables que entiende la barra superior. Una sin valor oculta su mensaje. */
    public const TOPBAR_VARIABLES = ['domicilio', 'gratis_desde', 'proxima_franja', 'sede', 'horario'];

    /** @var array<string, string> */
    public const TOPBAR_TONES = ['brand_dark' => 'Marca oscuro', 'accent' => 'Color de la marca', 'neutral' => 'Neutro', 'deal' => 'Rojo de oferta'];

    /** @var array<string, string> */
    public const HEADER_STYLES = ['light' => 'Claro', 'brand' => 'Color de la marca', 'dark' => 'Oscuro'];

    /** @var array<string, string> */
    public const LOGO_MODES = ['logo' => 'Logo', 'mark_name' => 'Isotipo y nombre', 'name' => 'Solo el nombre'];

    /** @var array<string, string> */
    public const MOBILE_NAV_ITEMS = [
        'home' => 'Inicio',
        'categories' => 'Categorías',
        'search' => 'Buscar',
        'orders' => 'Pedidos',
        'account' => 'Cuenta',
        'cart' => 'Carrito',
    ];

    /** @var array<string, string> */
    public const SORTS = [
        'relevance' => 'Relevancia',
        'price_asc' => 'Menor precio',
        'price_desc' => 'Mayor precio',
        'best_sellers' => 'Más vendidos',
        'name' => 'Nombre',
    ];

    /** @var array<string, string> */
    public const FILTERS = [
        'subcategories' => 'Subcategorías',
        'brand' => 'Marca',
        'price' => 'Rango de precio',
        'deals' => 'Solo ofertas',
        'in_stock' => 'Solo disponibles',
        // Tallas, presentaciones, sabores: de los articulos con subarticulos de la categoria.
        'attributes' => 'Características (presentación, talla, sabor…)',
    ];

    /** @var array<string, string> */
    public const SOCIAL = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'x' => 'X',
    ];

    /** @var array<string, string> */
    public const PAYMENT_LOGOS = [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta débito y crédito',
        'transfer' => 'Transferencia',
        'nequi' => 'Nequi',
        'daviplata' => 'Daviplata',
        'pse' => 'PSE',
        'credit' => 'Crédito de la tienda',
    ];

    /**
     * Las opciones cerradas de cada campo de la estructura y del tema, con su nombre. El editor
     * las pinta tal cual: ninguna lista vive en el `.tsx`.
     *
     * @var array<string, array<string|int, string>>
     */
    public const CHOICES = [
        'image_fit' => ['contain' => 'Completa, sobre su fondo', 'cover' => 'Llenando el cuadro'],
        'image_bg' => ['white' => 'Blanco', 'light' => 'Gris claro'],
        'deal_badge' => ['percent' => 'Porcentaje (-12%)', 'savings' => 'Ahorro (Ahorra $ 1.500)', 'none' => 'Sin etiqueta'],
        'add_button' => ['solid' => 'Relleno', 'outline' => 'Con borde'],
        'stock_display' => ['low_only' => 'Solo cuando quedan pocas', 'hidden' => 'Nunca (solo «Agotado»)', 'exact' => 'La cantidad exacta'],
        'price_size' => ['lg' => 'Grande', 'md' => 'Mediano'],
        'desktop_behavior' => ['drawer' => 'Abre un cajón al lado', 'page' => 'Va a la página del carrito'],
        'category_layout' => ['sidebar' => 'Filtros a la izquierda', 'top' => 'Filtros en un botón arriba'],
        'pagination' => ['load_more' => 'Botón «Cargar más»', 'pages' => 'Páginas'],
        'related' => ['same_category' => 'De la misma categoría', 'best_sellers' => 'Los más vendidos', 'none' => 'No mostrar'],
        'rotate_seconds' => [0 => 'Quietos, todos a la vez', 3 => 'Rotan cada 3 s', 4 => 'Rotan cada 4 s', 5 => 'Rotan cada 5 s', 6 => 'Rotan cada 6 s', 8 => 'Rotan cada 8 s', 10 => 'Rotan cada 10 s', 15 => 'Rotan cada 15 s'],
        'scheme' => ['light' => 'Claro', 'dark' => 'Oscuro', 'auto' => 'Como el sistema del cliente'],
        'radius' => ['sharp' => 'Rectas', 'soft' => 'Suaves', 'round' => 'Redondas'],
        'density' => ['cozy' => 'Cómoda', 'compact' => 'Compacta'],
        'button_style' => ['solid' => 'Rellenos', 'outline' => 'Con borde'],
        'font_pair' => ['mercado' => 'Mercado', 'moderna' => 'Moderna', 'cercana' => 'Cercana', 'editorial' => 'Editorial'],
    ];

    /** Los medios de pago de la sede, en el nombre de su logo del pie. */
    public const PAYMENT_LOGO_FOR = [
        'cash_on_delivery' => 'cash',
        'card_on_delivery' => 'card',
        'transfer' => 'transfer',
        'customer_credit' => 'credit',
    ];

    /** @var array<string, array<string, mixed>> */
    public const DEFAULTS = [
        'topbar' => [
            'enabled' => true,
            'tone' => 'brand_dark',
            'rotate_seconds' => 6,
            'messages' => [
                ['icon' => 'Truck', 'text' => 'Domicilio {domicilio} en {sede}', 'target' => null],
                ['icon' => 'Gift', 'text' => 'Domicilio gratis desde {gratis_desde}', 'target' => null],
                ['icon' => 'Clock', 'text' => 'Recíbelo {proxima_franja}', 'target' => null],
            ],
        ],
        'header' => [
            'style' => 'light',
            'sticky' => true,
            'show_tagline' => true,
            'show_location' => true,
            'show_account' => true,
            'cart_shows_total' => true,
            'search_placeholder' => '¿Qué necesitas hoy?',
            'logo_mode' => 'logo',
        ],
        'nav' => [
            'enabled' => true,
            'show_deals_chip' => true,
            'deals_label' => 'Ofertas',
            'items' => [],
            'show_all_categories' => true,
            'mega_menu' => true,
        ],
        'mobile_nav' => [
            'items' => ['home', 'categories', 'search', 'orders', 'account'],
        ],
        'card' => [
            'image_fit' => 'contain',
            'image_bg' => 'white',
            'show_brand' => true,
            'show_content' => true,
            'show_unit_price' => true,
            'deal_badge' => 'percent',
            'add_button' => 'solid',
            'stock_display' => 'low_only',
            'low_stock_threshold' => 5,
            'price_size' => 'lg',
        ],
        'cart_bar' => [
            'enabled' => true,
            'show_free_delivery_progress' => true,
            'texts' => [
                'missing' => 'Te faltan {faltan} para el domicilio gratis',
                'reached' => '¡Tu domicilio es gratis!',
            ],
            'desktop_behavior' => 'drawer',
        ],
        'category_page' => [
            'layout' => 'sidebar',
            'filters' => ['subcategories', 'brand', 'attributes', 'price', 'deals', 'in_stock'],
            'sorts' => ['relevance', 'price_asc', 'price_desc', 'best_sellers', 'name'],
            'default_sort' => 'relevance',
            'page_size' => 24,
            'pagination' => 'load_more',
            'show_subcategory_chips' => true,
        ],
        'product_page' => [
            'show_unit_price' => true,
            'show_description' => true,
            'related' => 'same_category',
            'related_limit' => 10,
        ],
        'search' => [
            'suggestions' => true,
            'popular_terms' => ['Arroz', 'Leche', 'Huevos', 'Aceite', 'Café', 'Detergente'],
            'empty_title' => 'No encontramos lo que buscas',
            'empty_text' => 'Revisa cómo lo escribiste o prueba con una palabra más corta.',
        ],
        'footer' => [
            'columns' => [
                ['title' => 'Compra', 'links' => [
                    ['label' => 'Ofertas', 'target' => ['kind' => 'page', 'value' => '/tienda/ofertas']],
                    ['label' => 'Todo el surtido', 'target' => ['kind' => 'page', 'value' => '/tienda/buscar']],
                    ['label' => 'Carrito', 'target' => ['kind' => 'page', 'value' => '/tienda/carrito']],
                ]],
                ['title' => 'Tu cuenta', 'links' => [
                    ['label' => 'Mis pedidos', 'target' => ['kind' => 'page', 'value' => '/tienda/pedidos']],
                    ['label' => 'Crear cuenta', 'target' => ['kind' => 'page', 'value' => '/tienda/registro']],
                ]],
            ],
            'contact' => ['phone' => null, 'whatsapp' => null, 'email' => null, 'address' => null],
            'social' => [],
            'payment_logos' => [],
            'legal_text' => null,
            'terms_target' => ['kind' => 'page', 'value' => '/terminos'],
            'privacy_target' => ['kind' => 'page', 'value' => '/privacidad'],
            'pqrs_target' => null,
        ],
    ];

    public function __construct(protected StorefrontConfig $config) {}

    /**
     * La estructura lista para pintar.
     *
     * @param  array<string, mixed>  $settings  Los ajustes del retrato (publicado o borrador).
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    public function resolve(array $settings, Store $store, array $theme = []): array
    {
        $settings = $this->withDefaults($settings);
        $store->loadMissing('company');
        $company = $store->company;
        $categories = $this->categories($store);
        $slugs = $categories->pluck('slug', 'id')->all();
        $whatsapp = Targets::whatsappNumber($settings['footer']['contact']['whatsapp'] ?? null) ?? Targets::whatsappNumber($store->phone);

        return [
            'topbar' => $this->topbar($settings, $store, $slugs, $whatsapp),
            'header' => array_merge($settings['header'], [
                'location' => [
                    'store' => $store->name,
                    'mode' => ($settings['delivery']['enabled'] ?? true)
                        ? (($settings['delivery']['pickup'] ?? false) ? 'Domicilio o recoger en tienda' : 'Domicilio')
                        : (($settings['delivery']['pickup'] ?? false) ? 'Recoger en tienda' : null),
                ],
            ]),
            'nav' => $this->nav($settings['nav'], $categories, $slugs, $whatsapp),
            'categories' => $this->tree($categories),
            'mobile_nav' => ['items' => array_values(array_intersect($settings['mobile_nav']['items'] ?? [], array_keys(self::MOBILE_NAV_ITEMS)))],
            'card' => $settings['card'],
            'cart_bar' => $settings['cart_bar'],
            'category_page' => $settings['category_page'],
            'product_page' => $settings['product_page'],
            'search' => $settings['search'],
            'catalog' => $settings['catalog'],
            'footer' => $this->footer($settings, $store, $slugs, $whatsapp),
            'delivery' => [
                'enabled' => (bool) ($settings['delivery']['enabled'] ?? true),
                'free_from' => (int) ($settings['delivery']['free_from'] ?? 0),
                'min_order_total' => (int) ($settings['delivery']['min_order_total'] ?? 0),
            ],
            'company' => [
                'name' => $company?->name,
                'slug' => $company?->slug,
            ],
        ];
    }

    /**
     * Completa lo guardado con los valores por defecto, clave por clave, y los textos del
     * carrito un nivel mas adentro.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function withDefaults(array $settings): array
    {
        $defaults = app(StorefrontManager::class)->defaultSettings();

        foreach ($defaults as $key => $value) {
            $settings[$key] = array_merge($value, is_array($settings[$key] ?? null) ? $settings[$key] : []);
        }

        $settings['cart_bar']['texts'] = array_merge(self::DEFAULTS['cart_bar']['texts'], (array) ($settings['cart_bar']['texts'] ?? []));
        $settings['footer']['contact'] = array_merge(self::DEFAULTS['footer']['contact'], (array) ($settings['footer']['contact'] ?? []));

        return $settings;
    }

    /**
     * El valor de cada variable de la barra superior. `null` es «sin valor», y oculta el mensaje.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string|null>
     */
    public function variables(array $settings, Store $store): array
    {
        $delivery = $settings['delivery'] ?? [];
        $enabled = (bool) ($delivery['enabled'] ?? true);
        $fee = (int) ($delivery['fee'] ?? 0);
        $freeFrom = (int) ($delivery['free_from'] ?? 0);

        return [
            'domicilio' => $enabled ? ($fee > 0 ? self::money($fee) : 'gratis') : null,
            // Cero es «no hay domicilio gratis»: nunca «gratis desde $ 0».
            'gratis_desde' => $enabled && $freeFrom > 0 ? self::money($freeFrom) : null,
            'proxima_franja' => $enabled ? $this->nextSlot($store) : null,
            'sede' => $store->name,
            'horario' => $this->hours($store),
        ];
    }

    /**
     * Reemplaza las variables de un texto. Si alguna no tiene valor, el texto no se muestra.
     *
     * @param  array<string, string|null>  $values
     */
    public static function fill(string $text, array $values): ?string
    {
        $missing = false;

        $filled = preg_replace_callback('/\{([a-z_]+)\}/', function (array $match) use ($values, &$missing) {
            $value = $values[$match[1]] ?? null;

            if ($value === null || $value === '') {
                $missing = true;

                return '';
            }

            return $value;
        }, $text);

        return $missing ? null : trim((string) $filled);
    }

    /**
     * Las variables de un texto que no estan en la lista.
     *
     * @return list<string>
     */
    public static function unknownVariables(string $text): array
    {
        preg_match_all('/\{([^}]*)\}/', $text, $matches);

        return array_values(array_diff($matches[1], self::TOPBAR_VARIABLES));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $slugs
     * @return array<string, mixed>
     */
    protected function topbar(array $settings, Store $store, array $slugs, ?string $whatsapp): array
    {
        $values = $this->variables($settings, $store);
        $messages = [];

        foreach ($settings['topbar']['messages'] ?? [] as $message) {
            $text = self::fill((string) ($message['text'] ?? ''), $values);

            if ($text === null || $text === '') {
                continue;
            }

            $messages[] = [
                'icon' => $message['icon'] ?? null,
                'text' => $text,
                'href' => Targets::href($message['target'] ?? null, $slugs, $whatsapp),
            ];
        }

        return [
            'enabled' => (bool) ($settings['topbar']['enabled'] ?? true) && $messages !== [],
            'tone' => $settings['topbar']['tone'] ?? 'brand_dark',
            'rotate_seconds' => (int) ($settings['topbar']['rotate_seconds'] ?? 6),
            'messages' => $messages,
        ];
    }

    /**
     * @param  array<string, mixed>  $nav
     * @param  Collection<int, array<string, mixed>>  $categories
     * @param  array<int, string>  $slugs
     * @return array<string, mixed>
     */
    protected function nav(array $nav, Collection $categories, array $slugs, ?string $whatsapp): array
    {
        $byId = $categories->keyBy('id');
        $items = [];

        foreach ($nav['items'] ?? [] as $item) {
            $target = $item['target'] ?? null;
            $category = ($target['kind'] ?? null) === Targets::KIND_CATEGORY ? $byId->get((int) ($target['value'] ?? 0)) : null;

            // Una categoria apagada o borrada no aparece: el menu no lleva a paginas vacias.
            if (($target['kind'] ?? null) === Targets::KIND_CATEGORY && ! $category) {
                continue;
            }

            $href = Targets::href($target, $slugs, $whatsapp);

            if ($href === null) {
                continue;
            }

            $items[] = [
                'label' => trim((string) ($item['label'] ?? '')) ?: ($category['name'] ?? ''),
                'icon' => $item['icon'] ?? ($category['icon'] ?? null),
                'image' => $category['image'] ?? null,
                'href' => $href,
                'highlight' => (bool) ($item['highlight'] ?? false),
            ];
        }

        // Sin items elegidos: las categorias principales, en su orden.
        if ($items === []) {
            $items = $categories->whereNull('parent_id')->take(10)->map(fn (array $category) => [
                'label' => $category['name'],
                'icon' => $category['icon'],
                'image' => $category['image'],
                'href' => $category['href'],
                'highlight' => false,
            ])->values()->all();
        }

        return [
            'enabled' => (bool) ($nav['enabled'] ?? true),
            'show_deals_chip' => (bool) ($nav['show_deals_chip'] ?? true),
            'deals_label' => trim((string) ($nav['deals_label'] ?? '')) ?: 'Ofertas',
            'show_all_categories' => (bool) ($nav['show_all_categories'] ?? true),
            'mega_menu' => (bool) ($nav['mega_menu'] ?? true),
            'items' => array_values(array_filter($items, fn (array $item) => $item['label'] !== '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $slugs
     * @return array<string, mixed>
     */
    protected function footer(array $settings, Store $store, array $slugs, ?string $whatsapp): array
    {
        $footer = $settings['footer'];
        $company = $store->company;
        $contact = $footer['contact'] ?? [];

        $columns = collect($footer['columns'] ?? [])->map(fn (array $column) => [
            'title' => (string) ($column['title'] ?? ''),
            'links' => collect($column['links'] ?? [])
                ->map(fn (array $link) => ['label' => (string) ($link['label'] ?? ''), 'href' => Targets::href($link['target'] ?? null, $slugs, $whatsapp)])
                ->filter(fn (array $link) => $link['label'] !== '' && $link['href'] !== null)
                ->values()
                ->all(),
        ])->filter(fn (array $column) => $column['links'] !== [])->values()->all();

        $logos = array_values(array_intersect($footer['payment_logos'] ?? [], array_keys(self::PAYMENT_LOGOS)));

        // Sin elegir: los medios que de verdad acepta la sede.
        if ($logos === []) {
            foreach (self::PAYMENT_LOGO_FOR as $setting => $logo) {
                if ($settings['payments'][$setting] ?? false) {
                    $logos[] = $logo;
                }
            }
        }

        $email = $contact['email'] ?: $company?->email;
        $phone = $contact['phone'] ?: $store->phone;
        $whatsappNumber = Targets::whatsappNumber($contact['whatsapp'] ?? null);

        return [
            'columns' => $columns,
            'contact' => [
                'phone' => $phone,
                'whatsapp' => $contact['whatsapp'] ?: null,
                'whatsapp_href' => $whatsappNumber ? 'https://wa.me/'.$whatsappNumber : null,
                'email' => $email,
                'address' => $contact['address'] ?: trim(collect([$store->address, $store->city ?? null])->filter()->implode(', ')),
            ],
            'social' => collect($footer['social'] ?? [])
                ->filter(fn ($row) => is_array($row) && isset(self::SOCIAL[$row['network'] ?? '']) && Targets::safeUrl((string) ($row['url'] ?? '')) !== null)
                ->map(fn (array $row) => ['network' => $row['network'], 'label' => self::SOCIAL[$row['network']], 'url' => $row['url']])
                ->values()
                ->all(),
            'payment_logos' => array_map(fn (string $key) => ['key' => $key, 'label' => self::PAYMENT_LOGOS[$key]], $logos),
            'legal_text' => $footer['legal_text'] ?? null,
            // La franja legal no se configura: el vendedor se identifica con los datos de la
            // empresa, como pide la norma de comercio electronico.
            'legal' => [
                'name' => $company?->legal_name ?: $company?->name,
                'tax_id' => $company?->tax_id,
                'address' => trim(collect([$company?->address, $company?->city])->filter()->implode(', ')) ?: null,
                'phone' => $company?->phone,
                'email' => $company?->email,
                'terms_href' => Targets::href($footer['terms_target'] ?? null, $slugs, $whatsapp),
                'privacy_href' => Targets::href($footer['privacy_target'] ?? null, $slugs, $whatsapp),
                'pqrs_href' => Targets::href($footer['pqrs_target'] ?? null, $slugs, $whatsapp) ?? ($company?->email ? 'mailto:'.$company->email : null),
            ],
            'attribution' => config('catalog.attribution'),
        ];
    }

    /**
     * Las categorias activas de la empresa, con su direccion, su icono y su foto.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function categories(Store $store): Collection
    {
        $rows = Category::query()
            ->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'icon', 'image_media_id', 'parent_id']);

        $images = MediaAsset::query()
            ->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->whereIn('id', $rows->pluck('image_media_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $rows->map(fn (Category $category) => [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'href' => '/tienda/categoria/'.$category->slug,
            'icon' => self::icon($category->icon),
            'image' => $category->image_media_id && $images->has($category->image_media_id)
                ? self::imageUrl($images->get($category->image_media_id))
                : null,
        ]);
    }

    /**
     * Arbol de dos niveles para el menu grande: principales y sus subcategorias.
     *
     * @param  Collection<int, array<string, mixed>>  $categories
     * @return list<array<string, mixed>>
     */
    protected function tree(Collection $categories): array
    {
        $children = $categories->whereNotNull('parent_id')->groupBy('parent_id');

        return $categories->whereNull('parent_id')->map(fn (array $category) => array_merge($category, [
            'children' => ($children->get($category['id']) ?? collect())->values()->all(),
        ]))->values()->all();
    }

    /**
     * El nombre del icono tal como lo pinta la tienda: sin el sufijo `Icon` y de la lista de
     * `config/storefront_icons.php`. Uno que no este ahi cae en la canasta.
     */
    public static function icon(?string $name): string
    {
        $name = preg_replace('/Icon$/', '', trim((string) $name)) ?? '';

        return in_array($name, config('storefront_icons'), true) ? $name : 'Basket';
    }

    /** La mejor miniatura cuadrada de un medio, o el original. */
    public static function imageUrl(MediaAsset $asset): string
    {
        $payload = $asset->toPayload();

        return $payload['variants']['square'] ?? $payload['url'];
    }

    /** La proxima franja con cupo: «hoy entre 4:00 y 6:00 p. m.». */
    protected function nextSlot(Store $store): ?string
    {
        foreach ($this->config->slots($store) as $slot) {
            if ($slot['full']) {
                continue;
            }

            $starts = CarbonImmutable::parse($slot['starts_at']);
            $ends = CarbonImmutable::parse($slot['ends_at']);
            $now = CarbonImmutable::now();
            $day = $starts->isSameDay($now) ? 'hoy' : ($starts->isSameDay($now->addDay()) ? 'mañana' : $starts->locale('es')->isoFormat('dddd'));

            return $day.' entre '.self::clock($starts, false).' y '.self::clock($ends);
        }

        return null;
    }

    protected function hours(Store $store): ?string
    {
        if (! $store->opens_at || ! $store->closes_at) {
            return null;
        }

        $opens = CarbonImmutable::parse((string) $store->opens_at);
        $closes = CarbonImmutable::parse((string) $store->closes_at);

        return 'de '.self::clock($opens).' a '.self::clock($closes);
    }

    /** «4:00 p. m.», como se escribe la hora en Colombia. */
    protected static function clock(CarbonImmutable $time, bool $withPeriod = true): string
    {
        return $time->format('g:i').($withPeriod ? ($time->hour < 12 ? ' a. m.' : ' p. m.') : '');
    }

    public static function money(int $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }
}
