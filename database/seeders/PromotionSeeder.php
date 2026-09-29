<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use Illuminate\Database\Seeder;

/**
 * Promociones de demostracion en cada empresa: las ofertas de la semana (un porcentaje sobre
 * unos articulos), un dia de frutas (sobre una categoria) y una oferta relampago que vence en
 * horas (precio especial). Asi la tienda tiene estantes de ofertas, precios tachados y una
 * cuenta regresiva de verdad.
 */
class PromotionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Company::query()->orderBy('id')->get() as $company) {
            if (Promotion::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
                continue;
            }

            $products = Product::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->where('is_online_visible', true)
                ->orderBy('id')
                ->get();

            if ($products->count() < 4) {
                continue;
            }

            $weekly = $this->promotion($company, 'Ofertas de la semana', Promotion::TYPE_PERCENT_OFF, ['percent' => 15], now()->subDays(2), now()->addDays(5));

            foreach ($products->filter(fn (Product $product, int $index) => $index % 4 === 1)->take(10) as $product) {
                $this->target($weekly, PromotionTarget::TYPE_PRODUCT, $product->id);
            }

            $fruits = Category::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where(fn ($query) => $query->where('slug', 'like', 'frutas%')->orWhere('slug', 'like', 'granos%'))
                ->first();

            if ($fruits) {
                $day = $this->promotion($company, 'Martes de frutas', Promotion::TYPE_PERCENT_OFF, ['percent' => 10], now()->startOfDay(), now()->addDays(2)->endOfDay());
                $this->target($day, PromotionTarget::TYPE_CATEGORY, $fruits->id);
            }

            // La oferta relampago: vence en horas, a precio especial.
            $flash = $this->promotion($company, 'Oferta relámpago', Promotion::TYPE_SPECIAL_PRICE, [], now()->subHour(), now()->addHours(20), priority: 5);

            foreach ($products->filter(fn (Product $product, int $index) => $index % 5 === 3)->take(6) as $product) {
                $base = (int) ($product->online_price ?: $product->price);
                $this->target($flash, PromotionTarget::TYPE_PRODUCT, $product->id, (int) (round($base * 0.75 / 100) * 100));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function promotion(Company $company, string $name, string $type, array $config, $starts, $ends, int $priority = 1): Promotion
    {
        return Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => $name,
            'type' => $type,
            'label' => null,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'applies_online' => true,
            'applies_pos' => true,
            'priority' => $priority,
            'config' => $config,
            'is_active' => true,
        ]);
    }

    protected function target(Promotion $promotion, string $type, int $id, ?int $value = null): void
    {
        PromotionTarget::query()->create([
            'promotion_id' => $promotion->id,
            'target_type' => $type,
            'target_id' => $id,
            'value' => $value,
            'quantity' => 1,
        ]);
    }
}
