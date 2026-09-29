<!DOCTYPE html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Devolución {{ $return->number }}</title>
    <style>
        /* El mismo rollo del tiquete de la sede. */
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

        .toolbar { width: {{ $paper }}mm; margin: 12px auto 0; display: flex; gap: 8px; }
        .toolbar button {
            flex: 1; height: 40px; border-radius: 8px; border: 1px solid #0E6E4E; background: #0E6E4E; color: #fff;
            font-family: system-ui, sans-serif; font-size: 14px; font-weight: 600; cursor: pointer;
        }
        .toolbar button.ghost { background: #fff; color: #0E6E4E; }

        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
<div class="center">
    <div class="bold big">{{ $headline }}</div>
    <div>{{ $company?->legal_name }}</div>
    <div class="small">NIT {{ $company?->tax_id }}</div>
    <div class="small">{{ $return->store?->name }}{{ $return->store?->address ? ' · '.$return->store->address : '' }}</div>
</div>

<hr>

<div class="center bold">COMPROBANTE DE DEVOLUCIÓN</div>

<table>
    <tr><td>Devolución</td><td class="right bold">{{ $return->number }}</td></tr>
    <tr><td>Fecha</td><td class="right">{{ ($return->resolved_at ?? $return->rejected_at ?? $return->created_at)->format('d/m/Y h:i a') }}</td></tr>
    <tr><td>Venta</td><td class="right">{{ $return->sale?->number }}</td></tr>
    @if ($return->order)
        <tr><td>Pedido</td><td class="right">#{{ $return->order->number }}</td></tr>
    @endif
    @if ($return->customer)
        <tr><td>Cliente</td><td class="right">{{ $return->customer->name }}</td></tr>
        <tr><td>Documento</td><td class="right">{{ $return->customer->document_number }}</td></tr>
    @endif
    <tr><td>Atendió</td><td class="right">{{ ($return->resolvedBy ?? $return->rejectedBy ?? $return->createdBy)?->name }}</td></tr>
    <tr><td>Estado</td><td class="right">{{ $status }}</td></tr>
</table>

<hr>

<table>
    @foreach ($lines as $line)
        <tr><td colspan="2" class="name">{{ $line['description'] }}</td></tr>
        <tr>
            <td class="small">{{ $line['quantity'] }} · {{ $line['reason'] }}{{ $line['disposition'] ? ' · '.$line['disposition'] : '' }}</td>
            <td class="right">{{ number_format($line['line_total'], 0, ',', '.') }}</td>
        </tr>
        @foreach ($line['components'] as $component)
            <tr>
                <td class="small" style="padding-left: 8px;">· {{ $component['quantity'] }} x {{ $component['description'] }}{{ $component['disposition'] ? ' ('.$component['disposition'].')' : '' }}</td>
                <td class="right small">{{ $component['line_total'] != 0 ? number_format($component['line_total'], 0, ',', '.') : 'incluido' }}</td>
            </tr>
        @endforeach
    @endforeach
</table>

<hr>

@if ($return->status === \App\Models\CustomerReturn::STATUS_REJECTED)
    <div class="bold">NO PROCEDE</div>
    <div class="small">{{ $return->reject_reason }}</div>
@else
    <table>
        <tr><td class="bold big">VALOR</td><td class="right bold big">$ {{ number_format($return->total, 0, ',', '.') }}</td></tr>
        @if ($return->debt_relief_total > 0)
            <tr><td>Descontado de la cartera</td><td class="right">{{ number_format($return->debt_relief_total, 0, ',', '.') }}</td></tr>
        @endif
        @if ($return->refunded_total > 0)
            <tr><td>Devuelto en {{ mb_strtolower($refund_method ?? '') }}</td><td class="right">{{ number_format($return->refunded_total, 0, ',', '.') }}</td></tr>
        @endif
        @if ($return->credited_total > 0)
            <tr><td>Saldo a favor abonado</td><td class="right">{{ number_format($return->credited_total, 0, ',', '.') }}</td></tr>
        @endif
        @if ($return->exchanged_total > 0)
            <tr><td>Aplicado al cambio{{ $return->exchangeSale ? ' ('.$return->exchangeSale->number.')' : '' }}</td><td class="right">{{ number_format($return->exchanged_total, 0, ',', '.') }}</td></tr>
        @endif
    </table>

    @if ($store_credit_balance !== null)
        <hr>
        <div class="small">Saldo a favor disponible: $ {{ number_format($store_credit_balance, 0, ',', '.') }}. Úsalo en caja o en la tienda en línea.</div>
    @endif
@endif

@if ($return->out_of_window)
    <hr>
    <div class="small">Fuera de plazo, autorizó {{ $return->windowApprovedBy?->name }}.</div>
@endif

<hr>

<div class="center small">
    <div>Firma del cliente</div>
    <div style="margin-top: 22px; border-top: 1px solid #000;"></div>
    <div style="margin-top:6px" class="bold">{{ $return->number }}</div>
</div>

<div class="toolbar">
    <button type="button" onclick="window.print()">Imprimir</button>
    <button type="button" class="ghost" onclick="window.close()">Cerrar</button>
</div>

<script>
    window.addEventListener('load', () => window.setTimeout(() => window.print(), 250));
</script>
</body>
</html>
