<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #14171A; }
        .grid { width: 100%; }
        .label {
            width: 31%;
            display: inline-block;
            vertical-align: top;
            border: 1px solid #C3C3B9;
            border-radius: 6px;
            padding: 8px 10px;
            margin: 0 1% 8px 0;
            height: 90px;
        }
        .name { font-size: 9.5pt; font-weight: bold; line-height: 1.15; height: 26px; overflow: hidden; }
        .brand { font-size: 7.5pt; color: #565E64; }
        .price { font-size: 17pt; font-weight: bold; color: #0E6E4E; margin-top: 4px; }
        .meta { font-size: 7pt; color: #565E64; margin-top: 2px; }
    </style>
</head>
<body>
<div class="grid">
    @foreach ($products as $product)
        <div class="label">
            <div class="name">{{ $product->name }}</div>
            <div class="brand">{{ $product->brand }}</div>
            <div class="price">$ {{ number_format($product->price, 0, ',', '.') }}</div>
            <div class="meta">{{ $product->sku }} · {{ $product->barcode }} · IVA {{ (int) $product->tax_rate }}%</div>
        </div>
    @endforeach
</div>
</body>
</html>
