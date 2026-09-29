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
    </style>
</head>
<body>
@php
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $cop = fn ($value) => '$ '.number_format((int) $value, 0, ',', '.');
@endphp
<div class="head">
    <h1>Devolución a proveedor {{ $return->number }}</h1>
    <div class="muted">
        {{ $brandName ?: $company?->name }}@if ($company?->legal_name) · {{ $company->legal_name }}@endif · NIT {{ $company?->tax_id }}<br>
        Sede {{ $return->store?->name }} · sale de {{ $return->warehouse?->name }}
    </div>
</div>

<table>
    <tr>
        <td class="muted">Proveedor</td>
        <td><strong>{{ $return->supplier?->name }}</strong>@if ($return->supplier?->tax_id) · NIT {{ $return->supplier->tax_id }}@endif</td>
        <td class="muted">Fecha</td>
        <td class="right num">{{ ($return->dispatched_at ?? $return->created_at)?->format('d/m/Y h:i a') }}</td>
    </tr>
    <tr>
        <td class="muted">Orden de compra</td>
        <td>{{ $return->purchaseOrder?->code ?? 'Sin orden' }}</td>
        <td class="muted">Estado</td>
        <td class="right">{{ $return->statusLabel() }}</td>
    </tr>
    <tr>
        <td class="muted">Guía o transportadora</td>
        <td>{{ $return->shipping_reference ?: '—' }}</td>
        <td class="muted">Despachó</td>
        <td class="right">{{ $return->dispatcher?->full_name ?? '—' }}</td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th>Artículo</th>
            <th>SKU</th>
            <th>Lote</th>
            <th>Vence</th>
            <th class="right">Cantidad</th>
            <th>Motivo</th>
            <th class="right">Costo</th>
            <th class="right">Subtotal</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($return->items as $item)
            @php
                $lots = collect($item->lots ?? []);
                if ($lots->isEmpty() && $item->lot) {
                    $lots = collect([['code' => $item->lot->code, 'expires_at' => $item->lot->expires_at?->toDateString()]]);
                }
            @endphp
            <tr>
                <td>
                    {{ $item->product?->name }}
                    @if ($item->note)<br><span class="muted">{{ $item->note }}</span>@endif
                </td>
                <td class="num">{{ $item->product?->sku }}</td>
                <td>{{ $lots->pluck('code')->filter()->implode(', ') ?: '—' }}</td>
                <td class="num">{{ $lots->pluck('expires_at')->filter()->map(fn ($date) => \Illuminate\Support\Carbon::parse($date)->format('d/m/Y'))->implode(', ') ?: '—' }}</td>
                <td class="right num">{{ $qty($item->quantity) }}</td>
                <td>{{ $item->reasonLabel() }}</td>
                <td class="right num">{{ $cop($item->unit_cost) }}</td>
                <td class="right num">{{ $cop($item->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="box">
    <table>
        <tr>
            <td>Base</td>
            <td class="right num">{{ $cop($return->subtotal) }}</td>
        </tr>
        <tr>
            <td>IVA</td>
            <td class="right num">{{ $cop($return->tax_total) }}</td>
        </tr>
        <tr>
            <td><strong>Total a favor</strong></td>
            <td class="right num total">{{ $cop($return->total) }}</td>
        </tr>
    </table>
</div>

@if ($return->note)
    <p class="muted">Nota: {{ $return->note }}</p>
@endif

<table class="sign">
    <tr>
        <td><div class="line">Entrega: {{ $brandName ?: $company?->name }}</div></td>
        <td><div class="line">Recibido por (proveedor o transportador) · nombre, documento y fecha</div></td>
    </tr>
</table>
</body>
</html>
