<?php

namespace App\Services;

use App\Support\Money;

/**
 * Totales de una venta, siempre en pesos enteros.
 *
 * Los precios al publico ya incluyen IVA, asi que la base se calcula hacia atras y el
 * impuesto es la diferencia. Sumar bases redondeadas linea a linea evita que el total de
 * la factura se desvie un peso del que se le cobro al cliente.
 */
class SaleTotalsCalculator
{
    /**
     * @param  list<array{quantity: float, unit_price: int, tax_rate: float, discount?: int}>  $lines
     * @return array{
     *     items: list<array{quantity: float, unit_price: int, tax_rate: float, discount: int, line_total: int}>,
     *     subtotal: int, tax_total: int, discount_total: int, total: int
     * }
     */
    public function calculate(array $lines): array
    {
        $items = [];
        $total = 0;
        $baseTotal = 0;
        $discountTotal = 0;

        foreach ($lines as $line) {
            $quantity = (float) $line['quantity'];
            $unitPrice = (int) $line['unit_price'];
            $taxRate = (float) ($line['tax_rate'] ?? 0);
            $discount = max(0, (int) ($line['discount'] ?? 0));

            $gross = (int) round($quantity * $unitPrice);
            $discount = min($discount, $gross);
            $lineTotal = $gross - $discount;

            $items[] = [
                'quantity' => round($quantity, 3),
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'discount' => $discount,
                'line_total' => $lineTotal,
            ];

            $total += $lineTotal;
            $baseTotal += Money::baseFromGross($lineTotal, $taxRate);
            $discountTotal += $discount;
        }

        return [
            'items' => $items,
            'subtotal' => $baseTotal,
            'tax_total' => $total - $baseTotal,
            'discount_total' => $discountTotal,
            'total' => $total,
        ];
    }
}
