<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuanto trae el empaque.
 *
 * Sin esto no se puede escribir «$ 5.100 / L», que es como un cliente compara dos aceites de
 * presentaciones distintas. El dato se deduce del nombre —«Aceite de girasol 1 L»— porque asi
 * estan escritos hoy, y queda como un campo normal que se puede corregir a mano.
 */
return new class extends Migration
{
    /** Lo que se reconoce en un nombre y a que unidad base equivale. */
    protected const UNITS = [
        'kg' => ['unit' => 'kg', 'factor' => 1],
        'kilo' => ['unit' => 'kg', 'factor' => 1],
        'g' => ['unit' => 'kg', 'factor' => 0.001],
        'gr' => ['unit' => 'kg', 'factor' => 0.001],
        'l' => ['unit' => 'l', 'factor' => 1],
        'lt' => ['unit' => 'l', 'factor' => 1],
        'ml' => ['unit' => 'l', 'factor' => 0.001],
        'cc' => ['unit' => 'l', 'factor' => 0.001],
    ];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /** Cuanto trae, ya convertido a la unidad base: kilos, litros o unidades. */
            $table->decimal('content_amount', 10, 3)->nullable()->after('sold_by_weight');
            $table->string('content_unit', 4)->nullable()->after('content_amount');
        });

        foreach (DB::table('products')->select('id', 'name')->get() as $product) {
            $content = $this->parse($product->name);

            if ($content !== null) {
                DB::table('products')->where('id', $product->id)->update($content);
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['content_amount', 'content_unit']);
        });
    }

    /**
     * @return array{content_amount: float, content_unit: string}|null
     */
    protected function parse(string $name): ?array
    {
        $name = mb_strtolower($name);

        // «1 kg», «600 ml», «1,5 l» y tambien pegado: «500g».
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|kilo|gr|g|lt|l|ml|cc)\b/u', $name, $matches)) {
            $amount = (float) str_replace(',', '.', $matches[1]);
            $unit = self::UNITS[$matches[2]] ?? null;

            if ($unit && $amount > 0) {
                return [
                    'content_amount' => round($amount * $unit['factor'], 3),
                    'content_unit' => $unit['unit'],
                ];
            }
        }

        // «Arepa de maiz blanco x5»: cinco unidades en el mismo empaque.
        if (preg_match('/\bx\s?(\d+)\b/u', $name, $matches)) {
            $count = (int) $matches[1];

            if ($count > 1) {
                return ['content_amount' => $count, 'content_unit' => 'un'];
            }
        }

        return null;
    }
};
