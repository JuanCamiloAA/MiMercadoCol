<?php

namespace App\Storefront;

use App\Storefront\Blocks\AnnouncementBlockData;
use App\Storefront\Blocks\BannerGridBlockData;
use App\Storefront\Blocks\BenefitsBlockData;
use App\Storefront\Blocks\BlockData;
use App\Storefront\Blocks\BrandLogosBlockData;
use App\Storefront\Blocks\CategoriesBlockData;
use App\Storefront\Blocks\CtaBandBlockData;
use App\Storefront\Blocks\DealCountdownBlockData;
use App\Storefront\Blocks\FaqBlockData;
use App\Storefront\Blocks\HeroBlockData;
use App\Storefront\Blocks\ImageTextBlockData;
use App\Storefront\Blocks\LookBlockData;
use App\Storefront\Blocks\MediaCarouselBlockData;
use App\Storefront\Blocks\ProductGridBlockData;
use App\Storefront\Blocks\PromoCarouselBlockData;
use App\Storefront\Blocks\RichTextBlockData;
use App\Storefront\Blocks\StoreInfoBlockData;
use App\Storefront\Blocks\TickerBlockData;
use App\Storefront\Blocks\VideoBlockData;
use App\Storefront\Blocks\VideoShowcaseBlockData;

/**
 * Los tipos de bloque que existen.
 *
 * Esta lista es la fuente: un tipo que no este aqui no se puede agregar, no se guarda y no se
 * renderiza. Agregar uno es escribir su DTO, sumarlo aqui y pintarlo en `StoreBlocks.tsx`.
 */
class BlockCatalogue
{
    /** @var list<class-string<BlockData>> */
    public const TYPES = [
        AnnouncementBlockData::class,
        HeroBlockData::class,
        BannerGridBlockData::class,
        CategoriesBlockData::class,
        ProductGridBlockData::class,
        DealCountdownBlockData::class,
        PromoCarouselBlockData::class,
        MediaCarouselBlockData::class,
        VideoBlockData::class,
        VideoShowcaseBlockData::class,
        CtaBandBlockData::class,
        BenefitsBlockData::class,
        RichTextBlockData::class,
        ImageTextBlockData::class,
        LookBlockData::class,
        BrandLogosBlockData::class,
        TickerBlockData::class,
        FaqBlockData::class,
        StoreInfoBlockData::class,
    ];

    /** @var array<string, BlockData>|null */
    protected static ?array $cache = null;

    /**
     * @return array<string, BlockData>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $types = [];

        foreach (self::TYPES as $class) {
            /** @var BlockData $instance */
            $instance = new $class();
            $types[$instance->type()] = $instance;
        }

        return self::$cache = $types;
    }

    /** Si un tipo se puede usar en una pagina. */
    public static function allowedOn(string $type, string $page): bool
    {
        return self::has($type) && in_array($page, self::for($type)->pages(), true);
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    public static function for(string $type): BlockData
    {
        $block = self::all()[$type] ?? null;

        abort_if($block === null, 422, 'Ese tipo de bloque no existe.');

        return $block;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Fichas de todos los tipos, para el menu de «Agregar» y para armar los formularios.
     *
     * @return list<array<string, mixed>>
     */
    public static function toPayload(string $context = BlockData::CONTEXT_STORE): array
    {
        return collect(self::all())
            ->map(fn (BlockData $block) => $block->toCatalogue($context))
            ->values()
            ->all();
    }
}
