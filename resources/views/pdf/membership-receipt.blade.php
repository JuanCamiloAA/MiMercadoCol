<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #14171A; }
        h1 { font-size: 16pt; margin: 0 0 2px; }
        .muted { color: #565E64; font-size: 8.5pt; }
        .head { border-bottom: 2px solid #0E6E4E; padding-bottom: 8px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #565E64; border-bottom: 1px solid #D8D8D0; padding: 5px 0; }
        td { padding: 6px 0; border-bottom: 1px solid #ECECE7; vertical-align: top; }
        .right { text-align: right; }
        .num { font-family: DejaVu Sans Mono, monospace; }
        .total { font-size: 14pt; font-weight: bold; color: #0E6E4E; }
        .box { border: 1px solid #D8D8D0; border-radius: 6px; padding: 8px 10px; margin-bottom: 12px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #E3F1EA; color: #0E6E4E; font-size: 8.5pt; font-weight: bold; }
    </style>
</head>
<body>
@php
    $fmt = fn ($value) => '$ '.number_format((int) $value, 0, ',', '.');
    $date = fn ($value) => $value?->timezone('America/Bogota')->format('d/m/Y');
@endphp

<div class="head">
    <h1>Comprobante de pago</h1>
    <div class="muted">{{ $platformName }} · Membresía de la plataforma</div>
</div>

<table>
    <tr>
        <td style="width: 50%">
            <div class="muted">Empresa</div>
            <strong>{{ $company->legal_name ?: $company->name }}</strong><br>
            NIT {{ $company->tax_id }}<br>
            {{ $company->email }}
        </td>
        <td class="right">
            <div class="muted">Referencia</div>
            <span class="num">{{ $charge->reference }}</span><br>
            <span class="muted">Pagado el</span> {{ $date($charge->paid_at) }}<br>
            <span class="badge">{{ $charge->statusLabel() }}</span>
        </td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th>Concepto</th>
            <th>Periodo</th>
            <th class="right">Valor</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $charge->concept }}</td>
            <td>{{ $date($charge->period_starts_at) }} al {{ $date($charge->period_ends_at) }}</td>
            <td class="right num">{{ $fmt($charge->amount) }}</td>
        </tr>
    </tbody>
</table>

<table>
    <tr>
        <td class="right" style="border: 0">Total pagado ({{ $charge->currency }})</td>
        <td class="right total num" style="border: 0; width: 30%">{{ $fmt($charge->amount) }}</td>
    </tr>
</table>

<div class="box muted">
    Medio: {{ \App\Models\MembershipCharge::METHOD_LABELS[$charge->method] ?? $charge->method }}
    @if ($charge->gateway_transaction_id)
        · Transacción {{ $charge->gateway_transaction_id }}
    @endif
    <br>
    Este comprobante certifica el pago de la membresía. No es una factura electrónica.
    @if ($supportEmail)
        Dudas: {{ $supportEmail }}.
    @endif
</div>
</body>
</html>
