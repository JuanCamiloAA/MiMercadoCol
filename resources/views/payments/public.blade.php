<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Estado del pago</title>
    {{-- Pagina suelta y sin Vite: la abre el celular del cliente despues de pagar un QR de la caja. --}}
    <style>
        :root { color-scheme: light dark; --bg: #f6f7f5; --card: #ffffff; --text: #1d2520; --muted: #5c665f; --ok: #0e6e4e; --warn: #8f4705; --bad: #b3261e; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111513; --card: #1a201c; --text: #e7ece8; --muted: #a2ada5; --ok: #5bc79a; --warn: #f0b36b; --bad: #f2918a; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { margin: 1rem; max-width: 26rem; width: calc(100% - 2rem); background: var(--card); border-radius: 1rem; padding: 2rem 1.5rem; text-align: center; box-shadow: 0 10px 30px rgba(0, 0, 0, .08); }
        .amount { font-size: 1.75rem; font-weight: 700; font-variant-numeric: tabular-nums; margin: .25rem 0 1rem; }
        .status { font-size: 1rem; line-height: 1.5; margin: 0; }
        .approved { color: var(--ok); } .pending { color: var(--warn); } .other { color: var(--bad); }
    </style>
    @if ($status === 'pending')
        <meta http-equiv="refresh" content="5">
    @endif
</head>
<body>
    <main>
        <p class="amount">$ {{ number_format($amount, 0, ',', '.') }}</p>
        <p class="status {{ $status === 'approved' ? 'approved' : ($status === 'pending' ? 'pending' : 'other') }}">{{ $label }}</p>
    </main>
</body>
</html>
