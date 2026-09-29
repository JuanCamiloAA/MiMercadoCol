<?php

namespace Database\Seeders;

use App\Models\StorefrontTemplate;
use App\Models\User;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\BlockCatalogue;
use App\Storefront\Blocks\BlockData;
use Database\Seeders\Support\BannerPainter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * La plantilla base «Supermercado», publicada y por defecto.
 *
 * Es solo diseño: banners con imagenes de plataforma, estantes por regla (ofertas, lo mas
 * vendido, lo nuevo, «vuelve a comprar»), categorias automaticas, la estructura global y el
 * pie. Nada que nombre articulos, categorias ni promociones de una empresa: por eso los
 * mosaicos llevan a una busqueda y no a una categoria.
 */
class StorefrontTemplateSeeder extends Seeder
{
    public function __construct(
        protected StorefrontTemplateService $templates,
        protected BannerPainter $painter,
    ) {}

    public function run(): void
    {
        if (StorefrontTemplate::query()->where('name', 'Supermercado')->exists()) {
            return;
        }

        $author = User::query()->withoutGlobalScopes()->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))->first()
            ?? User::query()->withoutGlobalScopes()->orderBy('id')->first();

        if (! $author) {
            return;
        }

        $media = $this->media();
        $template = $this->templates->create('Supermercado', 'Encabezado de compra, banners con laterales, estantes de ofertas y lo más vendido, oferta relámpago y pie completo. Pensada para supermercados de barrio.');

        $template->forceFill(['draft' => [
            'theme' => [
                'scheme' => 'light',
                'allow_scheme_toggle' => false,
                'font_pair' => 'mercado',
                'radius' => 'soft',
                'density' => 'cozy',
                'button_style' => 'solid',
            ],
            'blocks' => $this->blocks($media),
            'settings' => $this->templates->emptyDraft()['settings'],
        ], 'preview_media_id' => $media['fresh']])->save();

        $this->templates->publish($template->refresh(), $author, 'Plantilla inicial');
        $this->templates->setDefault($template->refresh());
    }

    /**
     * Las imagenes de ejemplo, como medios de plataforma.
     *
     * @return array<string, int|null>
     */
    protected function media(): array
    {
        $paint = fn (string $alt, int $w, int $h, array $gradient, array $fruits) => $this->painter->paint($alt, $w, $h, $gradient, $fruits, null)?->id;

        return [
            'fresh' => $paint('Frutas y verduras frescas sobre fondo verde', 1600, 600, ['#0B5D3B', '#2E9E5B'], ['#E4572E', '#F2A541', '#7BC043', '#F3D34A']),
            'fresh_mobile' => $paint('Frutas y verduras frescas sobre fondo verde', 800, 1000, ['#0B5D3B', '#2E9E5B'], ['#E4572E', '#F2A541', '#7BC043']),
            'deals' => $paint('Canasta de ofertas sobre fondo rojo', 1600, 600, ['#9F1239', '#E4572E'], ['#F3D34A', '#FFB703', '#FB8500']),
            'deals_mobile' => $paint('Canasta de ofertas sobre fondo rojo', 800, 1000, ['#9F1239', '#E4572E'], ['#F3D34A', '#FFB703']),
            'delivery' => $paint('Domicilio a la puerta de la casa', 800, 600, ['#1D4ED8', '#38BDF8'], ['#F3D34A', '#FB8500']),
            'pantry' => $paint('Granos y despensa', 900, 600, ['#92400E', '#F59E0B'], ['#FDE68A', '#FFFFFF']),
            'cleaning' => $paint('Productos de aseo', 900, 600, ['#155E75', '#22D3EE'], ['#A5F3FC', '#FFFFFF']),
            'dairy' => $paint('Lácteos y huevos', 900, 600, ['#1E3A8A', '#60A5FA'], ['#FFFFFF', '#FDE68A']),
            'drinks' => $paint('Bebidas frías', 900, 600, ['#7C2D12', '#EA580C'], ['#FDBA74', '#FFFFFF']),
        ];
    }

    /**
     * @param  array<string, int|null>  $media
     * @return list<array<string, mixed>>
     */
    protected function blocks(array $media): array
    {
        $home = [
            ['hero', [
                'layout' => 'main_side',
                'height' => 'medium',
                'autoplay' => 6,
                'slides' => [
                    [
                        'media_id' => $media['fresh'], 'media_mobile_id' => $media['fresh_mobile'],
                        'title' => 'Mercado fresco todos los días', 'text' => 'Frutas, verduras y todo lo de la casa, a tu puerta.',
                        'button_label' => 'Ver ofertas', 'target' => ['kind' => 'page', 'value' => '/tienda/ofertas'],
                        'text_color' => 'light', 'overlay' => 20,
                    ],
                    [
                        'media_id' => $media['deals'], 'media_mobile_id' => $media['deals_mobile'],
                        'title' => 'Ofertas de la semana', 'text' => 'Precios rebajados en lo que más llevas.',
                        'button_label' => 'Aprovechar', 'target' => ['kind' => 'page', 'value' => '/tienda/ofertas'],
                        'text_color' => 'light', 'overlay' => 20,
                    ],
                ],
                'side' => [
                    ['kind' => 'image', 'media_id' => $media['delivery'], 'title' => 'Domicilio rápido', 'target' => ['kind' => 'page', 'value' => '/tienda/buscar']],
                    ['kind' => 'color', 'tone' => 'deal', 'title' => 'Martes de frutas', 'text' => 'Lo más fresco, a mejor precio', 'target' => ['kind' => 'page', 'value' => '/tienda/ofertas']],
                ],
            ]],
            ['categories', ['title' => 'Compra por categoría', 'style' => 'icon_circles', 'source' => 'all', 'limit' => 12]],
            ['product_grid', ['title' => 'Ofertas de la semana', 'source' => 'on_sale', 'layout' => 'carousel', 'limit' => 12, 'background' => 'soft', 'see_all_target' => ['kind' => 'page', 'value' => '/tienda/ofertas']]],
            ['deal_countdown', ['title' => null, 'source' => 'next_ending', 'limit' => 8]],
            ['product_grid', ['title' => 'Lo más comprado aquí', 'source' => 'best_sellers', 'layout' => 'carousel', 'limit' => 12]],
            ['banner_grid', [
                'title' => 'Todo para tu casa',
                'layout' => '4',
                'tiles' => [
                    ['kind' => 'image', 'media_id' => $media['pantry'], 'title' => 'Despensa', 'text' => 'Arroz, granos y aceites', 'target' => ['kind' => 'search', 'value' => 'arroz']],
                    ['kind' => 'image', 'media_id' => $media['dairy'], 'title' => 'Lácteos', 'text' => 'Leche, quesos y huevos', 'target' => ['kind' => 'search', 'value' => 'leche']],
                    ['kind' => 'image', 'media_id' => $media['cleaning'], 'title' => 'Aseo', 'text' => 'La casa limpia', 'target' => ['kind' => 'search', 'value' => 'detergente']],
                    ['kind' => 'image', 'media_id' => $media['drinks'], 'title' => 'Bebidas', 'text' => 'Para la nevera', 'target' => ['kind' => 'search', 'value' => 'jugo']],
                ],
            ]],
            ['product_grid', ['title' => 'Vuelve a comprar', 'source' => 'buy_again', 'layout' => 'carousel', 'limit' => 10]],
            ['product_grid', ['title' => 'Lo último que llegó', 'source' => 'new_arrivals', 'layout' => 'grid', 'columns_mobile' => '2', 'limit' => 10]],
            ['cta_band', []],
            ['benefits', ['items' => [
                ['icon' => 'Truck', 'title' => 'Domicilio rápido', 'text' => 'Te llevamos el pedido hoy'],
                ['icon' => 'Leaf', 'title' => 'Fresco cada día', 'text' => 'Del mercado de la mañana'],
                ['icon' => 'CreditCard', 'title' => 'Paga como quieras', 'text' => 'Efectivo, tarjeta o transferencia'],
                ['icon' => 'ShieldCheck', 'title' => 'Compra segura', 'text' => 'Si algo falla, lo devuelves'],
            ]]],
        ];

        $product = [
            ['product_grid', ['title' => 'Otros clientes también llevaron', 'source' => 'best_sellers', 'layout' => 'carousel', 'limit' => 10]],
            ['benefits', ['items' => [
                ['icon' => 'Truck', 'title' => 'Domicilio rápido', 'text' => 'Hoy mismo en tu casa'],
                ['icon' => 'ShieldCheck', 'title' => 'Devolución fácil', 'text' => 'Desde «Mis pedidos»'],
            ]]],
        ];

        $cart = [
            ['product_grid', ['title' => '¿Se te olvidó algo?', 'source' => 'on_sale', 'layout' => 'carousel', 'limit' => 10]],
        ];

        $rows = [];

        foreach (['home' => $home, 'product' => $product, 'cart' => $cart] as $page => $blocks) {
            foreach ($blocks as $order => [$type, $payload]) {
                $definition = BlockCatalogue::for($type);

                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'type' => $type,
                    'page' => $page,
                    'sort_order' => $order,
                    'is_visible' => true,
                    'visible_from' => null,
                    'visible_to' => null,
                    'devices' => 'all',
                    'payload' => $definition->normalize(array_merge($definition->defaults(), $payload), BlockData::CONTEXT_TEMPLATE),
                ];
            }
        }

        return $rows;
    }
}
