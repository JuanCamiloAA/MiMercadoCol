@php
    // Ajustes > Tiquete, ya resueltos para la sede de la venta (`ReceiptService::settingsFor`).
    $paper = $receipt['paper_width'];
    $font = $receipt['font_px'];
    $fields = $receipt['fields'];
    $copies = $receipt['copies'];
@endphp
<!DOCTYPE html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tiquete {{ $sale->number }}</title>
    <style>
        /* El ancho es el del rollo de la termica (58 u 80 mm); el alto lo pone el contenido. */
        @page { size: {{ $paper }}mm auto; margin: 0; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 4mm 3mm;
            width: {{ $paper }}mm;
            font-family: 'Courier New', monospace;
            font-size: {{ $font }}px;
            line-height: 1.35;
            color: #000;
            background: #fff;
        }

        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .big { font-size: {{ $font + 4 }}px; }
        .small { font-size: {{ $font - 1 }}px; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        .name { word-break: break-word; }

        /* Ya viene en blanco y negro; el filtro solo cuenta si el servidor no tenia GD. */
        .logo { display: block; max-width: 100%; max-height: 30mm; margin: 0 auto 4px; filter: grayscale(1) contrast(1.8); }
        .code { display: block; margin: 6px auto 0; }
        .code-barcode { width: 100%; height: 12mm; }
        .code-qr { width: 26mm; height: 26mm; }

        /* Cada copia sale como un tiquete aparte. */
        .copy + .copy { page-break-before: always; break-before: page; margin-top: 8mm; }

        .toolbar {
            width: {{ $paper }}mm;
            margin: 12px auto 0;
            display: flex;
            gap: 8px;
        }

        .toolbar button {
            flex: 1;
            height: 40px;
            border-radius: 8px;
            border: 1px solid #0E6E4E;
            background: #0E6E4E;
            color: #fff;
            font-family: system-ui, sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .toolbar button.ghost { background: #fff; color: #0E6E4E; }

        @media print {
            .toolbar { display: none; }
            .copy + .copy { margin-top: 0; }
        }
    </style>
</head>
<body>
@for ($copy = 1; $copy <= $copies; $copy++)
<div class="copy">
<div class="center">
    @if ($logo)
        <img class="logo" src="{{ $logo }}" alt="">
    @endif
    <div class="bold big">{{ $headline }}</div>
    {{-- Razon social y NIT salen siempre: el tiquete puede ser documento equivalente. --}}
    <div>{{ $company?->legal_name }}</div>
    <div class="small">NIT {{ $company?->tax_id }}</div>
    <div class="small">{{ $sale->store?->name }}{{ in_array('store_address', $fields, true) && $sale->store?->address ? ' · '.$sale->store->address : '' }}</div>
    @if (in_array('store_phone', $fields, true) && $sale->store?->phone)
        <div class="small">{{ $sale->store->phone }}</div>
    @endif
    @foreach ($receipt['header'] as $line)
        <div class="small">{{ $line }}</div>
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
        <tr><td colspan="2" class="name">{{ $item->description }}</td></tr>
        <tr>
            <td class="small">
                {{ rtrim(rtrim(number_format($item->quantity, 3, ',', '.'), '0'), ',') }}
                x {{ number_format($item->unit_price, 0, ',', '.') }}@if ($item->discount > 0) (−{{ number_format($item->discount, 0, ',', '.') }})@endif
            </td>
            <td class="right">{{ number_format($item->line_total, 0, ',', '.') }}</td>
        </tr>
        {{-- Lo que llevo el servicio, debajo. Lo quitado no se imprime: el tiquete dice lo que se entrego. --}}
        @foreach ($sale->items->where('parent_sale_item_id', $item->id) as $component)
            <tr>
                <td class="small" style="padding-left: 8px;">· {{ rtrim(rtrim(number_format(abs($component->quantity), 3, ',', '.'), '0'), ',') }} x {{ $component->description }}@if ($component->component_change) (ajustado)@endif</td>
                <td class="right small">{{ $component->line_total != 0 ? number_format($component->line_total, 0, ',', '.') : 'incluido' }}</td>
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
        <tr><td class="bold">Queda a crédito</td><td class="right bold">{{ number_format($sale->due_total, 0, ',', '.') }}</td></tr>
        <tr><td>Vence</td><td class="right">{{ $sale->due_date?->format('d/m/Y') }}</td></tr>
    </table>
@endif

<hr>

<div class="center small">
    {{ $footer }}
    {{-- El numero de la venta, para encontrarla en una devolucion. --}}
    @if ($code)
        <img class="code code-{{ $receipt['code'] }}" src="{{ $code }}" alt="{{ $sale->number }}">
    @endif
    <div style="margin-top:6px" class="bold">{{ $sale->number }}</div>
</div>
</div>
@endfor

<div class="toolbar">
    <button type="button" onclick="window.print()">Imprimir</button>
    <button type="button" class="ghost" onclick="window.close()">Cerrar</button>
</div>

@if ($receipt['auto_print'])
<script>
    // Se manda a imprimir sola: el cajero no tiene que dar un clic extra por cada venta.
    window.addEventListener('load', () => window.setTimeout(() => window.print(), 250));
</script>
@endif
</body>
</html>
