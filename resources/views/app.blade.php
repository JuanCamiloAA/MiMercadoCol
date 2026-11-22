<!DOCTYPE html>
@php
    // La marca del panel y la caja llega en la prop compartida `brand`. En la tienda en linea
    // es null: alli manda `storeTheme` y aqui no se inyecta nada.
    $brand = $page['props']['brand'] ?? null;
    $surface = \App\Support\Surface::for(request());
    $surfaceConfig = $brand['surfaces'][$surface] ?? null;
    $defaultTheme = $surfaceConfig['default_theme'] ?? ($surface === 'pos' ? 'dark' : 'light');
    $themeToggle = $surfaceConfig['allow_theme_toggle'] ?? true;
    // En la tienda el esquema lo decide su editor; el cliente solo lo cambia si la sede lo permite.
    $storeTheme = $page['props']['storeTheme'] ?? null;
    if ($surface === 'store' && is_array($storeTheme)) {
        $defaultTheme = match ($storeTheme['scheme'] ?? 'light') { 'dark' => 'dark', 'auto' => 'system', default => 'light' };
        $themeToggle = (bool) ($storeTheme['allow_scheme_toggle'] ?? false);
    }
    // La landing, la contratacion y los textos legales nacen claros y respetan la eleccion del
    // visitante, sin importar los ajustes del panel; la vista previa del editor fija el modo que
    // revisa. Igual que en sus paginas de React: si no coinciden, el tema parpadea al cargar.
    if (request()->routeIs('home', 'legal.*', 'signup.*')) {
        $defaultTheme = 'light';
        $themeToggle = true;
    } elseif (request()->routeIs('platform.landing.preview') && in_array(request()->query('scheme'), ['light', 'dark'], true)) {
        $defaultTheme = request()->query('scheme');
        $themeToggle = false;
    }
    $factoryFonts = 'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap';
    $factoryFavicon = app(\App\Services\BrandThemeService::class)->defaultFavicon(null);
@endphp
{{-- El tema inicial sale de los ajustes de cada superficie; el guion de abajo respeta la eleccion de quien mira. --}}
<html
    lang="es-CO"
    data-surface="{{ $surface }}"
    data-theme="{{ $defaultTheme === 'dark' ? 'dark' : 'light' }}"
    data-theme-default="{{ $defaultTheme }}"
    data-theme-locked="{{ $themeToggle ? '0' : '1' }}"
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="{{ $brand['theme_color'] ?? '#0E6E4E' }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ $brand['display_name'] ?? config('app.name', 'AgoraShop') }}</title>

        <link rel="icon" id="brand-favicon" href="{{ $brand['favicon_url'] ?? $factoryFavicon }}">
        @if (! empty($brand['touch_icon_url']))
            <link rel="apple-touch-icon" id="brand-touch-icon" href="{{ $brand['touch_icon_url'] }}">
        @endif
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link id="brand-fonts" href="{{ $brand['fonts_href'] ?? $factoryFonts }}" rel="stylesheet">

        {{-- El tema se aplica antes de pintar: si no, la pantalla parpadea en claro al recargar. --}}
        <script>
            (function () {
                var root = document.documentElement;
                var fallback = root.getAttribute('data-theme-default');
                // Con el interruptor oculto por la empresa, la eleccion guardada no se aplica,
                // pero tampoco se borra: si vuelve el interruptor, vuelve la eleccion.
                var locked = root.getAttribute('data-theme-locked') === '1';
                var stored = null;

                try {
                    stored = locked ? null : window.localStorage.getItem('lacanasta.theme');
                } catch (error) {
                    // Sin almacenamiento se queda con el tema propio de la pantalla.
                }

                var preference = stored === 'light' || stored === 'dark' || stored === 'system' ? stored : fallback;
                var dark = preference === 'dark'
                    || (preference === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

                root.setAttribute('data-theme', dark ? 'dark' : 'light');
            })();
        </script>

        @routes
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        {{-- DESPUES de @vite: mismos selectores que app.css, gana el que va de ultimas. Solo
             lleva valores validados (hex por regex, listas cerradas), por eso va sin escapar. --}}
        <style id="brand-theme">{!! $brand['css'] ?? '' !!}</style>
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
