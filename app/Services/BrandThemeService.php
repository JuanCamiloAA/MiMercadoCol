<?php

namespace App\Services;

use App\Support\Color;
use App\Support\Uploads;

/**
 * De los ajustes de marca a lo que necesitan el blade y el front: nombre, imagenes, tokens de
 * color, tipografias y el CSS que se inyecta en `#brand-theme`.
 *
 * Dos reglas que no se negocian:
 *  - Si la empresa no toco nada, el CSS sale vacio y manda `app.css`. El verde oscuro de
 *    fabrica (#2FBE7E) no sale de ninguna formula y no se debe «recalcular».
 *  - El CSS se arma solo con valores validados (hex por regex, enums de listas cerradas). Nunca
 *    se interpola texto libre, porque el blade lo imprime con `{!! !!}`.
 */
class BrandThemeService
{
    public const FACTORY_ACCENT = '#0E6E4E';

    /** Los fondos donde la interfaz pone el acento como texto, por tema. */
    public const LIGHT = ['surface' => '#FFFFFF', 'bg' => '#F4F4F1'];

    public const DARK = ['surface' => '#151A1D', 'bg' => '#0C1012'];

    /** Cuanto acento lleva el tinte suave (el item activo del menu) en cada tema. */
    public const SOFT_WEIGHT = ['light' => 0.12, 'dark' => 0.20];

    /** Contraste minimo del acento usado como texto. */
    public const MIN_CONTRAST = 4.5;

    /** chip, control, card y sheet, en px. `soft` son los de hoy. */
    public const RADII = [
        'sharp' => [2, 3, 4, 6],
        'soft' => [8, 10, 14, 20],
        'round' => [12, 16, 20, 28],
    ];

    public const CONTENT_WIDTHS = ['centered' => '75.125rem', 'wide' => '90rem', 'full' => 'none'];

    public const DENSITIES = ['cozy' => 1, 'compact' => 0.8];

    /**
     * Los tokens de color de `app.css` para la marca de fabrica. La vista previa de Ajustes los
     * necesita para mostrar «de fabrica» aunque la empresa ya tenga otro color guardado. Si
     * cambias uno en `app.css`, cambialo aqui.
     */
    public const FACTORY_TOKENS = [
        'light' => ['accent' => '#0E6E4E', 'ink' => '#FFFFFF', 'soft' => '#DCEDE4', 'muted' => '#CFE2DC', 'mark' => '#FFFFFF'],
        'dark' => ['accent' => '#2FBE7E', 'ink' => '#052018', 'soft' => '#123029', 'muted' => '#0D402C', 'mark' => '#FFFFFF'],
    ];

    /**
     * Lo que la vista previa de Ajustes necesita para pintar un borrador igual que el CSS real.
     *
     * @return array<string, mixed>
     */
    public function previewTokens(): array
    {
        return [
            'factory_accent' => self::FACTORY_ACCENT,
            'factory' => self::FACTORY_TOKENS,
            'radii' => self::RADII,
            'densities' => self::DENSITIES,
            'content_widths' => self::CONTENT_WIDTHS,
        ];
    }

    public function __construct(protected SettingsService $settings) {}

    /**
     * La marca de una empresa, o la neutra (nombre de la aplicacion y colores de fabrica)
     * cuando no hay empresa: el consolidado del super administrador o un ingreso con varias
     * empresas activas.
     *
     * @return array<string, mixed>
     */
    public function forCompany(?int $companyId): array
    {
        $settings = $companyId !== null ? $this->settings->all($companyId) : $this->platformSettings();
        $mark = $this->asset($settings['brand.mark'] ?? null);

        return [
            'display_name' => (string) $settings['brand.display_name'],
            'tagline' => $companyId !== null ? ($settings['brand.tagline'] ?: null) : null,
            'mark_url' => $this->url($mark['path'] ?? null),
            'logo_light_url' => $this->url($this->asset($settings['brand.logo_light'] ?? null)['path'] ?? null),
            'logo_dark_url' => $this->url($this->asset($settings['brand.logo_dark'] ?? null)['path'] ?? null),
            'favicon_url' => $this->url($mark['favicon'] ?? null) ?? $this->defaultFavicon($settings['brand.accent'] ?? null),
            'touch_icon_url' => $this->url($mark['touch'] ?? null),
            'theme_color' => Color::isHex($settings['brand.accent'] ?? null) ? strtoupper($settings['brand.accent']) : self::FACTORY_ACCENT,
            'ink' => $this->inks($settings),
            'css' => $this->css($settings),
            'fonts_href' => $this->fontsHref($settings),
            'surfaces' => [
                'panel' => [
                    'default_theme' => $settings['panel.default_theme'],
                    'allow_theme_toggle' => (bool) $settings['panel.allow_theme_toggle'],
                    'sidebar_style' => $settings['panel.sidebar_style'],
                    'sidebar_mode' => $settings['panel.sidebar_mode'],
                ],
                'pos' => [
                    'default_theme' => $settings['pos.default_theme'],
                    'allow_theme_toggle' => (bool) $settings['pos.allow_theme_toggle'],
                ],
            ],
            'navigation' => is_array($settings['panel.navigation'] ?? null) && $settings['panel.navigation'] !== []
                ? $settings['panel.navigation']
                : null,
        ];
    }

    /**
     * Sin empresa (el ingreso con varias empresas, el consolidado del super usuario, la landing)
     * manda la marca de plataforma: nombre, logos, isotipo, favicon, color y fuentes de
     * Plataforma > Landing. Va solo por aqui: una empresa sin logo no la hereda.
     *
     * @return array<string, mixed>
     */
    protected function platformSettings(): array
    {
        $platform = app(PlatformSettingsService::class);
        $accent = $platform->get('platform.brand_accent');
        $path = function (string $key) use ($platform): ?string {
            $value = $platform->get($key);

            return is_string($value) && $value !== '' ? $value : null;
        };
        $logo = $path('platform.brand_logo');
        $logoDark = $path('platform.brand_logo_dark');
        $mark = $path('platform.brand_mark');
        $favicon = $path('landing.favicon') ?? $mark;

        return array_merge(SettingsService::DEFAULTS, array_filter([
            'brand.display_name' => $platform->name(),
            'brand.accent' => Color::isHex($accent) ? strtoupper((string) $accent) : null,
            'brand.logo_light' => $logo ? ['path' => $logo] : null,
            'brand.logo_dark' => $logoDark ? ['path' => $logoDark] : null,
            // El isotipo cuadrado sirve tambien de icono de iOS; la pestaña lleva el favicon.
            'brand.mark' => $favicon !== null ? array_filter(['path' => $mark, 'favicon' => $favicon, 'touch' => $mark]) : null,
            'appearance.font_body' => $platform->get('platform.font_body'),
            'appearance.font_display' => $platform->get('platform.font_display'),
            'appearance.font_numeric' => $platform->get('platform.font_numeric'),
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * El acento de cada tema, ya ajustado, con su letra, su tinte y el gris del menu de marca.
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public function palette(string $accent, ?string $accentDark = null): array
    {
        $accent = Color::normalize($accent);
        $light = $this->resolveAccent($accent, self::LIGHT, '#000000', self::SOFT_WEIGHT['light']);
        $dark = $accentDark !== null && Color::isHex($accentDark)
            ? Color::normalize($accentDark)
            : $this->resolveAccent($accent, self::DARK, '#FFFFFF', self::SOFT_WEIGHT['dark']);

        return [
            'light' => $this->tokens($light, self::LIGHT, self::SOFT_WEIGHT['light']),
            'dark' => $this->tokens($dark, self::DARK, self::SOFT_WEIGHT['dark']),
        ];
    }

    /**
     * Acerca el color de marca a negro (tema claro) o a blanco (tema oscuro) en pasos de 5 %
     * hasta que se lea como texto sobre todos los fondos donde la interfaz lo pone: la
     * superficie, el fondo de la pagina y su propio tinte suave (el item activo del menu).
     *
     * @param  array{surface: string, bg: string}  $palette
     */
    public function resolveAccent(string $accent, array $palette, string $toward, float $softWeight): string
    {
        for ($step = 0; $step <= 20; $step++) {
            $candidate = Color::mix($accent, $toward, $step / 20);

            if ($this->worstContrast($candidate, $palette, $softWeight) >= self::MIN_CONTRAST) {
                return $candidate;
            }
        }

        return Color::normalize($toward);
    }

    /**
     * El peor contraste del acento como texto: contra la superficie, el fondo y su tinte.
     *
     * @param  array{surface: string, bg: string}  $palette
     */
    public function worstContrast(string $accent, array $palette, float $softWeight): float
    {
        $soft = Color::mix($palette['surface'], $accent, $softWeight);

        return min(
            Color::contrast($accent, $palette['surface']),
            Color::contrast($accent, $palette['bg']),
            Color::contrast($accent, $soft),
        );
    }

    /**
     * La letra que va sobre el acento en cada tema. El front la usa para saber si el menu del
     * color de la marca es oscuro y escoger el logo que se lee encima.
     *
     * @param  array<string, mixed>  $settings
     * @return array{light: string, dark: string}
     */
    public function inks(array $settings): array
    {
        $accent = Color::isHex($settings['brand.accent'] ?? null) ? Color::normalize($settings['brand.accent']) : self::FACTORY_ACCENT;
        $accentDark = Color::isHex($settings['brand.accent_dark'] ?? null) ? $settings['brand.accent_dark'] : null;

        // Los de app.css: el verde oscuro de fabrica lleva letra oscura.
        $factory = ['light' => '#FFFFFF', 'dark' => '#052018'];

        if ($accent === self::FACTORY_ACCENT && $accentDark === null) {
            return $factory;
        }

        $palette = $this->palette($accent, $accentDark);

        return [
            'light' => $accent === self::FACTORY_ACCENT ? $factory['light'] : $palette['light']['ink'],
            'dark' => $palette['dark']['ink'],
        ];
    }

    /**
     * El CSS que se inyecta despues de `app.css`. Vacio si todo es de fabrica.
     *
     * @param  array<string, mixed>  $settings
     */
    public function css(array $settings): string
    {
        $defaults = SettingsService::DEFAULTS;
        $rules = [];

        $accent = Color::isHex($settings['brand.accent'] ?? null) ? Color::normalize($settings['brand.accent']) : self::FACTORY_ACCENT;
        $accentDark = Color::isHex($settings['brand.accent_dark'] ?? null) ? Color::normalize($settings['brand.accent_dark']) : null;
        $customAccent = $accent !== self::FACTORY_ACCENT;

        if ($customAccent || $accentDark !== null) {
            $palette = $this->palette($accent, $accentDark);

            // Con el acento de fabrica solo cambia el oscuro: el claro sigue siendo el de app.css.
            // El atributo va doble a proposito: sube la especificidad para ganarle a app.css
            // aunque Vite, en desarrollo, lo inyecte despues de `#brand-theme`.
            if ($customAccent) {
                $rules[] = "[data-theme='light'][data-theme]{".$this->colorDeclarations($palette['light']).'}';
            }

            $rules[] = "[data-theme='dark'][data-theme]{".$this->colorDeclarations($palette['dark']).'}';
        }

        $root = [];
        $fonts = config('brand_fonts');

        foreach (['body', 'display', 'numeric'] as $group) {
            $family = $settings["appearance.font_{$group}"] ?? null;

            if ($family !== $defaults["appearance.font_{$group}"] && isset($fonts[$group][$family])) {
                $root[] = "--brand-font-{$group}:'{$family}'";
            }
        }

        $radius = $settings['appearance.radius'] ?? 'soft';

        if ($radius !== $defaults['appearance.radius'] && isset(self::RADII[$radius])) {
            [$chip, $control, $card, $sheet] = self::RADII[$radius];
            $root[] = "--r-chip:{$chip}px;--r-control:{$control}px;--r-card:{$card}px;--r-sheet:{$sheet}px";
        }

        $density = $settings['appearance.density'] ?? 'cozy';

        // La caja ignora la densidad: nada que se toque con el dedo baja de 44 px.
        $panel = [];

        if ($density !== $defaults['appearance.density'] && isset(self::DENSITIES[$density])) {
            $panel[] = '--density:'.self::DENSITIES[$density];
        }

        $width = $settings['panel.content_width'] ?? 'centered';

        if ($width !== $defaults['panel.content_width'] && isset(self::CONTENT_WIDTHS[$width])) {
            $root[] = '--content-max:'.self::CONTENT_WIDTHS[$width];
        }

        if ($root !== []) {
            $rules[] = 'html:root{'.implode(';', $root).'}';
        }

        $surfaces = ['panel' => $panel, 'pos' => []];

        foreach (['panel' => 'panel.ui_scale', 'pos' => 'pos.ui_scale'] as $surface => $key) {
            $scale = (int) ($settings[$key] ?? 100);

            if ($scale !== (int) $defaults[$key] && $scale >= 50 && $scale <= 200) {
                $surfaces[$surface][] = '--ui-scale:'.rtrim(rtrim(number_format($scale / 100, 2, '.', ''), '0'), '.');
            }
        }

        foreach ($surfaces as $surface => $declarations) {
            if ($declarations !== []) {
                $rules[] = "html[data-surface='{$surface}']{".implode(';', $declarations).'}';
            }
        }

        return implode("\n", $rules);
    }

    /**
     * La direccion de Google Fonts con las tres familias elegidas. Con las de fabrica es
     * exactamente la que el blade cargaba antes.
     *
     * @param  array<string, mixed>  $settings
     */
    public function fontsHref(array $settings): string
    {
        $fonts = config('brand_fonts');
        $defaults = SettingsService::DEFAULTS;
        $families = [];

        foreach (['body', 'display', 'numeric'] as $group) {
            $family = $settings["appearance.font_{$group}"] ?? $defaults["appearance.font_{$group}"];
            $spec = $fonts[$group][$family] ?? $fonts[$group][$defaults["appearance.font_{$group}"]];
            $family = isset($fonts[$group][$family]) ? $family : $defaults["appearance.font_{$group}"];

            $families[$family]['weights'] = array_values(array_unique(array_merge($families[$family]['weights'] ?? [], $spec['weights'])));
            $families[$family]['axis'] = $families[$family]['axis'] ?? ($spec['axis'] ?? null);
        }

        return $this->googleHref($families);
    }

    /**
     * La direccion de Google Fonts de una sola familia, para la vista previa de Ajustes.
     *
     * @param  array{weights: list<int>, axis?: array{0: string, 1: string}|null}  $spec
     */
    public function familyHref(string $family, array $spec): string
    {
        return $this->googleHref([$family => ['weights' => $spec['weights'], 'axis' => $spec['axis'] ?? null]]);
    }

    /**
     * Opciones de tipografia para los selectores de Ajustes, con su direccion para precargar.
     *
     * @return array<string, list<array{value: string, label: string, generic: string, href: string}>>
     */
    public function fontOptions(): array
    {
        $options = [];

        foreach (config('brand_fonts') as $group => $families) {
            foreach ($families as $family => $spec) {
                $options[$group][] = [
                    'value' => $family,
                    'label' => $spec['label'],
                    'generic' => $spec['generic'],
                    'href' => $this->familyHref($family, $spec),
                ];
            }
        }

        return $options;
    }

    /**
     * La canasta de siempre como favicon, pintada con el acento cuando no hay isotipo.
     * Con el acento de fabrica es, caracter por caracter, el que traia el blade.
     */
    public function defaultFavicon(mixed $accent): string
    {
        $accent = Color::isHex($accent) ? Color::normalize($accent) : self::FACTORY_ACCENT;
        $stroke = Color::ink($accent) === '#FFFFFF' ? 'white' : '%23'.ltrim(Color::DARK_INK, '#');
        $fill = '%23'.ltrim($accent, '#');

        return 'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 32 32%22>'
            ."<rect width=%2232%22 height=%2232%22 rx=%228%22 fill=%22{$fill}%22/>"
            ."<path d=%22M8 11h16l-2 12H10L8 11Z%22 fill=%22none%22 stroke=%22{$stroke}%22 stroke-width=%222%22/>"
            ."<path d=%22M12 11a4 4 0 0 1 8 0%22 fill=%22none%22 stroke=%22{$stroke}%22 stroke-width=%222%22/></svg>";
    }

    /**
     * @param  array{path?: string}|mixed  $value
     * @return array<string, string>
     */
    protected function asset(mixed $value): array
    {
        return is_array($value) ? array_filter($value, 'is_string') : [];
    }

    protected function url(?string $path): ?string
    {
        return Uploads::url($path);
    }

    /**
     * @param  array<string, string>  $tokens
     */
    protected function colorDeclarations(array $tokens): string
    {
        return sprintf(
            '--accent:%1$s;--accent-ink:%2$s;--accent-soft:%3$s;--brand-accent:%1$s;--brand-ink:%2$s;--brand-muted:%4$s;--brand-mark-ink:%2$s',
            $tokens['accent'],
            $tokens['ink'],
            $tokens['soft'],
            $tokens['muted'],
        );
    }

    /**
     * @param  array{surface: string, bg: string}  $palette
     * @return array{accent: string, ink: string, soft: string, muted: string, contrast: float}
     */
    protected function tokens(string $accent, array $palette, float $softWeight): array
    {
        $ink = Color::ink($accent);

        return [
            'accent' => $accent,
            'ink' => $ink,
            'soft' => Color::mix($palette['surface'], $accent, $softWeight),
            'muted' => $this->sidebarMuted($accent, $ink),
            'contrast' => $this->worstContrast($accent, $palette, $softWeight),
        ];
    }

    /**
     * El gris de los textos secundarios en el menu del color de la marca: la letra mezclada
     * con el acento. Si esa mezcla no llega a 4,5:1, va la letra entera.
     */
    public function sidebarMuted(string $accent, string $ink): string
    {
        $muted = Color::mix($accent, $ink, 0.8);

        return Color::contrast($muted, $accent) >= self::MIN_CONTRAST ? $muted : $ink;
    }

    /**
     * @param  array<string, array{weights: list<int>, axis: array{0: string, 1: string}|null}>  $families
     */
    protected function googleHref(array $families): string
    {
        ksort($families);
        $parts = [];

        foreach ($families as $family => $spec) {
            $weights = $spec['weights'];
            sort($weights);
            $name = str_replace(' ', '+', $family);

            if ($spec['axis'] ?? null) {
                [$axis, $range] = $spec['axis'];
                $parts[] = "family={$name}:{$axis},wght@".implode(';', array_map(fn ($w) => "{$range},{$w}", $weights));
            } else {
                $parts[] = "family={$name}:wght@".implode(';', $weights);
            }
        }

        return 'https://fonts.googleapis.com/css2?'.implode('&', $parts).'&display=swap';
    }
}
