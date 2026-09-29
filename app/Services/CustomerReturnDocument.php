<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\SalePayment;

/**
 * Lo que dice el comprobante de una devolucion de venta. Lo usan el rollo de la caja
 * (`pos/return-print`) y el PDF del panel (`pdf/customer-return`): los dos cuentan lo mismo.
 */
class CustomerReturnDocument
{
    public function __construct(
        protected ReceiptService $receipts,
        protected StoreCreditService $credits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(CustomerReturn $return): array
    {
        $return->loadMissing([
            'items.parent', 'sale', 'order', 'store', 'customer', 'refundSale', 'exchangeSale',
            'createdBy', 'resolvedBy', 'rejectedBy', 'receivedBy', 'windowApprovedBy',
        ]);

        $company = Company::query()->find($return->company_id);
        $receipt = $this->receipts->settingsFor($return->company_id, $return->store_id);

        $lines = $return->items->whereNull('parent_item_id')->values()->map(fn (CustomerReturnItem $item) => [
            'description' => $item->description,
            'quantity' => $this->quantity($item->quantity),
            'line_total' => $item->line_total,
            'reason' => $item->reasonLabel(),
            'disposition' => $item->dispositionLabel(),
            'components' => $return->items->where('parent_item_id', $item->id)->values()->map(fn (CustomerReturnItem $component) => [
                'description' => $component->description,
                'quantity' => $this->quantity($component->quantity),
                'line_total' => $component->line_total,
                'disposition' => $component->dispositionLabel(),
            ])->all(),
        ])->all();

        return [
            'return' => $return,
            'company' => $company,
            'headline' => $this->receipts->headline($return->company_id, $company),
            'paper' => $receipt['paper_width'],
            'font' => $receipt['font_px'],
            'lines' => $lines,
            'status' => $return->statusLabel(),
            'resolution' => $return->resolutionLabel(),
            'refund_method' => $return->refund_method ? (SalePayment::METHOD_LABELS[$return->refund_method] ?? $return->refund_method) : null,
            'store_credit_balance' => $return->credited_total > 0 && $return->customer ? $this->credits->available($return->customer) : null,
            'origin' => CustomerReturn::ORIGIN_LABELS[$return->origin] ?? $return->origin,
        ];
    }

    protected function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }
}
