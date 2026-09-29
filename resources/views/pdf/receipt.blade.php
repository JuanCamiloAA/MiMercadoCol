@php
    // Ajustes > Tiquete de la sede de la venta. En PDF la letra va en puntos: 7,5 pt es la de siempre.
    $fields = $receipt['fields'];
    $base = ['small' => 7, 'normal' => 7.5, 'large' => 8.5][$receipt['font_size']] ?? 7.5;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 6pt 8pt; }
        body { font-family: DejaVu Sans Mono, monospace; font-size: {{ $base }}pt; color: #000; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .big { font-size: {{ $base + 2.5 }}pt; }
        hr { border: none; border-top: 1px dashed #000; margin: 4pt 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1pt 0; vertical-align: top; }
        .name { font-size: {{ $base - 0.5 }}pt; }
        .logo { max-width: 100%; max-height: 60pt; margin-bottom: 3pt; }
        .code-barcode { width: 100%; height: 30pt; margin-top: 4pt; }
        .code-qr { width: 64pt; height: 64pt; margin-top: 4pt; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
@for ($copy = 1; $copy <= $receipt['copies']; $copy++)
<div class="center">
    {{-- dompdf no descarga direcciones: el logo llega como ruta en disco. --}}
    @if ($logo)
        <img class="logo" src="{{ $logo }}" alt="">
    @endif
    <div class="bold big">{{ $headline }}</div>
    <div>{{ $company?->legal_name }}</div>
    <div>NIT {{ $company?->tax_id }}</div>
    <div>{{ $sale->store?->name }}@if (in_array('store_address', $fields, true)) · {{ $sale->store?->address }}@endif</div>
    @if (in_array('store_phone', $fields, true))
        <div>{{ $sale->store?->phone }}</div>
    @endif
    @foreach ($receipt['header'] as $line)
        <div>{{ $line }}</div>
    @endforeach
</div>

<hr>

<table>
    <tr><td>Tiquete</td><td class="right bold">{{ $sale->number }}</td></tr>
    <tr><td>Fecha</td><td class="right">{{ $sale->sold_at->format('d/m/Y h:i a') }}</td></tr>
    @if (in_array('cashier', $fields, true))
        <tr><td>Cajero</td><td class="right">{{ $sale->user?->name }}</td></tr>
    @endif
    @if (in_array('seller', $fields, true) && $sale->seller && $sale->seller->full_name !== $sale->user?->full_name)
        <tr><td>Vendedor</td><td class="right">{{ $sale->seller->full_name }}</td></tr>
    @endif
    @if (in_array('customer', $fields, true) && $sale->customer)
        <tr><td>Cliente</td><td class="right">{{ $sale->customer->name }}</td></tr>
        <tr><td>Documento</td><td class="right">{{ $sale->customer->document_number }}</td></tr>
    @endif
</table>

<hr>

<table>
    @foreach ($sale->items->whereNull('parent_sale_item_id') as $item)
        <tr>
            <td colspan="2" class="name">{{ $item->description }}</td>
        </tr>
        <tr>
            <td>{{ rtrim(rtrim(number_format($item->quantity, 3, ',', '.'), '0'), ',') }} x {{ number_format($item->unit_price, 0, ',', '.') }}@if ($item->discount > 0) (−{{ number_format($item->discount, 0, ',', '.') }})@endif</td>
            <td class="right">{{ number_format($item->line_total, 0, ',', '.') }}</td>
        </tr>
        {{-- Lo que llevo el servicio, debajo. Lo quitado no se imprime: el tiquete dice lo que se entrego. --}}
        @foreach ($sale->items->where('parent_sale_item_id', $item->id) as $component)
            <tr>
                <td style="padding-left: 6px; font-size: 7pt;">· {{ rtrim(rtrim(number_format(abs($component->quantity), 3, ',', '.'), '0'), ',') }} x {{ $component->description }}@if ($component->component_change) (ajustado)@endif</td>
                <td class="right" style="font-size: 7pt;">{{ $component->line_total != 0 ? number_format($component->line_total, 0, ',', '.') : 'incluido' }}</td>
            </tr>
        @endforeach
    @endforeach
</table>

<hr>

<table>
    <tr><td>Base</td><td class="right">{{ number_format($sale->subtotal, 0, ',', '.') }}</td></tr>
    <tr><td>IVA</td><td class="right">{{ number_format($sale->tax_total, 0, ',', '.') }}</td></tr>
    @if ($sale->discount_total > 0)
        <tr><td>Descuento</td><td class="right">− {{ number_format($sale->discount_total, 0, ',', '.') }}</td></tr>
    @endif
    @if ($sale->rounding_total != 0)
        <tr>
            <td>Ajuste al redondeo</td>
            <td class="right">{{ $sale->rounding_total > 0 ? '+ ' : '− ' }}{{ number_format(abs($sale->rounding_total), 0, ',', '.') }}</td>
        </tr>
    @endif
    <tr><td class="bold big">TOTAL</td><td class="right bold big">$ {{ number_format($sale->total, 0, ',', '.') }}</td></tr>
</table>

<hr>

{{-- Un cobro puede venir repartido: cada medio va en su linea, con el neto aplicado. --}}
<table>
    @foreach ($sale->payments->sortBy('sequence') as $payment)
        <tr>
            <td>{{ $payment->methodLabel() }}{{ $payment->reference ? ' '.$payment->reference : '' }}</td>
            <td class="right">{{ number_format($payment->netAmount(), 0, ',', '.') }}</td>
        </tr>
    @endforeach
    @php($cash = $sale->payments->firstWhere('method', 'cash'))
    @if ($cash && $cash->change_amount > 0)
        <tr><td>Recibido en efectivo</td><td class="right">{{ number_format($cash->amount, 0, ',', '.') }}</td></tr>
        <tr><td class="bold">Cambio</td><td class="right bold">{{ number_format($cash->change_amount, 0, ',', '.') }}</td></tr>
    @endif
</table>

@if ($sale->due_total > 0)
    <hr>
    <table>
        <tr><td class="bold">Queda a credito</td><td class="right bold">{{ number_format($sale->due_total, 0, ',', '.') }}</td></tr>
        <tr><td>Vence</td><td class="right">{{ $sale->due_date?->format('d/m/Y') }}</td></tr>
    </table>
@endif

<hr>

<div class="center">
    {{ $footer }}
    @if ($code)
        <div><img class="code-{{ $receipt['code'] }}" src="{{ $code }}" alt=""></div>
    @endif
    <div style="margin-top:4pt">{{ $sale->number }}</div>
</div>
@if ($copy < $receipt['copies'])
    <div class="page-break"></div>
@endif
@endfor
</body>
</html>
