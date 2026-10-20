<?php

namespace App\Landing;

use App\Landing\Blocks\AppDemoBlock;
use App\Landing\Blocks\BenefitsBlock;
use App\Landing\Blocks\CompaniesShowcaseBlock;
use App\Landing\Blocks\ContactBlock;
use App\Landing\Blocks\CtaBandBlock;
use App\Landing\Blocks\FaqBlock;
use App\Landing\Blocks\FooterBlock;
use App\Landing\Blocks\HeaderBlock;
use App\Landing\Blocks\HeroBlock;
use App\Landing\Blocks\HowItWorksBlock;
use App\Landing\Blocks\LandingBlock;
use App\Landing\Blocks\MediaCarouselBlock;
use App\Landing\Blocks\PlansBlock;
use App\Landing\Blocks\StoreFinderBlock;
use App\Landing\Blocks\TestimonialsBlock;
use App\Landing\Blocks\VideoBlock;
use App\Landing\Blocks\VideoShowcaseBlock;

/**
 * Los tipos de bloque de la landing. Un tipo que no este aqui no se agrega, no se guarda y no
 * se pinta. Agregar uno es escribir su clase, sumarla aqui y pintarla en `LandingBlocks.tsx`.
 */
class LandingBlockCatalogue
{
    /** @var list<class-string<LandingBlock>> En el orden de la landing por defecto. */
    public const TYPES = [
        HeaderBlock::class,
        HeroBlock::class,
        StoreFinderBlock::class,
        HowItWorksBlock::class,
        VideoShowcaseBlock::class,
        VideoBlock::class,
        MediaCarouselBlock::class,
        AppDemoBlock::class,
        CompaniesShowcaseBlock::class,
        BenefitsBlock::class,
        PlansBlock::class,
        TestimonialsBlock::class,
        FaqBlock::class,
        CtaBandBlock::class,
        ContactBlock::class,
        FooterBlock::class,
    ];

    /** Los que tiene que tener la pagina: se crean si faltan y no se borran. */
    public const REQUIRED = ['header', 'store_finder', 'footer'];

    /** @var array<string, LandingBlock>|null */
    protected static ?array $cache = null;

    /**
     * @return array<string, LandingBlock>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $types = [];

        foreach (self::TYPES as $class) {
            $instance = new $class();
            $types[$instance->type()] = $instance;
        }

        return self::$cache = $types;
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    public static function for(string $type): LandingBlock
    {
        $block = self::all()[$type] ?? null;

        abort_if($block === null, 422, 'Ese tipo de bloque no existe.');

        return $block;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toArray(): array
    {
        return array_values(array_map(fn (LandingBlock $block) => $block->toCatalogue(), self::all()));
    }
}
