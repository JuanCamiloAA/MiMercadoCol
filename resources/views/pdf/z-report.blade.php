<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #14171A; }
        h1 { font-size: 15pt; margin: 0 0 2px; }
        .muted { color: #565E64; font-size: 8.5pt; }
        .head { border-bottom: 2px solid #0E6E4E; padding-bottom: 8px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #565E64; border-bottom: 1px solid #D8D8D0; padding: 4px 0; }
        td { padding: 4px 0; border-bottom: 1px solid #ECECE7; }
        .right { text-align: right; }
        .num { font-family: DejaVu Sans Mono, monospace; }
        .total { font-size: 13pt; font-weight: bold; color: #0E6E4E; }
        .diff-ok { color: #0E6E4E; font-weight: bold; }
        .diff-bad { color: #A81F16; font-weight: bold; }
        .box { border: 1px solid #D8D8D0; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="head">
    <h1>Informe Z · Cierre de caja</h1>
    <div class="muted">
        {{ $company?->legal_name ?: ($company ? app(\App\Services\SettingsService::class)->get($company->id, 'brand.display_name') : config('app.name')) }} · NIT {{ $company?->tax_id }}<br>
        Sede {{ $session->store?->name }} · {{ $session->register?->name }}
    </div>
</div>

<table>
    <tr>
        <td class="muted">Cajero</td>
        <td class="right">{{ $session->user?->full_name }}</td>
        <td class="muted">Apertura</td>
        <td class="right num">{{ $session->opened_at?->format('d/m/Y h:i a') }}</td>
    </tr>
    <tr>
        <td class="muted">Turno</td>
        <td class="right num">#{{ $session->id }}</td>
        <td class="muted">Cierre</td>
        <td class="right num">{{ $session->closed_at?->format('d/m/Y h:i a') }}</td>
    </tr>
</table>

<div class="box">
    <table>
        <tr>
            <td>Base inicial</td>
            <td class="right num">$ {{ number_format($session->opening_amount, 0, ',', '.') }}</td>
        </tr>
        @foreach ($summary['payments_by_method'] as $method)
            <tr>
                <td>Ventas en {{ mb_strtolower($method['label']) }} ({{ $method['count'] }})</td>
                <td class="right num">$ {{ number_format($method['total'], 0, ',', '.') }}</td>
            </tr>
        @endforeach
        <tr>
            <td>Abonos de cartera en efectivo</td>
            <td class="right num">$ {{ number_format($summary['customer_payments_cash'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Ingresos de efectivo</td>
            <td class="right num">$ {{ number_format($summary['cash_in'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Sangrias</td>
            <td class="right num">− $ {{ number_format($summary['cash_out'], 0, ',', '.') }}</td>
        </tr>
    </table>
</div>

<table>
    <tr>
        <td><strong>Efectivo esperado</strong></td>
        <td class="right num total">$ {{ number_format($session->expected_amount, 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td><strong>Efectivo contado</strong></td>
        <td class="right num total">$ {{ number_format($session->counted_amount, 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td><strong>Diferencia</strong></td>
        <td class="right num {{ (int) $session->difference === 0 ? 'diff-ok' : 'diff-bad' }}">
            $ {{ number_format($session->difference, 0, ',', '.') }}
        </td>
    </tr>
</table>

@if ($session->closing_note)
    <div class="box">
        <div class="muted">Observacion del cierre</div>
        {{ $session->closing_note }}
    </div>
@endif

@if (count($summary['movements']) > 0)
    <table>
        <thead>
        <tr>
            <th>Hora</th>
            <th>Movimiento</th>
            <th>Motivo</th>
            <th class="right">Valor</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($summary['movements'] as $movement)
            <tr>
                <td class="num">{{ \Carbon\Carbon::parse($movement['occurred_at'])->format('h:i a') }}</td>
                <td>{{ $movement['type'] === 'in' ? 'Ingreso' : 'Sangria' }}</td>
                <td>{{ $movement['reason'] }}</td>
                <td class="right num">$ {{ number_format($movement['amount'], 0, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<div class="muted">
    Tiquetes del turno: {{ $summary['sales_count'] }} · Venta total: $ {{ number_format($summary['sales_total'], 0, ',', '.') }}<br>
    Generado el {{ now()->format('d/m/Y h:i a') }}
</div>
</body>
</html>
