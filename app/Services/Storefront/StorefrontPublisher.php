<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\MediaAsset;
use App\Models\PaymentMethod;
use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Models\StorefrontVersion;
use App\Models\User;
use App\Services\Payments\PaymentMethodAvailability;
use App\Storefront\BlockCatalogue;
use App\Storefront\Targets;
use App\Support\Color;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publicar, revisar y revertir.
 *
 * Publicar no mueve el borrador: le toma una foto, la guarda con su numero y apunta el sitio a
 * ella. Revertir tampoco borra nada: copia una foto vieja a una foto nueva. Asi el historial
 * solo crece y siempre se puede volver.
 */
class StorefrontPublisher
{
    /** La llave de cache del cascaron (tema y estructura, sin bloques). */
    public const SHELL = 'shell';

    /** Cuantas versiones se conservan por sitio. */
    public const KEEP_VERSIONS = 30;

    /** Como se nombra cada pieza de la estructura en el historial. */
    public const STRUCTURE_LABELS = [
        'topbar' => 'barra superior',
        'header' => 'encabezado',
        'nav' => 'menú de categorías',
        'mobile_nav' => 'navegación del celular',
        'card' => 'tarjeta de producto',
        'cart_bar' => 'barra del carrito',
        'category_page' => 'página de categoría',
        'product_page' => 'página de producto',
        'search' => 'búsqueda',
        'footer' => 'pie de página',
        'catalog' => 'catálogo',
    ];

    public function __construct(protected StorefrontManager $manager) {}

    /**
     * Lo que impide publicar y lo que solo conviene saber.
     *
     * @return array{blocking: list<array{message: string, hint: string|null}>, warnings: list<array{message: string, hint: string|null}>}
     */
    /** El aviso de las imagenes sin version de modo oscuro. Avisa, no bloquea: en oscuro va la clara. */
    public static function darkWarning(int $missing): string
    {
        return $missing === 1
            ? '1 imagen o video no tiene versión para modo oscuro: en oscuro se verá la de modo claro.'
            : $missing.' imágenes o videos no tienen versión para modo oscuro: en oscuro se verá la de modo claro.';
    }

    public function validate(Storefront $storefront): array
    {
        $storefront->loadMissing(['theme', 'blocks', 'settings', 'zones', 'slots', 'template', 'store']);

        $blocking = [];
        $warnings = [];

        // Los medios de pago no se publican con la tienda (son operacion): se mira que la sede
        // tenga al menos uno encendido para la tienda en linea.
        $online = app(PaymentMethodAvailability::class)
            ->configuredOnline((int) $storefront->company_id, (int) $storefront->store_id)
            ->reject(fn (PaymentMethod $method) => $method->kind === PaymentMethod::KIND_STORE_CREDIT);

        if ($online->isEmpty()) {
            $blocking[] = [
                'message' => 'No hay ningún medio de pago para la tienda en línea de esta sede.',
                'hint' => 'Medios de pago · enciende uno para la tienda',
            ];
        }

        $delivery = $storefront->setting('delivery', []);

        if ($delivery['enabled'] ?? false) {
            $coverage = $storefront->setting('coverage', []);

            if (($coverage['mode'] ?? 'zones') === 'zones' && $storefront->zones->where('is_active', true)->isEmpty()) {
                $blocking[] = [
                    'message' => 'El domicilio está encendido pero no hay zonas de cobertura activas.',
                    'hint' => 'Funciones · Cobertura',
                ];
            }

            if (($storefront->setting('slots', [])['enabled'] ?? false) && $storefront->slots->where('is_active', true)->isEmpty()) {
                $blocking[] = [
                    'message' => 'Las franjas horarias están encendidas pero no hay ninguna activa.',
                    'hint' => 'Funciones · Horarios y franjas',
                ];
            }
        }

        // Si el diseño lo pone la plantilla, sus bloques y su estructura ya se revisaron al
        // publicarla: aqui solo cuenta lo de la sede.
        $ownDesign = ! $storefront->usesTemplate();
        $blocks = $ownDesign ? $storefront->blocks : collect();
        $media = $this->mediaFacts($blocks, (int) $storefront->company_id);
        // Las versiones de modo oscuro son opcionales: solo se avisa si la tienda puede verse en los dos modos.
        $bothModes = ($storefront->theme?->scheme === 'auto') || (bool) $storefront->theme?->allow_scheme_toggle;

        foreach ($blocks as $block) {
            if (! BlockCatalogue::has($block->type)) {
                continue;
            }

            $definition = BlockCatalogue::for($block->type);
            $where = StorefrontBlock::PAGE_LABELS[$block->page ?? 'home'] ?? 'Portada';

            foreach ($definition->issues($block->payload ?? [], $media) as $issue) {
                $entry = ['message' => $definition->label().' ('.mb_strtolower($where).'): '.$issue['message'], 'hint' => 'Contenido · '.$where];

                if ($issue['level'] === 'block') {
                    $blocking[] = $entry;
                } else {
                    $warnings[] = $entry;
                }
            }

            if ($block->is_visible && $definition->isEmpty($block->payload ?? [])) {
                $warnings[] = [
                    'message' => $definition->summary($block->payload ?? []).' no tiene nada que mostrar.',
                    'hint' => 'Contenido',
                ];
            }

            if ($bothModes && $block->is_visible && ($missing = $definition->missingDarkMedia($block->payload ?? [])) > 0) {
                $warnings[] = [
                    'message' => $definition->label().' ('.mb_strtolower($where).'): '.self::darkWarning($missing),
                    'hint' => 'Contenido · '.$where,
                ];
            }
        }

        if ($ownDesign) {
            [$structureBlocking, $structureWarnings] = $this->structureIssues($storefront);
            array_push($blocking, ...$structureBlocking);
            array_push($warnings, ...$structureWarnings);
        }

        // El texto sobre el color de la marca tiene que poder leerse.
        $contrast = $this->contrast($storefront->theme?->accent ?? '#0E6E4E');

        if ($contrast < 4.5) {
            $blocking[] = [
                'message' => 'El texto sobre el color de la marca no se lee bien (contraste '.number_format($contrast, 1, ',', '.').':1).',
                'hint' => 'Aspecto · Color',
            ];
        }

        // El rojo de oferta va con letra encima y como precio sobre blanco: las dos cosas se leen.
        $deal = $storefront->theme?->deal_color ?? '#D92D20';

        if (Color::contrast($deal, '#FFFFFF') < 4.5) {
            $blocking[] = [
                'message' => 'El rojo de oferta no se lee sobre fondo blanco (contraste '.number_format(Color::contrast($deal, '#FFFFFF'), 1, ',', '.').':1).',
                'hint' => 'Aspecto · Colores',
            ];
        }

        $expired = Coupon::query()
            ->withoutGlobalScopes()
            ->where('company_id', $storefront->company_id)
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->count();

        if ($expired > 0) {
            $warnings[] = [
                'message' => $expired === 1 ? 'Hay un cupón encendido que ya venció.' : 'Hay '.$expired.' cupones encendidos que ya vencieron.',
                'hint' => 'Funciones · Cupones',
            ];
        }

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }

    /**
     * Guarda el borrador como version publicada.
     */
    public function publish(Storefront $storefront, User $user, ?string $label = null): StorefrontVersion
    {
        $checks = $this->validate($storefront);

        if ($checks['blocking'] !== []) {
            throw ValidationException::withMessages([
                'publish' => array_map(fn (array $issue) => $issue['message'], $checks['blocking']),
            ]);
        }

        return DB::transaction(function () use ($storefront, $user, $label) {
            $version = $this->createVersion($storefront, $user, $label, published: true);

            $storefront->forceFill([
                'published_version_id' => $version->id,
                'published_at' => now(),
                'published_by_user_id' => $user->id,
            ])->save();

            $this->prune($storefront);
            $this->forget($storefront);

            return $version;
        });
    }

    /**
     * Vuelve a una version anterior.
     *
     * No se reescribe la historia: se crea una version nueva con el mismo retrato y se repone
     * el borrador, de modo que el historial deja ver que se volvio atras y cuando.
     */
    public function restore(Storefront $storefront, StorefrontVersion $version, User $user): StorefrontVersion
    {
        abort_if($version->storefront_id !== $storefront->id, 403, 'Esa versión no es de esta tienda.');

        return DB::transaction(function () use ($storefront, $version, $user) {
            $this->manager->restore($storefront, $version->snapshot);

            $copy = StorefrontVersion::create([
                'storefront_id' => $storefront->id,
                'number' => $this->nextNumber($storefront),
                'label' => 'Se volvió a la versión '.$version->number,
                'snapshot' => $version->snapshot,
                'created_by_user_id' => $user->id,
                'published_at' => now(),
            ]);

            $storefront->forceFill([
                'published_version_id' => $copy->id,
                'published_at' => now(),
                'published_by_user_id' => $user->id,
            ])->save();

            $this->prune($storefront);
            $this->forget($storefront);

            return $copy;
        });
    }

    /**
     * Que cambio entre dos retratos, en lenguaje de tienda.
     *
     * @return list<string>
     */
    public function diff(?array $before, array $after): array
    {
        if ($before === null) {
            return ['Primera publicación'];
        }

        $changes = [];

        $blocksBefore = collect($before['blocks'] ?? []);
        $blocksAfter = collect($after['blocks'] ?? []);

        if ($blocksBefore->count() !== $blocksAfter->count()) {
            $delta = $blocksAfter->count() - $blocksBefore->count();
            $changes[] = $delta > 0
                ? ($delta === 1 ? '1 bloque agregado' : $delta.' bloques agregados')
                : (abs($delta) === 1 ? '1 bloque eliminado' : abs($delta).' bloques eliminados');
        } else {
            $touched = $blocksAfter->filter(function (array $block, int $index) use ($blocksBefore) {
                $old = $blocksBefore[$index] ?? null;

                return $old === null || ($old['payload'] ?? []) !== ($block['payload'] ?? []) || ($old['type'] ?? '') !== $block['type'];
            })->count();

            if ($touched > 0) {
                $changes[] = $touched === 1 ? '1 bloque cambiado' : $touched.' bloques cambiados';
            }
        }

        $feeBefore = (int) ($before['settings']['delivery']['fee'] ?? 0);
        $feeAfter = (int) ($after['settings']['delivery']['fee'] ?? 0);

        if ($feeBefore !== $feeAfter) {
            $changes[] = 'tarifa de domicilio '.self::money($feeBefore).' → '.self::money($feeAfter);
        }

        if (($before['theme']['accent'] ?? null) !== ($after['theme']['accent'] ?? null)) {
            $changes[] = 'color de la marca';
        }

        if (($before['zones'] ?? []) !== ($after['zones'] ?? [])) {
            $changes[] = 'zonas de cobertura';
        }

        if (($before['slots'] ?? []) !== ($after['slots'] ?? [])) {
            $changes[] = 'franjas horarias';
        }

        if (($before['settings']['payments'] ?? []) !== ($after['settings']['payments'] ?? [])) {
            $changes[] = 'medios de pago';
        }

        foreach (self::STRUCTURE_LABELS as $key => $label) {
            if (($before['settings'][$key] ?? null) !== ($after['settings'][$key] ?? null)) {
                $changes[] = $label;
            }
        }

        if (($before['template']['follows'] ?? false) !== ($after['template']['follows'] ?? false)) {
            $changes[] = ($after['template']['follows'] ?? false) ? 'vuelve a la plantilla' : 'diseño propio';
        }

        return $changes === [] ? ['Sin cambios visibles'] : $changes;
    }

    /**
     * Contraste del texto que va encima del acento, segun WCAG.
     *
     * Se compara contra blanco y contra negro y gana el que mejor se lea encima. La cuenta
     * vive en `App\Support\Color`, compartida con la marca del panel.
     */
    public function contrast(string $hex): float
    {
        return round(max(Color::contrast($hex, '#FFFFFF'), Color::contrast($hex, '#000000')), 2);
    }

    /**
     * El color del texto que va encima del acento.
     *
     * `contrast()` mide contra el mejor de los dos, asi que la medida solo vale si el texto de
     * verdad usa ese. Con un acento amarillo la letra tiene que ser oscura, o el boton dice
     * «Agregar» en blanco sobre amarillo y no se lee.
     */
    public function ink(string $hex): string
    {
        return Color::ink($hex);
    }

    protected function createVersion(Storefront $storefront, User $user, ?string $label, bool $published): StorefrontVersion
    {
        return StorefrontVersion::create([
            'storefront_id' => $storefront->id,
            'number' => $this->nextNumber($storefront),
            'label' => $label,
            'snapshot' => $this->manager->snapshot($storefront),
            'created_by_user_id' => $user->id,
            'published_at' => $published ? now() : null,
        ]);
    }

    protected function nextNumber(Storefront $storefront): int
    {
        return (int) StorefrontVersion::query()->where('storefront_id', $storefront->id)->max('number') + 1;
    }

    /** Se conservan las ultimas treinta; la publicada nunca se poda. */
    protected function prune(Storefront $storefront): void
    {
        $keep = StorefrontVersion::query()
            ->where('storefront_id', $storefront->id)
            ->orderByDesc('number')
            ->limit(self::KEEP_VERSIONS)
            ->pluck('id');

        StorefrontVersion::query()
            ->where('storefront_id', $storefront->id)
            ->whereNotIn('id', $keep)
            ->when($storefront->published_version_id, fn ($query, $id) => $query->whereKeyNot($id))
            ->delete();
    }

    public function forget(Storefront $storefront): void
    {
        foreach ([...StorefrontBlock::PAGES, self::SHELL] as $page) {
            Cache::forget(self::cacheKey($storefront->store_id, $page));
        }
    }

    public static function cacheKey(int $storeId, string $page = StorefrontBlock::PAGE_HOME): string
    {
        return "storefront.published.{$storeId}.{$page}";
    }

    /**
     * Lo que la lista de verificacion necesita saber de cada imagen: su texto alternativo y su
     * peso. Solo cuentan las de la empresa: una tienda propia no puede pintar medios de
     * plataforma.
     *
     * @param  iterable<StorefrontBlock>  $blocks
     * @return array<int, array{alt: string|null, bytes: int|null}>
     */
    public function mediaFacts(iterable $blocks, ?int $companyId): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            $payload = is_array($block) ? ($block['payload'] ?? []) : ($block->payload ?? []);
            $type = is_array($block) ? ($block['type'] ?? '') : $block->type;

            if (BlockCatalogue::has($type)) {
                array_push($ids, ...BlockCatalogue::for($type)->mediaIds($payload));
            }
        }

        if ($ids === []) {
            return [];
        }

        return MediaAsset::query()->withoutGlobalScopes()
            ->whereIn('id', array_unique($ids))
            ->when($companyId === null, fn ($query) => $query->whereNull('company_id'), fn ($query) => $query->where('company_id', $companyId))
            ->get(['id', 'alt', 'bytes'])
            ->mapWithKeys(fn (MediaAsset $asset) => [$asset->id => ['alt' => $asset->alt, 'bytes' => $asset->bytes]])
            ->all();
    }

    /**
     * Lo que la estructura global impide o avisa: mensajes de la barra que no se van a ver,
     * categorias del menu apagadas, estantes vacios y el pie sin tratamiento de datos.
     *
     * @return array{0: list<array{message: string, hint: string|null}>, 1: list<array{message: string, hint: string|null}>}
     */
    protected function structureIssues(Storefront $storefront): array
    {
        $structure = app(StorefrontStructure::class);
        $settings = $structure->withDefaults($storefront->settings->mapWithKeys(fn ($setting) => [$setting->key => $setting->value])->all());
        $blocking = [];
        $warnings = [];

        // Sin enlace al tratamiento de datos no se puede vender en linea: la ley pide decir que
        // se hace con los datos del cliente antes de pedirselos.
        if (Targets::sanitize($settings['footer']['privacy_target'] ?? null) === null) {
            $blocking[] = ['message' => 'El pie no tiene enlace a la política de tratamiento de datos.', 'hint' => 'Contenido · Pie de página'];
        }

        if ($storefront->store) {
            $values = $structure->variables($settings, $storefront->store);

            foreach ($settings['topbar']['messages'] ?? [] as $message) {
                if (($settings['topbar']['enabled'] ?? true) && StorefrontStructure::fill((string) ($message['text'] ?? ''), $values) === null) {
                    $warnings[] = [
                        'message' => 'El mensaje «'.mb_strimwidth((string) $message['text'], 0, 40, '…').'» de la barra superior no se muestra: una de sus variables no tiene valor.',
                        'hint' => 'Contenido · Barra superior',
                    ];
                }
            }
        }

        $categoryIds = collect($settings['nav']['items'] ?? [])
            ->filter(fn ($item) => is_array($item) && ($item['target']['kind'] ?? null) === Targets::KIND_CATEGORY)
            ->map(fn (array $item) => (int) ($item['target']['value'] ?? 0));

        if ($categoryIds->isNotEmpty()) {
            $active = Category::query()->withoutGlobalScopes()->where('company_id', $storefront->company_id)->where('is_active', true)->whereIn('id', $categoryIds)->pluck('id');
            $missing = $categoryIds->diff($active)->count();

            if ($missing > 0) {
                $warnings[] = [
                    'message' => $missing === 1 ? 'Una categoría del menú está apagada o ya no existe: no se muestra.' : $missing.' categorías del menú están apagadas o ya no existen: no se muestran.',
                    'hint' => 'Contenido · Menú de categorías',
                ];
            }
        }

        // Estantes que con su regla de hoy no traen nada.
        if ($storefront->store && $storefront->blocks->contains(fn ($block) => $block->type === 'product_grid' && $block->is_visible)) {
            $renderer = app(StorefrontRenderer::class);

            foreach (StorefrontBlock::PAGES as $page) {
                if (! $storefront->blocks->contains(fn ($block) => $block->type === 'product_grid' && $block->is_visible && ($block->page ?? 'home') === $page)) {
                    continue;
                }

                foreach ($renderer->draft($storefront->store, page: $page)['blocks'] as $block) {
                    if ($block['type'] === 'product_grid' && $block['is_visible'] && ($block['data']['source'] ?? null) !== 'buy_again' && ($block['data']['products'] ?? []) === []) {
                        $warnings[] = [
                            'message' => 'El estante «'.($block['data']['title'] ?: 'sin título').'» no tiene artículos con su regla de hoy.',
                            'hint' => 'Contenido · '.(StorefrontBlock::PAGE_LABELS[$page] ?? 'Portada'),
                        ];
                    }
                }
            }
        }

        return [$blocking, $warnings];
    }

    protected static function money(int $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }
}
