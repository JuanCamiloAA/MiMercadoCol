<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #14171A; }
        h1 { font-size: 15pt; margin: 0 0 2px; }
        .muted { color: #565E64; font-size: 8.5pt; }
        .head { border-bottom: 2px solid #0E6E4E; padding-bottom: 8px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th { text-align: left; font-size: 7.5pt; text-transform: uppercase; color: #565E64; border-bottom: 1px solid #D8D8D0; padding: 4px 3px; }
        td { padding: 4px 3px; border-bottom: 1px solid #ECECE7; vertical-align: top; }
        .right { text-align: right; }
        .num { font-family: DejaVu Sans Mono, monospace; }
        .total { font-size: 12pt; font-weight: bold; color: #0E6E4E; }
        .box { border: 1px solid #D8D8D0; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; }
        .sign { margin-top: 40px; }
        .sign td { border: none; width: 50%; padding-top: 36px; }
        .line { border-top: 1px solid #14171A; padding-top: 4px; }
        .sub td { color: #565E64; font-size: 8.5pt; }
    </style>
</head>
<body>
@php
    $cop = fn ($value) => '$ '.number_format((int) $value, 0, ',', '.');
@endphp
<div class="head">
    <h1>Devolución de venta {{ $return->number }}</h1>
    <div class="muted">
        {{ $headline }}@if ($company?->legal_name) · {{ $company->legal_name }}@endif · NIT {{ $company?->tax_id }}<br>
        Sede {{ $return->store?->name }} · {{ $origin }}
    </div>
</div>

<table>
    <tr>
        <td class="muted">Cliente</td>
        <td><strong>{{ $return->customer?->name ?? 'Consumidor final' }}</strong>@if ($return->customer?->document_number) · {{ $return->customer->document_number }}@endif</td>
        <td class="muted">Fecha</td>
        <td class="right num">{{ ($return->resolved_at ?? $return->rejected_at ?? $return->created_at)?->format('d/m/Y h:i a') }}</td>
    </tr>
    <tr>
        <td class="muted">Venta</td>
        <td>{{ $return->sale?->number }}@if ($return->order) · pedido #{{ $return->order->number }}@endif</td>
        <td class="muted">Estado</td>
        <td class="right">{{ $status }}</td>
    </tr>
    @if ($return->reason || $return->customer_note)
        <tr>
            <td class="muted">Motivo</td>
            <td colspan="3">{{ $return->reason }}@if ($return->customer_note) {{ $return->reason ? '·' : '' }} El cliente dice: «{{ $return->customer_note }}»@endif</td>
        </tr>
    @endif
</table>

<table>
    <thead>
        <tr>
            <th>Artículo</th>
            <th class="right">Cantidad</th>
            <th>Motivo</th>
            <th>Destino</th>
            <th class="right">Valor</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="right num">{{ $line['quantity'] }}</td>
                <td>{{ $line['reason'] }}</td>
                <td>{{ $line['disposition'] ?? '—' }}</td>
                <td class="right num">{{ $cop($line['line_total']) }}</td>
            </tr>
            @foreach ($line['components'] as $component)
                <tr class="sub">
                    <td style="padding-left: 14px;">· {{ $component['description'] }}</td>
                    <td class="right num">{{ $component['quantity'] }}</td>
                    <td></td>
                    <td>{{ $component['disposition'] ?? '—' }}</td>
                    <td class="right num">{{ $component['line_total'] != 0 ? $cop($component['line_total']) : 'incluido' }}</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
</table>

@if ($return->status === \App\Models\CustomerReturn::STATUS_REJECTED)
    <div class="box"><strong>No procede.</strong> {{ $return->reject_reason }}</div>
@else
    <table style="width: 55%; margin-left: 45%;">
        <tr><td>Valor devuelto</td><td class="right num total">{{ $cop($return->total) }}</td></tr>
        @if ($return->debt_relief_total > 0)
            <tr><td>Descontado de la cartera</td><td class="right num">{{ $cop($return->debt_relief_total) }}</td></tr>
        @endif
        @if ($return->refunded_total > 0)
            <tr><td>Reembolsado ({{ mb_strtolower($refund_method ?? '') }})</td><td class="right num">{{ $cop($return->refunded_total) }}</td></tr>
        @endif
        @if ($return->credited_total > 0)
            <tr><td>Saldo a favor abonado</td><td class="right num">{{ $cop($return->credited_total) }}</td></tr>
        @endif
        @if ($return->exchanged_total > 0)
            <tr><td>Aplicado al cambio {{ $return->exchangeSale?->number }}</td><td class="right num">{{ $cop($return->exchanged_total) }}</td></tr>
        @endif
        @if ($resolution)
            <tr><td class="muted">Resolución</td><td class="right">{{ $resolution }}</td></tr>
        @endif
    </table>

    @if ($store_credit_balance !== null)
        <div class="box">Saldo a favor disponible del cliente: <strong>{{ $cop($store_credit_balance) }}</strong>. Se usa como medio de pago en caja y en la tienda en línea.</div>
    @endif
@endif

@if ($return->out_of_window)
    <div class="muted">Registrada fuera del plazo de devolución; autorizó {{ $return->windowApprovedBy?->full_name }}.</div>
@endif

<table class="sign">
    <tr>
        <td><div class="line">Recibe por la tienda — {{ ($return->resolvedBy ?? $return->receivedBy ?? $return->createdBy)?->full_name }}</div></td>
        <td><div class="line">Cliente</div></td>
    </tr>
</table>
</body>
</html>
