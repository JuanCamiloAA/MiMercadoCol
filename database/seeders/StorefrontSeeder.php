<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontTemplateService;
use App\Storefront\BlockCatalogue;
use Database\Seeders\Support\BannerPainter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Una tienda en linea armada y publicada para cada sede.
 *
 * - Las sedes de la primera empresa (La Canasta) se independizan de la plantilla y arman su
 *   propia portada: banner con sus imagenes, un estante por categoria con «Ver todo»,
 *   preguntas y datos de la sede.
 * - Las de las demas (El Vecino) siguen la plantilla por defecto: su diseño es el de la
 *   plantilla y lo suyo (marca, color, domicilio, pagos) sigue siendo suyo.
 *
 * Sin esto, `migrate:fresh --seed` dejaria cada sede con la tienda en blanco.
 */
class StorefrontSeeder extends Seeder
{
    public function __construct(
        protected StorefrontManager $manager,
        protected StorefrontPublisher $publisher,
        protected StorefrontTemplateService $templates,
        protected BannerPainter $painter,
    ) {}

    public function run(): void
    {
        $firstCompany = Company::query()->orderBy('id')->value('id');
        $media = [];

        foreach (Store::query()->withoutGlobalScopes()->orderBy('id')->get() as $store) {
            $storefront = $this->manager->for($store);

            if ($storefront->published_version_id !== null) {
                continue;
            }

            $author = User::query()->withoutGlobalScopes()->where('company_id', $store->company_id)->orderBy('id')->first()
                ?? User::query()->withoutGlobalScopes()->orderBy('id')->first();

            if (! $author) {
                continue;
            }

            // Sin zonas no se puede publicar con cobertura por zonas: la sede arranca con una
            // propia, el barrio de la sede, con la tarifa general.
            $storefront->zones()->create([
                'name' => 'Alrededores de '.$store->name,
                'fee' => 5000,
                'eta_minutes' => 40,
                'is_active' => true,
                'sort_order' => 0,
            ]);

            // Domicilio gratis desde un monto y un pedido minimo: la barra del carrito tiene que
            // decir cuanto falta.
            $storefront->settings()->updateOrCreate(['key' => 'delivery'], ['value' => array_merge(
                $this->manager->defaultSettings()['delivery'],
                ['fee' => 5000, 'free_from' => 80000, 'min_order_total' => 20000, 'pickup' => true]
            )]);

            if ((int) $store->company_id === (int) $firstCompany) {
                $media[$store->company_id] ??= $this->companyMedia((int) $store->company_id);
                $this->ownDesign($storefront, $store, $author, $media[$store->company_id]);
            } else {
                // Sigue la plantilla: su identidad es la suya.
                $storefront->theme()->update(['accent' => '#0F5F8A', 'tagline' => 'El mercado de tu barrio, a domicilio']);
            }

            // Publica quien administra esa empresa, y la tienda queda encendida: una empresa
            // aprovisionada nace con la tienda apagada.
            $this->publisher->publish($storefront->refresh(), $author, 'Tienda inicial');
            $storefront->forceFill(['is_online' => true])->save();
        }
    }

    /**
     * La Canasta: deja la plantilla (se queda con su diseño y sus imagenes, ya en su biblioteca)
     * y arma una portada propia.
     *
     * @param  array<string, int|null>  $media
     */
    protected function ownDesign(Storefront $storefront, Store $store, User $author, array $media): void
    {
        if ($storefront->follows_template) {
            $this->templates->detach($storefront, $author);
            $storefront->refresh();
        }

        $storefront->theme()->update(['tagline' => 'Lo fresco del día, a tu puerta']);
        $storefront->blocks()->where('page', StorefrontBlock::PAGE_HOME)->delete();

        foreach ($this->page($store, $media) as $order => [$type, $payload]) {
            $definition = BlockCatalogue::for($type);

            StorefrontBlock::create([
                'storefront_id' => $storefront->id,
                'uuid' => (string) Str::uuid(),
                'type' => $type,
                'page' => StorefrontBlock::PAGE_HOME,
                'sort_order' => $order,
                'is_visible' => true,
                'devices' => StorefrontBlock::DEVICE_ALL,
                'payload' => $definition->normalize(array_merge($definition->defaults(), $payload)),
            ]);
        }
    }

    /**
     * Las imagenes propias de la empresa, en su biblioteca.
     *
     * @return array<string, int|null>
     */
    protected function companyMedia(int $companyId): array
    {
        $paint = fn (string $alt, int $w, int $h, array $gradient, array $fruits) => $this->painter->paint($alt, $w, $h, $gradient, $fruits, $companyId, 'storefront')?->id;

        return [
            'hero' => $paint('Canasta con frutas y verduras de La Canasta', 1600, 600, ['#14532D', '#16A34A'], ['#DC2626', '#F97316', '#FACC15', '#84CC16']),
            'hero_mobile' => $paint('Canasta con frutas y verduras de La Canasta', 800, 1000, ['#14532D', '#16A34A'], ['#DC2626', '#F97316', '#FACC15']),
            'market' => $paint('Mercado de la semana con descuentos', 1600, 600, ['#7C2D12', '#F97316'], ['#FDE047', '#FFFFFF', '#FCA5A5']),
        ];
    }

    /**
     * La portada propia de La Canasta.
     *
     * @param  array<string, int|null>  $media
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    protected function page(Store $store, array $media): array
    {
        $categories = Category::query()->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug']);

        $byName = fn (string $start) => $categories->first(fn (Category $category) => str_starts_with(Str::lower($category->name), Str::lower($start)));
        $hasSales = Sale::query()->withoutGlobalScopes()->where('company_id', $store->company_id)->exists()
            || $store->company_id === Company::query()->orderBy('id')->value('id');

        $blocks = [
            ['announcement', ['text' => 'Domicilio en '.$store->name.' en menos de una hora', 'tone' => 'accent']],
            ['hero', [
                'layout' => 'full',
                'height' => 'medium',
                'autoplay' => 6,
                'slides' => array_values(array_filter([
                    $media['hero'] ? [
                        'media_id' => $media['hero'], 'media_mobile_id' => $media['hero_mobile'],
                        'title' => 'Lo fresco del día', 'text' => 'Frutas y verduras del mercado de la mañana.',
                        'button_label' => 'Comprar frutas', 'target' => ($fruits = $byName('Frutas')) ? ['kind' => 'category', 'value' => (string) $fruits->id] : ['kind' => 'page', 'value' => '/tienda'],
                        'text_color' => 'light', 'overlay' => 20,
                    ] : null,
                    $media['market'] ? [
                        'media_id' => $media['market'], 'media_mobile_id' => null,
                        'title' => 'Mercado de la semana', 'text' => 'Los precios rebajados de la semana.',
                        'button_label' => 'Ver ofertas', 'target' => ['kind' => 'page', 'value' => '/tienda/ofertas'],
                        'text_color' => 'light', 'overlay' => 20,
                    ] : null,
                ])),
            ]],
            ['categories', ['title' => 'Compra por categoría', 'style' => 'icon_circles', 'source' => 'all', 'limit' => 12]],
            ['product_grid', ['title' => 'Ofertas de la semana', 'source' => 'on_sale', 'layout' => 'carousel', 'limit' => 12, 'background' => 'soft']],
            ['deal_countdown', ['source' => 'next_ending', 'limit' => 8]],
            ['product_grid', $hasSales
                ? ['title' => 'Lo más pedido', 'source' => 'best_sellers', 'layout' => 'carousel', 'limit' => 10]
                : ['title' => 'Destacados de la semana', 'source' => 'featured', 'layout' => 'carousel', 'limit' => 10]],
        ];

        // Un estante por categoria, con su «Ver todo».
        foreach (['Frutas', 'Despensa', 'Lacteos', 'Aseo'] as $start) {
            if ($category = $byName($start)) {
                $blocks[] = ['product_grid', ['title' => $category->name, 'source' => 'category', 'value' => (string) $category->id, 'layout' => 'carousel', 'limit' => 10]];
            }
        }

        return [
            ...$blocks,
            ['cta_band', ['title' => '¿Prefieres pedir por WhatsApp?', 'text' => 'Escríbenos y te armamos el pedido.', 'button_label' => 'Escribir', 'tone' => 'soft']],
            ['benefits', ['items' => [
                ['icon' => 'Truck', 'title' => 'Domicilio rápido', 'text' => 'Llegamos en menos de una hora.'],
                ['icon' => 'Basket', 'title' => 'Fresco cada día', 'text' => 'Frutas y verduras del mercado de la mañana.'],
                ['icon' => 'CreditCard', 'title' => 'Paga como quieras', 'text' => 'Efectivo, tarjeta o transferencia.'],
            ]]],
            ['faq', [
                'title' => 'Preguntas frecuentes',
                'items' => [
                    ['question' => '¿Cuánto cuesta el domicilio?', 'answer' => 'Depende de tu zona; lo ves antes de confirmar el pedido. Desde $ 80.000 es gratis.'],
                    ['question' => '¿Puedo pagar contraentrega?', 'answer' => 'Sí, en efectivo o con tarjeta al recibir.'],
                    ['question' => '¿Qué pasa si falta un producto?', 'answer' => 'Te llamamos para reemplazarlo o quitarlo del pedido.'],
                ],
            ]],
            ['store_info', ['title' => 'Dónde estamos']],
        ];
    }
}
