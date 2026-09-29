<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #14171A; }
        h1 { font-size: 16pt; margin: 0 0 2px; }
        h2 { font-size: 11pt; margin: 14px 0 6px; }
        .muted { color: #565E64; font-size: 8.5pt; }
        .head { border-bottom: 2px solid #0E6E4E; padding-bottom: 8px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #565E64; border-bottom: 1px solid #D8D8D0; padding: 4px 0; }
        td { padding: 4px 0; border-bottom: 1px solid #ECECE7; }
        .right { text-align: right; }
        .num { font-family: DejaVu Sans Mono, monospace; }
        .cards td { border: 1px solid #D8D8D0; padding: 8px 10px; border-radius: 6px; width: 25%; }
        .value { font-size: 13pt; font-weight: bold; color: #0E6E4E; }
    </style>
</head>
<body>
<div class="head">
    <h1>Informe de ventas</h1>
    <div class="muted">
        {{ $company?->legal_name ?: ($company ? app(\App\Services\SettingsService::class)->get($company->id, 'brand.display_name') : config('app.name')) }} · NIT {{ $company?->tax_id }}<br>
        Del {{ $from->format('d/m/Y') }} al {{ $to->format('d/m/Y') }}
        @if ($store) · Sede {{ $store->name }} @else · Todas las sedes @endif
    </div>
</div>

<table class="cards">
    <tr>
        <td>
            <div class="muted">Ventas</div>
            <div class="value num">$ {{ number_format($data['summary']['total'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="muted">Tiquetes</div>
            <div class="value num">{{ number_format($data['summary']['tickets'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="muted">Tiquete promedio</div>
            <div class="value num">$ {{ number_format($data['summary']['average_ticket'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="muted">Margen</div>
            <div class="value num">$ {{ number_format($data['summary']['margin'], 0, ',', '.') }}</div>
        </td>
    </tr>
</table>

<h2>Ventas por medio de pago</h2>
<table>
    <thead><tr><th>Medio</th><th class="right">Movimientos</th><th class="right">Total</th></tr></thead>
    <tbody>
    @foreach ($data['by_method'] as $row)
        <tr>
            <td>{{ $row['label'] }}</td>
            <td class="right num">{{ $row['count'] }}</td>
            <td class="right num">$ {{ number_format($row['total'], 0, ',', '.') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Ventas por categoria</h2>
<table>
    <thead><tr><th>Categoria</th><th class="right">Unidades</th><th class="right">Total</th></tr></thead>
    <tbody>
    @foreach ($data['by_category'] as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td class="right num">{{ number_format($row['quantity'], 2, ',', '.') }}</td>
            <td class="right num">$ {{ number_format($row['total'], 0, ',', '.') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Ventas por empleado</h2>
<table>
    <thead><tr><th>Empleado</th><th class="right">Tiquetes</th><th class="right">Total</th></tr></thead>
    <tbody>
    @foreach ($data['by_employee'] as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td class="right num">{{ $row['tickets'] }}</td>
            <td class="right num">$ {{ number_format($row['total'], 0, ',', '.') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Articulos mas vendidos</h2>
<table>
    <thead><tr><th>Articulo</th><th class="right">Unidades</th><th class="right">Total</th></tr></thead>
    <tbody>
    @foreach ($data['top_products'] as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td class="right num">{{ number_format($row['quantity'], 2, ',', '.') }}</td>
            <td class="right num">$ {{ number_format($row['total'], 0, ',', '.') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<p class="muted" style="margin-top:14px">Generado el {{ now()->format('d/m/Y h:i a') }}</p>
</body>
</html>
