# Ajustes de marca, panel y caja — especificación para implementar

> **Proyecto:** La Canasta (Laravel 12 + Inertia 2 + React 19 + TS estricto)
> **Fecha:** 2026-09-25
> **Alcance:** panel administrativo y punto de venta. **No** toca la tienda en línea, que ya tiene su propio editor de aspecto.
> **Base:** la maqueta funcional mostrada en la conversación. Donde este documento y la maqueta difieran, manda este documento (ver §11).

---

## Índice

1. [Objetivo y principios](#1-objetivo-y-principios)
2. [Decisiones asumidas (confirmar antes de la fase 1)](#2-decisiones-asumidas)
3. [Diagnóstico del código actual](#3-diagnóstico-del-código-actual)
4. [Catálogo de ajustes](#4-catálogo-de-ajustes)
5. [Backend](#5-backend)
6. [Frontend](#6-frontend)
7. [Migración de estilos: de `px` a tokens](#7-migración-de-estilos-de-px-a-tokens)
8. [Rutas, permisos y FormRequests](#8-rutas-permisos-y-formrequests)
9. [Fases, entregables y criterios de aceptación](#9-fases-entregables-y-criterios-de-aceptación)
10. [Pruebas](#10-pruebas)
11. [Diferencias con la maqueta](#11-diferencias-con-la-maqueta)
12. [Reglas nuevas para `CLAUDE.md`](#12-reglas-nuevas-para-claudemd)
13. [Hallazgos colaterales](#13-hallazgos-colaterales)

---

## 1. Objetivo y principios

Que todo lo visual y la organización del panel y de la caja se configure desde **Ajustes**, sin tocar código:
identidad (nombre, logos, color), apariencia (tipografía, escala, esquinas, densidad, menú), organización
(orden y nombres del menú, página de inicio por rol, riel y atajos de la caja) e impresión (tiquete).

Principios que no se negocian:

| Principio | Por qué |
|---|---|
| **Los valores por defecto reproducen hoy, píxel por píxel.** Desplegar esto no cambia nada hasta que alguien edite. | Una empresa en uso no puede amanecer con otra cara. |
| **Si la empresa no tocó el color, no se inyecta CSS:** manda `app.css`. | El verde oscuro actual (`#2FBE7E`) no sale de ninguna fórmula; no se debe "recalcular". |
| **Se configura con barandas, no a mano alzada.** Color de marca + derivados validados por contraste; fuentes de una lista cerrada; tamaños en escalones. | 30 selectores de color sueltos terminan en gris sobre gris. |
| **La configuración puede ocultar, nunca mostrar.** El permiso manda después de la configuración. | Un menú configurado no puede ser una puerta trasera. |
| **Claro u oscuro sigue siendo decisión de quien mira.** La empresa fija el punto de partida y si hay interruptor. | Regla vigente: la elección vive en `localStorage`, no en la BD. |
| **La tienda en línea queda por fuera.** En rutas `storefront.*` no se comparte ni se inyecta nada de esto. | `StoreTheme` ya es dueño de esas pantallas. |

---

## 2. Decisiones asumidas

Todas tienen una alternativa. Las marcadas con ⚠ cambian el diseño si se deciden distinto.

| # | Decisión | Asumido | Alternativa | Si se cambia… |
|---|---|---|---|---|
| D1 ⚠ | Alcance de marca, apariencia, menú, caja, reglas y crédito | **Empresa** | Por sede | Habría que llevar todo a `store_setting_overrides` y la marca podría variar entre sedes. |
| D2 ⚠ | Alcance del tiquete | **Empresa, con excepciones por sede** | Solo empresa | Se elimina la tabla de excepciones y el selector «Editando». |
| D3 | Tema claro/oscuro | Empresa fija el **inicial** y si se **muestra el interruptor**; la elección sigue en `localStorage` | Tema forzado por empresa | Violaría la regla de `CLAUDE.md`. |
| D4 ⚠ | Permisos | Dos nuevos: `settings.index.brand` y `settings.index.pos_layout`; se reusa `settings.index.edit` | Uno solo (`settings.index.edit`) | Sin migración de reparto, pero quien cambia el redondeo también cambiaría el logo. |
| D5 | Pantalla de ingreso | Marca de la **única empresa activa**; con varias, marca neutra (`config('app.name')` y colores de fábrica) | Marca por subdominio | Requiere resolver empresa por dominio antes del login. |
| D6 ⚠ | Códigos en el tiquete | `picqer/php-barcode-generator` (Code 128) y `chillerlan/php-qrcode` (QR), ambos MIT y con salida SVG sin extensiones | Sin código en la v1 | Se quita la opción «Código al pie» hasta decidir. |
| D7 | Fuentes | Lista cerrada servida desde Google Fonts (como hoy) | Autoalojadas en `public/fonts` | Mejor sin internet en caja, pero hay que empaquetar cada familia. |
| D8 | Imágenes de marca | PNG, JPG o WebP hasta 2 MB; **SVG no** en la v1 | SVG saneado (`enshrined/svg-sanitize`) | Un SVG puede traer `<script>`: sin saneador es XSS almacenado. |
| D9 ⚠ | NIT y Base/IVA en el tiquete | **Fijos**, no se pueden apagar | Apagables | Confirmar con contabilidad: si el tiquete es documento equivalente, no son opcionales. |

---

## 3. Diagnóstico del código actual

### A favor

- **Los colores ya son tokens.** 15 variables × 2 temas en [`resources/css/app.css`](../resources/css/app.css); solo 19 hex sueltos en `.tsx` (5 archivos, casi todos legítimos: `StoreTheme`, `AppearanceTab`, colores de rol).
- **Los tokens cuelgan de `[data-theme]`, no solo de `:root`.** Por eso un `<div data-theme="dark">` vuelve oscuro su subárbol. Eso habilita la vista previa sin `iframe` y el menú lateral oscuro sobre un panel claro.
- **Las fuentes ya son variables** (`--font-sans`, `--font-display`, `--font-mono`).
- **El editor de la tienda ya resolvió contraste y letra sobre el acento** (`StorefrontPublisher::contrast/ink/luminance`, `AppearanceTab.tsx`). Se extrae y se reusa.
- **`SettingsService`** ya es clave‑valor JSON por empresa, con `DEFAULTS` y caché por petición.

### En contra (lo que hay que arreglar antes de parametrizar)

| Qué | Dónde | Cantidad |
|---|---|---|
| Tamaños de letra escritos en `px` | `text-[13px]`, `text-[12.5px]`… en todo `resources/js` | **1.193** (13px: 312 · 12.5px: 237 · 11.5px: 145 · 13.5px: 123 · 12px: 116 · …) |
| Esquinas escritas en `px` | `rounded-[10px]`, `rounded-[9px]`… | **432** (10px: 131 · 9px: 64 · 11px: 53 · 8px: 49 · 14px: 45 · 12px: 36 · 13px: 17 · 6px: 13 · 7px: 11 · 16px: 8 · 5px: 4 · 20px: 1 · 18px: 1) |
| Tokens de radio definidos y **sin un solo uso** | `--radius-chip/control/card/sheet` en `@theme` | 0 usos |
| Otras medidas arbitrarias en `px` | `w-[238px]`, `h-[58px]`, `max-w-[1202px]`… | 148 |
| Logo = SVG en línea repetido | `AppLayout.tsx:44`, `PosLayout.tsx:57`, `AuthLayout.tsx` y favicon en `app.blade.php:12` | 4 |
| «La Canasta» escrito a mano | `AppLayout.tsx:51`, `AuthLayout.tsx:40`, `Login.tsx:41`, respaldos en `pdf/receipt`, `pdf/sales-report`, `pdf/z-report`, `pos/receipt-print` | 8 |
| `theme-color` fijo | `app.blade.php:7` | 1 |
| Google Fonts fijo | `app.blade.php:15` | 1 |
| Tema inicial por ruta, fijo | `app.blade.php:3` (`request()->is('pos*','caja/*')`), `useTheme('light')`, `useTheme('dark')` | 3 |
| Ancho del contenido fijo | `AppLayout.tsx:184` (`max-w-[1202px]`) | 1 |
| Menú como literal | `lib/navigation.ts:69` | — |
| Atajos fijos | `Pos/Sale.tsx:471` (F1 escanear, F2 cliente, F3 descuento, F4 suspender, F5 retomar, F6 recientes, F8 y F12 cobrar) | 8 |
| Tiquete fijo | `pos/receipt-print.blade.php` (80 mm, 11px, Courier) | — |
| `ImageService::encode()` guarda JPEG | `ImageService.php:257` | Un logo con transparencia queda con fondo negro o blanco. |
| `companies.logo` existe y se comparte (`activeCompany.logo`) pero **nadie lo usa** | `HandleInertiaRequests.php:212` | — |

---

## 4. Catálogo de ajustes

Nombres definitivos. Todas las claves caben en `settings.key` (`string(60)`).
**Alcance:** `E` = empresa, `S` = empresa con excepción por sede.
**Gestionada:** la escribe un endpoint propio (imágenes), no el `PUT` de la pestaña.

### 4.1 Marca — `PUT /ajustes/marca`

| Clave | Tipo | Defecto | Validación | Se usa en |
|---|---|---|---|---|
| `brand.display_name` | string | `'La Canasta'` | `required, string, max:40` | Menú, ingreso, `<title>`, tiquete, PDF |
| `brand.tagline` | string\|null | `'Sistema de supermercado'` | `nullable, string, max:60` | Menú, ingreso |
| `brand.accent` | hex | `'#0E6E4E'` | `required, regex:/^#[0-9A-Fa-f]{6}$/` | Tokens de color |
| `brand.accent_dark` | hex\|null | `null` (= derivado) | `nullable, regex:…` + contraste ≥ 4,5 contra fondos oscuros | Tokens del tema oscuro (opción avanzada) |
| `brand.mark` | `{path, favicon, touch}`\|null | `null` | Gestionada | Favicon, riel de la caja, menú colapsado, ingreso |
| `brand.logo_light` | `{path}`\|null | `null` | Gestionada | Menú sobre fondo claro |
| `brand.logo_dark` | `{path}`\|null | `null` | Gestionada | Menú sobre fondo oscuro o de marca; si falta, se usa el claro |
| `brand.logo_receipt` | `{path}`\|null | `null` | Gestionada (se genera) | Tiquete y PDF, en blanco y negro |

### 4.2 Apariencia — `PUT /ajustes/apariencia`

Compartida por panel y caja (tipografía, esquinas, densidad) más lo propio del panel.

| Clave | Tipo | Defecto | Validación |
|---|---|---|---|
| `appearance.font_body` | enum de `config/brand_fonts.php` | `'IBM Plex Sans'` | `Rule::in(array_keys(config('brand_fonts.body')))` |
| `appearance.font_display` | enum | `'Bricolage Grotesque'` | ídem con `display` |
| `appearance.font_numeric` | enum (solo cifras tabulares) | `'IBM Plex Mono'` | ídem con `numeric` |
| `appearance.radius` | `sharp\|soft\|round` | `'soft'` | `in:` |
| `appearance.density` | `cozy\|compact` | `'cozy'` | `in:` (la caja la ignora; ver §6.6) |
| `panel.default_theme` | `light\|dark\|system` | `'light'` | `in:` |
| `panel.allow_theme_toggle` | bool | `true` | `boolean` |
| `panel.sidebar_style` | `surface\|dark\|brand` | `'surface'` | `in:` |
| `panel.sidebar_mode` | `expanded\|icons` | `'expanded'` | `in:` |
| `panel.ui_scale` | int | `100` | `integer, in:90,95,100,105,110,115,120` |
| `panel.content_width` | `centered\|wide\|full` | `'centered'` | `in:` |

### 4.3 Menú — `PUT /ajustes/menu`

| Clave | Tipo | Defecto | Validación |
|---|---|---|---|
| `panel.navigation` | objeto con **solo diferencias** (ver §6.4) | `{}` | Estructura, formato de claves y **bloqueo**: no se puede ocultar lo que contiene `settings.index` |
| `panel.home_by_role` | `{roleName: routeName}` | `{}` (= `dashboard`) | Ruta dentro de `HomeResolver::PAGES`; rol existente en la empresa |

### 4.4 Caja — `PUT /ajustes/caja`

| Clave | Tipo | Defecto | Validación |
|---|---|---|---|
| `pos.default_theme` | `light\|dark\|system` | `'dark'` | `in:` |
| `pos.allow_theme_toggle` | bool | `true` | `boolean` |
| `pos.ui_scale` | int | `100` | `in:100,115,130` |
| `pos.ticket_side` | `right\|left` | `'right'` | `in:` |
| `pos.catalog_view` | `grid\|list\|search` | `'grid'` | `in:` |
| `pos.tile_size` | `sm\|md\|lg` | `'md'` | `in:` |
| `pos.total_size` | `normal\|large` | `'normal'` (= el tamaño actual de `Sale.tsx`; `large` lo sube un escalón) | `in:` |
| `pos.rail` | lista ordenada de `sale\|opening\|closing` | `['sale','opening','closing']` | Permutación exacta de las tres |
| `pos.shortcuts` | `{action: key}` | `{scan:'F1', customer:'F2', discount:'F3', hold:'F4', resume:'F5', recent:'F6', pay:'F12', pay_alt:'F8'}` | Cada valor en `F1…F12`, **sin repetidos**, todas las acciones presentes |
| `pos.sounds` | bool | `false` (hoy la caja no suena) | `boolean` |

### 4.5 Reglas de caja — `PUT /ajustes/caja/reglas`

Las de hoy, sin cambio de clave ni de validación: `pos.allow_discount_percent` (0–100) y `pos.cash_rounding` (0, 50 o 100).

### 4.6 Tiquete — `PUT /ajustes/tiquete` y `PUT /ajustes/tiquete/sedes/{store}`

Alcance `S`: el valor de la empresa aplica a toda sede que no tenga excepción.

| Clave | Tipo | Defecto (= lo que imprime hoy) | Validación |
|---|---|---|---|
| `receipt.paper_width` | `58\|80` | `80` | `in:58,80` |
| `receipt.font_size` | `small\|normal\|large` (10/11/12 px) | `'normal'` (11 px, como hoy) | `in:` |
| `receipt.show_logo` | bool | `false` (hoy no imprime logo) | `boolean` |
| `receipt.header` | texto | `''` | Máx. 6 líneas; máx. **32** caracteres por línea en 58 mm y **48** en 80 mm |
| `receipt.fields` | lista | `['store_address','store_phone','cashier','seller','customer']` | Subconjunto de esas cinco. **NIT y Base/IVA no están en la lista: siempre salen** (D9). |
| `receipt.code` | `none\|barcode\|qr` | `'none'` | `in:` |
| `pos.receipt_footer` | texto | el actual | `required, max:200`. **Se conserva la clave** para no migrar datos. |
| `receipt.copies` | int | `1` | `in:1,2,3` |
| `receipt.auto_print` | bool | `true` (hoy imprime al cargar) | `boolean` |

La validación del encabezado depende del papel **efectivo** de esa sede: una excepción de 58 mm con un encabezado heredado de 45 caracteres debe fallar.

### 4.7 Crédito — `PUT /ajustes/credito`

Sin cambios: `credit.block_on_overdue_days` (1–120).

---

## 5. Backend

### 5.1 Almacenamiento

**Empresa:** la tabla `settings` de siempre (`company_id, key, value json`, único `company_id + key`). Sin cambios.

**Excepciones por sede:** tabla nueva. **No** se agrega `store_id` nullable a `settings`: en MariaDB un índice único trata los `NULL` como distintos, así que `(1, NULL, 'x')` se podría insertar dos veces y la empresa tendría dos valores para la misma clave.

```php
// database/migrations/2026_09_25_000100_create_store_setting_overrides_table.php
Schema::create('store_setting_overrides', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->foreignId('store_id')->constrained()->cascadeOnDelete();
    $table->string('key', 60);
    $table->json('value')->nullable();
    $table->timestamps();

    $table->unique(['store_id', 'key']);
});
```

Modelo `StoreSettingOverride` con `$fillable = ['company_id', 'store_id', 'key', 'value']` y `casts: ['value' => 'array']`.
Recuerda la trampa: sin `$fillable`, `create()` ignora columnas sin avisar.

### 5.2 `SettingsService`: defectos y cascada

`DEFAULTS` sigue siendo la fuente de los valores por defecto, como exige `CLAUDE.md`. Se le suman todas las claves de §4.

```php
public const DEFAULTS = [
    // …las cuatro de hoy…
    'brand.display_name' => 'La Canasta',
    'brand.tagline' => 'Sistema de supermercado',
    'brand.accent' => '#0E6E4E',
    'brand.accent_dark' => null,
    'brand.mark' => null,
    // …resto de §4…
];
```

Métodos nuevos:

```php
/** Ajustes efectivos de una sede: los de la empresa con las excepciones de la sede encima. */
public function forStore(int $companyId, ?int $storeId): array;

/** Guarda o quita (con `null`) la excepción de una sede. Solo claves con alcance de sede. */
public function setForStore(int $companyId, int $storeId, string $key, mixed $value): void;
```

`forStore` solo aplica excepciones de claves que `SettingsSchema::isStoreScoped()` reconozca. Una fila vieja con otra clave se ignora.

### 5.3 `SettingsSchema`: una definición por clave

`app/Settings/SettingsSchema.php`. Guarda los metadatos; los defectos siguen en `DEFAULTS`. Una prueba verifica que las dos listas tengan las mismas claves (§10).

```php
final class SettingsSchema
{
    /**
     * @return array<string, array{tab: string, scope: 'company'|'store', rules?: array, managed?: bool}>
     */
    public static function definitions(): array
    {
        return [
            'brand.display_name' => ['tab' => 'brand', 'scope' => 'company', 'rules' => ['required', 'string', 'max:40']],
            'brand.accent' => ['tab' => 'brand', 'scope' => 'company', 'rules' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/']],
            'brand.mark' => ['tab' => 'brand', 'scope' => 'company', 'managed' => true],
            // …
            'receipt.paper_width' => ['tab' => 'receipt', 'scope' => 'store', 'rules' => ['required', 'integer', 'in:58,80']],
            'pos.receipt_footer' => ['tab' => 'receipt', 'scope' => 'store', 'rules' => ['required', 'string', 'max:200']],
        ];
    }

    /** Reglas del PUT de una pestaña: excluye las gestionadas. */
    public static function rulesFor(string $tab): array;

    public static function keysFor(string $tab): array;

    public static function isStoreScoped(string $key): bool;
}
```

`SettingsService::setMany()` ya descarta claves que no estén en `DEFAULTS`. Además, cada FormRequest valida solo las claves de su pestaña, así que un `PUT /ajustes/caja` no puede colar `brand.accent`.

### 5.4 `App\Support\Color`: aritmética de color compartida

Se extrae de `StorefrontPublisher` (`luminance`, `contrast`, `ink`) y se generaliza. `StorefrontPublisher` pasa a delegar aquí. **El navegador repite estas mismas cuentas en `resources/js/lib/color.ts`**: si una cambia, cambia la otra, o la vista previa promete un color y el servidor guarda otro.

```php
final class Color
{
    public static function luminance(string $hex): float;   // WCAG, igual que hoy

    /** Contraste real entre dos colores (no contra «el mejor de blanco o negro»). */
    public static function contrast(string $a, string $b): float;

    /** Mezcla lineal en sRGB; $t = 0 devuelve $a, $t = 1 devuelve $b. */
    public static function mix(string $a, string $b, float $t): string;

    /** Letra encima del color: blanca o #10161C, la que mejor se lea. */
    public static function ink(string $hex): string;
}
```

### 5.5 `BrandThemeService`: de ajustes a tokens

`app/Services/BrandThemeService.php`. Recibe el id de la empresa (o `null`) y devuelve lo que necesitan el blade y el front.

**Cómo se resuelve el acento de cada tema:**

```php
/**
 * Acerca el color de marca a negro (tema claro) o a blanco (tema oscuro) en pasos de 5 %
 * hasta que se lea como texto sobre todos los fondos donde la interfaz lo pone: la
 * superficie, el fondo de la pagina y su propio tinte suave (el item activo del menu).
 */
protected function resolveAccent(string $accent, array $palette, string $toward, float $softWeight): string
{
    for ($step = 0; $step <= 20; $step++) {
        $candidate = Color::mix($accent, $toward, $step / 20);
        $soft = Color::mix($palette['surface'], $candidate, $softWeight);

        $worst = min(
            Color::contrast($candidate, $palette['surface']),
            Color::contrast($candidate, $palette['bg']),
            Color::contrast($candidate, $soft),
        );

        if ($worst >= 4.5) {
            return $candidate;
        }
    }

    return $toward;
}
```

- **Claro:** `resolveAccent($accent, LIGHT, '#000000', 0.12)`, con `LIGHT = ['surface' => '#FFFFFF', 'bg' => '#F4F4F1']`.
- **Oscuro:** `brand.accent_dark` si existe, validado igual. Si no, `resolveAccent($accent, DARK, '#FFFFFF', 0.20)`, con `DARK = ['surface' => '#151A1D', 'bg' => '#0C1012']`.
- `--accent-ink` sale de `Color::ink()` sobre el acento **resuelto**.
- `--accent-soft` sale de `Color::mix(surface, acentoResuelto, peso)`.
- **Si `brand.accent` es el de fábrica y `brand.accent_dark` es `null`, no se emite ningún color:** manda `app.css`. Así el verde oscuro `#2FBE7E` de hoy se conserva.
- El logo y el isotipo conservan el color original. Solo la interfaz usa el ajustado, y la pestaña Marca lo avisa («Tu color da 1,6:1 sobre blanco…»).

**Lo que devuelve:**

```php
[
    'display_name' => 'La Canasta',
    'tagline' => 'Sistema de supermercado',
    'mark_url' => '/storage/brand/1/mark-512.png',     // null si no hay
    'logo_light_url' => null,
    'logo_dark_url' => null,
    'favicon_url' => '/storage/brand/1/mark-32.png',    // null = el de fábrica
    'theme_color' => '#0E6E4E',
    'css' => '…',                                        // ver §5.6; '' si todo es de fábrica
    'fonts_href' => 'https://fonts.googleapis.com/css2?family=…&display=swap',
    'surfaces' => [
        'panel' => ['default_theme' => 'light', 'allow_theme_toggle' => true, 'sidebar_style' => 'surface', 'sidebar_mode' => 'expanded'],
        'pos' => ['default_theme' => 'dark', 'allow_theme_toggle' => true],
    ],
    'navigation' => [/* panel.navigation, solo diferencias */],
]
```

Lo propio de la caja (riel, atajos, catálogo, tiquete) **no** va en la prop compartida. Viaja como prop de `Pos/Sale`, que es la única pantalla que lo usa.

### 5.6 CSS generado

Se arma **solo** con valores validados (hex por regex, enums por `in:`). Nunca se interpola texto libre. Por eso se puede imprimir con `{!! !!}`.

```css
:root,[data-theme='light']{--accent:#0B5A40;--accent-ink:#FFFFFF;--accent-soft:#E0EDE8;--brand-accent:#0B5A40;--brand-ink:#FFFFFF}
[data-theme='dark']{--accent:#5FB896;--accent-ink:#10161C;--accent-soft:#233A33;--brand-accent:#5FB896;--brand-ink:#10161C}
:root{--brand-font-body:'Inter';--brand-font-display:'Plus Jakarta Sans';--brand-font-numeric:'JetBrains Mono';
      --r-chip:8px;--r-control:10px;--r-card:14px;--r-sheet:20px;--density:1;--content-max:75.125rem}
html[data-surface='panel']{--ui-scale:1}
html[data-surface='pos']{--ui-scale:1.15;--density:1}
```

Tabla de radios:

| Opción | chip | control | card | sheet |
|---|---|---|---|---|
| `sharp` | 2px | 3px | 4px | 6px |
| `soft` (hoy) | 8px | 10px | 14px | 20px |
| `round` | 12px | 16px | 20px | 28px |

Ancho del contenido: `centered` = 75,125rem (los 1202 px de hoy) · `wide` = 90rem · `full` = `none`.

### 5.7 Compartir con Inertia

En `HandleInertiaRequests::share()`:

```php
// La marca del panel y la caja. En la tienda en linea no: alli manda `storeTheme`.
'brand' => fn () => str_starts_with($request->route()?->getName() ?? '', 'storefront.')
    ? null
    : app(BrandThemeService::class)->forCompany($activeCompanyId ?? $this->loginCompanyId()),
```

- El usuario se lee con `$request->user('web')`, como el resto del middleware (trampa de los guards).
- `loginCompanyId()`: el id de la única empresa activa, o `null` si hay varias (D5).
- Con «Consolidado» (`activeCompanyId === null` para superadmin) se usa la marca neutra.
- Costo: `SettingsService::all()` es una consulta por petición y ya está en caché por petición. No hace falta caché adicional en la v1.

### 5.8 Imágenes de marca

Método nuevo en `ImageService`. **No** reusar `store()`/`encode()`, porque guardan JPEG y matan la transparencia.

```php
/**
 * Guarda una imagen de marca en PNG, conservando la transparencia.
 *
 * @return array<string, string> rutas en el disco `public`
 */
public function storeBrandAsset(UploadedFile $file, int $companyId, string $slot): array
```

| Slot | Variantes generadas | Notas |
|---|---|---|
| `mark` | `mark-512.png`, `mark-32.png` (favicon), `mark-180.png` (touch, **con fondo del acento**: iOS no respeta transparencia) | Mínimo 256 × 256, cuadrada (tolerancia 5 %) |
| `logo_light` | `logo-light.png`, máximo 600 px de ancho | — |
| `logo_dark` | `logo-dark.png`, máximo 600 px de ancho | — |
| *(automático)* `logo_receipt` | Desde `logo_light` (o `mark` si no hay): gris, umbral y 576 px de ancho | 576 puntos = 72 mm imprimibles a 203 dpi. En 58 mm se escala por CSS. |

- **Transparencia:** `imagealphablending($img, false)` + `imagesavealpha($img, true)` antes de `imagepng`.
- **Blanco y negro:** `imagefilter(IMG_FILTER_GRAYSCALE)` y luego `IMG_FILTER_CONTRAST, -60`. El umbral se afina con un logo real.
- **Sin GD:** se guarda el original. El tiquete HTML le aplica `filter: grayscale(1) contrast(1.8)`; dompdf ignora ese filtro, y es aceptable.
- **Ruta:** `brand/{companyId}/…` en el disco `public`. Al reemplazar, se borran los archivos anteriores del slot.
- **Validación:** `mimes:png,jpg,jpeg,webp`, `max:2048`, `dimensions:min_width=256,min_height=256` para `mark`.
- **Pruebas:** `UploadedFile::fake()->create('x.png', 120, 'image/png')` si no se quiere depender de GD (trampa conocida).
- `companies.logo` **no se revive**: la marca vive en ajustes (una sola fuente). Se deja la columna y se deja de compartir `activeCompany.logo` en una limpieza posterior.

### 5.9 Página de inicio por rol

`app/Support/HomeResolver.php`:

```php
final class HomeResolver
{
    /** Paginas que se pueden elegir como inicio, con el permiso que exigen. */
    public const PAGES = [
        'dashboard' => 'dashboard.index.view',
        'pos.index' => 'pos.sale.view',
        'orders.index' => 'orders.index.view',
        'inventory.index' => 'inventory.index.view',
        'reports.index' => 'reports.sales.view',
    ];

    public static function for(User $user): string
    {
        // Rol primario → ruta configurada → si no tiene permiso, la primera accesible → dashboard.
    }
}
```

En `AuthenticatedSessionController:37`: `redirect()->intended(HomeResolver::for($user))`. `intended` sigue mandando cuando la persona venía de un enlace.

### 5.10 Tiquete

- `Pos\SaleController` (el que pinta `pos/receipt-print`) y el PDF de `pdf/receipt` reciben `$receipt = $settings->forStore($companyId, $sale->store_id)`.
- **Papel:** `@page { size: {{ $receipt['receipt.paper_width'] }}mm auto }` y `width` del cuerpo.
- **Letra:** 10, 11 o 12 px según `receipt.font_size`.
- **Encabezado:** debajo del bloque de la sede, una línea por renglón, escapado con `{{ }}`.
- **Campos:** `store_address`, `store_phone`, `cashier`, `seller` y `customer` se condicionan. **NIT, Base e IVA quedan fijos** (D9).
- **Código:** Code 128 o QR con `$sale->number`, en SVG en línea. Sirve igual en HTML y en dompdf.
- **Copias:** se repite el cuerpo con `page-break-after: always` entre copias.
- **Impresión automática:** el `window.print()` de `receipt-print.blade.php:158` queda condicionado a `receipt.auto_print`.
- **Logo:** en HTML, la URL. En dompdf, la ruta de disco (`Storage::disk('public')->path(...)`), porque dompdf no descarga URLs sin `isRemoteEnabled`.
- **Nombre:** los respaldos `?? 'La Canasta'` se reemplazan por `brand.display_name`.

---

## 6. Frontend

### 6.1 Inyección antes de pintar (`app.blade.php`)

Por la misma razón que existe hoy el guion del tema: si React aplicara la marca, se vería el verde de fábrica un instante.

```blade
@php
    $brand = $page['props']['brand'] ?? null;
    $surface = request()->is('pos*', 'caja/*') ? 'pos' : 'panel';
    $surfaceCfg = $brand['surfaces'][$surface] ?? null;
    $defaultTheme = $surfaceCfg['default_theme'] ?? ($surface === 'pos' ? 'dark' : 'light');
    $toggle = $surfaceCfg['allow_theme_toggle'] ?? true;
@endphp
<html lang="es-CO"
      data-surface="{{ $surface }}"
      data-theme="{{ $defaultTheme === 'system' ? 'light' : $defaultTheme }}"
      data-theme-default="{{ $defaultTheme }}"
      data-theme-locked="{{ $toggle ? '0' : '1' }}">
<head>
    …
    <meta name="theme-color" content="{{ $brand['theme_color'] ?? '#0E6E4E' }}">
    <title inertia>{{ $brand['display_name'] ?? config('app.name') }}</title>
    <link rel="icon" href="{{ $brand['favicon_url'] ?? '…el data: de hoy…' }}">
    <link href="{{ $brand['fonts_href'] ?? '…el href de hoy…' }}" rel="stylesheet">

    <script>
        (function () {
            var root = document.documentElement;
            var fallback = root.getAttribute('data-theme-default');
            var locked = root.getAttribute('data-theme-locked') === '1';
            var stored = null;
            try { stored = locked ? null : window.localStorage.getItem('lacanasta.theme'); } catch (e) {}
            var pref = stored || fallback;
            var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            root.setAttribute('data-theme', dark ? 'dark' : 'light');
        })();
    </script>

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    {{-- DESPUES de @vite: mismos selectores que app.css, gana el que va de ultimas. --}}
    <style id="brand-theme">{!! $brand['css'] ?? '' !!}</style>
    @inertiaHead
</head>
```

> ⚠ El `<style id="brand-theme">` va **después** de `@vite`. Si va antes, `app.css` lo pisa.

### 6.2 Mantenerlo al día sin recargar

El blade solo se pinta en la primera carga. Las visitas de Inertia no lo vuelven a pintar. Por eso:

- **`components/brand/BrandStyle.tsx`**: un efecto que, cuando cambia `usePage().props.brand.css`, reescribe el `textContent` de `#brand-theme`, el `href` del favicon y `meta[name=theme-color]`. Se monta en `AppLayout`, `PosLayout` y `AuthLayout`.
- **`hooks/use-surface.ts`**: `useSurface('panel' | 'pos')` fija `data-surface` en `<html>`, igual que `useTheme` fija `data-theme`. Sin esto, pasar del panel a la caja por Inertia deja la escala del panel.

Efecto: al guardar Ajustes, quien guarda ve el cambio de inmediato. Los demás lo ven en su siguiente navegación.

### 6.3 Tema: `useTheme` y `ThemeToggle`

- `useTheme(fallback: ThemePreference, locked = false)`: acepta `'system'` como punto de partida. Con `locked`, ignora `localStorage` sin borrarlo; si la empresa vuelve a mostrar el interruptor, la elección de la persona reaparece.
- `AppLayout`: `useTheme(brand.surfaces.panel.default_theme, !brand.surfaces.panel.allow_theme_toggle)`. Lo mismo en `PosLayout` con `pos`.
- `ThemeToggle` **no se pinta** si `allow_theme_toggle` es `false` (regla: no se pinta, no se deshabilita).

### 6.4 Menú configurable

`NAVIGATION` sigue en `lib/navigation.ts` como **catálogo de lo que existe**: los íconos son componentes y no viajan por JSON. La configuración solo guarda diferencias:

```ts
export interface NavigationOverrides {
    section_order?: string[];                 // claves de sección
    entry_order?: Record<string, string[]>;   // por sección: claves de grupo o routeName del enlace suelto
    labels?: Record<string, string>;          // clave → nombre
    hidden?: string[];                        // claves ocultas
}

/** Clave estable de una entrada: el grupo por su `key`, el enlace suelto por su ruta. */
export const entryKey = (entry: NavEntry): string => (isNavGroup(entry) ? entry.key : entry.routeName);

/**
 * Aplica lo que la empresa configuró sobre el catálogo del código.
 *
 * Lo desconocido se ignora y lo nuevo aparece en su sitio por defecto: una pantalla que se
 * agregue mañana se ve sin que nadie tenga que volver a configurar el menú.
 */
export function applyNavigationOverrides(base: NavSection[], o: NavigationOverrides | null): NavSection[];
```

Orden en `AppLayout`: `applyNavigationOverrides(NAVIGATION, brand.navigation)` → filtro por permiso (el de hoy) → secciones vacías fuera. **La configuración nunca devuelve algo que el permiso quitó.**

**Bloqueo:** `NavigationOverrides::LOCKED = ['admin', 'company']` en PHP. Son la sección y el grupo que contienen `settings.index`. El FormRequest rechaza ocultarlos: si se ocultan, nadie vuelve a Ajustes para deshacerlo. Una prueba verifica que en `navigation.ts` el enlace `settings.index` siga dentro de esas claves.

**Menú solo con íconos** (`panel.sidebar_mode = 'icons'`): `SidebarNav` gana un modo compacto (68 px). El grupo se vuelve un ícono que abre un menú flotante con sus enlaces, y cada ícono lleva `title` y `aria-label`.

**Estilo del menú lateral**, sin CSS nuevo por componente:

- `dark`: el `<aside>` recibe `data-theme="dark"`. Los tokens cuelgan del atributo y el subárbol se vuelve oscuro aunque el panel sea claro.
- `brand`: el `<aside>` recibe `data-sidebar="brand"`, y en `app.css`:

```css
/* Menu del color de la marca: se reescriben los tokens del subarbol, no los componentes. */
[data-sidebar='brand'] {
    --surface: var(--brand-accent);
    --surface-2: color-mix(in srgb, var(--brand-ink) 14%, var(--brand-accent));
    --line: color-mix(in srgb, var(--brand-ink) 22%, var(--brand-accent));
    --text: var(--brand-ink);
    --muted: color-mix(in srgb, var(--brand-ink) 80%, var(--brand-accent));
    --accent: var(--brand-ink);
    --accent-ink: var(--brand-accent);
    --accent-soft: color-mix(in srgb, var(--brand-ink) 18%, var(--brand-accent));
}
```

> `--brand-accent` y `--brand-ink` existen para no crear referencias circulares: dentro del menú `--accent` se redefine y no puede apuntar a sí mismo. Verificar el contraste de `--muted`; si no llega a 4,5, usar `--text`.

### 6.5 Marca en los layouts

- **`components/brand/BrandMark.tsx`** (isotipo): reemplaza el SVG de `AppLayout.tsx:44`, `PosLayout.tsx:57` y `AuthLayout.tsx`. Sin imagen, pinta la canasta de hoy.
- **`components/brand/BrandLogo.tsx`**: logo horizontal según el fondo (`logo_dark` si el menú o el tema son oscuros, con respaldo en `logo_light`). Sin logo, isotipo + nombre + subtítulo.
- **Textos:** `AppLayout.tsx:51`, `AuthLayout.tsx:40` y `Login.tsx:41` pasan a `brand.display_name`.
- **Ancho del contenido:** `AppLayout.tsx:184`, de `max-w-[1202px]` a `max-w-[var(--content-max)]`.
- **Tipos:** `BrandProps` en `resources/js/types` y `brand: BrandProps | null` en `PageProps`.

### 6.6 Caja

- `Pos/Sale` recibe la prop `posLayout` con `ticket_side`, `catalog_view`, `tile_size`, `total_size`, `rail`, `shortcuts` y `sounds`.
- **Atajos:** el mapa fijo de `Sale.tsx:471` se arma invirtiendo `shortcuts` (`tecla → acción`). Los `ShortcutButton hint="F2"` leen la tecla de ahí.
  - Solo `F1…F12`: una letra chocaría con lo que se escribe en el buscador.
  - `PaymentDialog` conserva sus propios atajos de medio de pago; quedan fuera de alcance.
- **Riel:** `PosLayout` ordena `RAIL` según `pos.rail`. El permiso sigue filtrando después.
- **Densidad:** la caja la ignora (`--density: 1` en `[data-surface='pos']`). Nada que se toque baja de 44 px.
- **Escala:** `pos.ui_scale` pasa por `--ui-scale` (§5.6). Con 130 %, verificar que el tiquete y el botón Cobrar sigan dentro de `h-screen` en 1366 × 768.
- **Sonidos:** dos `Audio` precargados, uno para escaneo correcto y otro para error. Se reproducen solo si `pos.sounds`.

### 6.7 La pantalla de Ajustes

```
resources/js/pages/Settings/
├── Index.tsx                 # pestañas; la activa vive en ?pestana= (preserveState)
├── SettingField.tsx          # texto, número, select, segmentado, casilla, rango
├── tabs/
│   ├── BrandTab.tsx          # nombre, color (muestras + hex + aviso de contraste), 3 imágenes
│   ├── AppearanceTab.tsx     # tema, menú, fuentes, escala, esquinas, densidad, ancho
│   ├── NavigationTab.tsx     # editor de menú (orden, nombre, ocultar) + inicio por rol
│   ├── PosTab.tsx            # tema, escala, disposición, riel, atajos, sonido
│   ├── PosRulesTab.tsx       # lo de hoy
│   ├── ReceiptTab.tsx        # selector «Editando: empresa / sede», chips «Propio de la sede»
│   └── CreditTab.tsx         # lo de hoy
└── preview/
    ├── PanelPreview.tsx
    ├── PosPreview.tsx
    └── ReceiptPreview.tsx
```

- **Guardado por pestaña.** Cada pestaña tiene su `useForm`, su `PUT` y su permiso. La maqueta tenía un solo botón; aquí va uno por pestaña, pegado abajo. Al cambiar de pestaña con cambios sin guardar, se avisa.
- **Pestañas sin permiso:** no se pintan. Si no hay ninguna editable, la página queda de solo lectura, como hoy.
- **Listas de opciones** (fuentes, roles de la empresa, páginas de inicio, sedes) llegan **por props**, no como arrays en el `.tsx` (regla del proyecto).
- **Vista previa sin `iframe`:** `<div data-theme={modo} style={varsDelBorrador}>` con componentes reales (`Button`, `Card`, `Table`). Las variables en línea del elemento ganan sobre `#brand-theme`. Se puede mostrar claro y oscuro lado a lado. El `iframe` solo haría falta para probar anchos de pantalla.
- **Color:** `lib/color.ts` repite `resolveAccent` para avisar mientras se arrastra el selector. El servidor vuelve a calcular al guardar.
- **Imágenes:** cada slot sube en el acto (`POST`) y muestra la miniatura. Se quita con `DELETE`. No esperan al botón Guardar.
- **Excepciones por sede:** con «Editando: Sede Norte», un campo tocado queda «Propio de la sede» y ofrece «Usar el de la empresa», que manda `null` para esa clave.

---

## 7. Migración de estilos: de `px` a tokens

Es el paso que habilita escala, esquinas y densidad. **Hacerlo en un PR propio, sin mezclar funcionalidad**, para revisarlo solo con capturas.

### 7.1 `app.css`

```css
:root {
    --brand-font-body: 'IBM Plex Sans';
    --brand-font-display: 'Bricolage Grotesque';
    --brand-font-numeric: 'IBM Plex Mono';
    --r-chip: 8px;
    --r-control: 10px;
    --r-card: 14px;
    --r-sheet: 20px;
    --density: 1;
    --content-max: 75.125rem;
    --ui-scale: 1;
}

html {
    /* La escala mueve todo lo que esta en rem; los cortes md:/lg: no, porque en una media query el rem es el del navegador. */
    font-size: calc(100% * var(--ui-scale));
}

@theme inline {
    --font-sans: var(--brand-font-body), ui-sans-serif, system-ui, sans-serif;
    --font-display: var(--brand-font-display), var(--brand-font-body), sans-serif;
    --font-mono: var(--brand-font-numeric), ui-monospace, monospace;
    --radius-chip: var(--r-chip);
    --radius-control: var(--r-control);
    --radius-card: var(--r-card);
    --radius-sheet: var(--r-sheet);
}
```

`body` y `h1–h3` pasan a `var(--brand-font-*)` directamente. No dependas de que `@theme inline` emita `--font-sans` en `:root`.

### 7.2 Codemod

`scripts/codemod-units.mjs`. Es idempotente; se revisa con `git diff`.

```js
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

// Radios de hoy agrupados en los cuatro tokens (ver distribución en §3).
const radius = (px) => (px <= 8 ? 'chip' : px <= 11 ? 'control' : px <= 16 ? 'card' : 'sheet');
const rem = (px) => `${+(px / 16).toFixed(5)}rem`;

const SIZE = /\b((?:[a-z0-9-]+:)*)(text|w|h|size|min-w|max-w|min-h|max-h|gap|gap-x|gap-y|p|px|py|pt|pb|pl|pr|m|mx|my|mt|mb|ml|mr|top|left|right|bottom|inset|leading)-\[(\d+(?:\.\d+)?)px\]/g;
const ROUND = /\b((?:[a-z0-9-]+:)*)rounded(-[tblr]{1,2})?-\[(\d+(?:\.\d+)?)px\]/g;

function walk(dir, out = []) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) walk(path, out);
        else if (path.endsWith('.tsx') || path.endsWith('.ts')) out.push(path);
    }
    return out;
}

for (const file of walk('resources/js')) {
    const before = readFileSync(file, 'utf8');
    const after = before
        .replace(SIZE, (_, variant, util, px) => `${variant}${util}-[${rem(+px)}]`)
        .replace(ROUND, (_, variant, side = '', px) => `${variant}rounded${side}-${radius(+px)}`);
    if (after !== before) writeFileSync(file, after);
}
```

- `border-[Npx]`, `ring-[Npx]`, `outline-[Npx]` y sombras **se quedan en px**: un borde de 1 px no debe engordar.
- A escala 100 % el cambio de tamaños es **idéntico** (13px = 0,8125rem).
- Los radios sí se mueven ±2 px (9px → 10, 11px → 10, 12px → 14, 13px → 14): revisar con capturas.
- `resources/js/components/storefront/**` y `pages/Storefront/**` se excluyen del codemod si la tienda debe quedar intacta. Recomendado: excluirlos en esta fase.
- Clases que se arman con plantillas (`` `rounded-[${x}px]` ``) no las toca el codemod; buscarlas a mano con `grep -rn "\-\[\${" resources/js`.

### 7.3 Densidad

No se aplica en las 1.193 clases: solo en los componentes compartidos de `components/ui`. Ahí se multiplica por `var(--density)`:

| Componente | Qué cambia |
|---|---|
| `Card` | Padding |
| `Table` | Alto de fila |
| `Field` | Alto del control; nunca menos de 2,75rem en la caja |
| `Button` | Alto `md` |

Compacta = `0.8`. Es honesto decir que las pantallas con espaciados propios no cambian: la densidad afecta formularios, tablas y tarjetas, que es donde se nota.

---

## 8. Rutas, permisos y FormRequests

### 8.1 Rutas

Reemplazan el `PUT ajustes` de `routes/web.php:312`, en el mismo bloque. Rutas fijas **antes** de las que llevan parámetro.

```php
// Ajustes
Route::get('ajustes', [SettingController::class, 'index'])->middleware('permission:settings.index.view')->name('settings.index');

Route::put('ajustes/marca', [BrandSettingController::class, 'update'])->middleware('permission:settings.index.brand')->name('settings.brand.update');
Route::post('ajustes/marca/imagenes/{slot}', [BrandImageController::class, 'store'])->middleware('permission:settings.index.brand')->whereIn('slot', ['mark', 'logo_light', 'logo_dark'])->name('settings.brand.images.store');
Route::delete('ajustes/marca/imagenes/{slot}', [BrandImageController::class, 'destroy'])->middleware('permission:settings.index.brand')->whereIn('slot', ['mark', 'logo_light', 'logo_dark'])->name('settings.brand.images.destroy');
Route::put('ajustes/apariencia', [AppearanceSettingController::class, 'update'])->middleware('permission:settings.index.brand')->name('settings.appearance.update');
Route::put('ajustes/menu', [NavigationSettingController::class, 'update'])->middleware('permission:settings.index.brand')->name('settings.navigation.update');

Route::put('ajustes/caja', [PosSettingController::class, 'update'])->middleware('permission:settings.index.pos_layout')->name('settings.pos.update');
Route::put('ajustes/caja/reglas', [PosRulesSettingController::class, 'update'])->middleware('permission:settings.index.edit')->name('settings.pos_rules.update');
Route::put('ajustes/tiquete', [ReceiptSettingController::class, 'update'])->middleware('permission:settings.index.pos_layout')->name('settings.receipt.update');
Route::put('ajustes/tiquete/sedes/{store}', [ReceiptSettingController::class, 'updateStore'])->middleware('permission:settings.index.pos_layout')->name('settings.receipt.store.update');

Route::put('ajustes/credito', [CreditSettingController::class, 'update'])->middleware('permission:settings.index.edit')->name('settings.credit.update');
```

- `{store}` se resuelve con `CompanyScope`. Una sede de otra empresa da 404, y va con prueba.
- La ruta vieja `settings.update` se elimina, junto con sus usos y pruebas.

### 8.2 Permisos

`PermissionHelper`, módulo `settings`, página `index`:

```php
'actions' => ['view', 'edit', 'brand', 'pos_layout'],
'labels' => [
    'view' => 'Ver los ajustes',
    'edit' => 'Cambiar reglas de caja y crédito',
    'brand' => 'Cambiar marca, apariencia y menú',
    'pos_layout' => 'Cambiar la caja y el tiquete',
],
```

- `supervisorPermissions()`: agregar `settings.index.brand` a `$excludedPermissions`. La marca es decisión del administrador.
- **Migración de reparto** (patrón de `2025_01_01_000800_grant_sales_history_permission.php`). Crea los dos permisos y se los entrega a los roles `super_admin` y `admin` y a **todo usuario que hoy tenga `settings.index.edit` directo**. Sin esto, nadie podría entrar a las pestañas nuevas en una base en uso.

```php
User::query()
    ->whereHas('permissions', fn ($q) => $q->where('name', 'settings.index.edit'))
    ->get()
    ->each(fn (User $user) => $user->givePermissionTo([$brand, $posLayout]));
```

### 8.3 FormRequests

Un FormRequest por acción (regla del proyecto). Las reglas salen de `SettingsSchema::rulesFor()` y los mensajes van en español.

| Request | Extra sobre el esquema |
|---|---|
| `UpdateBrandSettingsRequest` | Contraste de `brand.accent_dark` si viene |
| `StoreBrandImageRequest` | `mimes`, `max:2048`, dimensiones por slot |
| `UpdateAppearanceSettingsRequest` | — |
| `UpdateNavigationSettingsRequest` | Formato `^[a-z0-9_.]+$` en claves; `hidden` sin `LOCKED`; labels máx. 30; `home_by_role` contra `HomeResolver::PAGES` y roles de la empresa |
| `UpdatePosSettingsRequest` | `rail` es permutación; `shortcuts` completos y sin repetidos |
| `UpdatePosRulesSettingsRequest` | Las reglas de hoy |
| `UpdateReceiptSettingsRequest` | Encabezado vs. papel efectivo de **cada** sede sin excepción de papel |
| `UpdateStoreReceiptSettingsRequest` | Solo claves con alcance de sede; `null` = quitar la excepción; encabezado vs. papel efectivo de esa sede |
| `UpdateCreditSettingsRequest` | La regla de hoy |

Mensajes de ejemplo:

- `'La tecla F4 está en dos acciones: suspender venta y retomar venta.'`
- `'La línea 2 tiene 41 caracteres; en papel de 58 mm caben 32.'`
- `'Ajustes vive en Empresa: si lo ocultas, nadie podría volver a esta pantalla para deshacerlo.'`

---

## 9. Fases, entregables y criterios de aceptación

Cada fase cierra con:

```bash
npx tsc --noEmit
npm run build
php artisan test
```

Y, si tocó migraciones o seeders: `php artisan migrate:fresh --seed`.

### Fase 0 — Cimientos (sin cambio visible)

- [ ] `App\Support\Color`; `StorefrontPublisher` delega en ella. Las pruebas de la tienda siguen verdes.
- [ ] `lib/color.ts` con las mismas funciones y una prueba de paridad: mismos casos, mismo resultado.
- [ ] `SettingsSchema` y `DEFAULTS` ampliados; prueba de claves iguales.
- [ ] Tabla `store_setting_overrides`, su modelo y `SettingsService::forStore/setForStore`.
- [ ] `BrandThemeService` + prop `brand` + inyección en `app.blade.php` + `BrandStyle` + `useSurface`.

**Aceptación:** con los valores de fábrica, `#brand-theme` sale vacío y capturas del panel y la caja son idénticas a las de antes.

### Fase 1 — Marca

- [ ] Endpoints de imágenes con `storeBrandAsset` en PNG y las variantes.
- [ ] `BrandMark` y `BrandLogo` en `AppLayout`, `PosLayout` y `AuthLayout`; favicon y `theme-color` dinámicos; fuera los literales «La Canasta».
- [ ] Pestaña Marca con vista previa; color con muestras, hex y aviso de ajuste.
- [ ] Permisos `brand` y `pos_layout` + migración de reparto.

**Aceptación:**

- Subir un PNG con transparencia se ve sin fondo en el menú claro y en el oscuro.
- Elegir `#E8B100` (amarillo) deja el texto de acento legible en claro (ajustado) y en oscuro.
- El favicon cambia sin recargar después de guardar.

### Fase 2 — Tiquete

- [ ] Dependencias D6 (tras aprobarlas).
- [ ] `receipt-print` y `pdf/receipt` leen `forStore`.
- [ ] Pestaña Tiquete con «Editando» y excepciones por sede.

**Aceptación:**

- Una sede con 58 mm imprime a 58 mm y las demás a 80 mm.
- Quitar la excepción vuelve al valor de la empresa.
- El código de barras de un tiquete impreso se lee con el escáner de caja.

### Fase 3 — Estilos a tokens y Apariencia

- [ ] PR aislado con el codemod (§7.2) y capturas antes y después de 10 pantallas: inicio, artículos, existencias, compras, caja, cierre, informes, ajustes, ingreso y tienda (esta última no debe cambiar).
- [ ] Fuentes (`config/brand_fonts.php`), escala, esquinas, densidad en `components/ui`, ancho del contenido.
- [ ] Menú oscuro o de marca; menú solo con íconos con menús flotantes.
- [ ] Tema inicial e interruptor por superficie.

**Aceptación:**

- Escala 120 % en el panel no rompe tablas en 1366 px.
- «Rectas» cambia botones, tarjetas, campos y modales, no solo algunos.
- Con el interruptor oculto, una preferencia guardada no se aplica y reaparece al volver a mostrarlo.

### Fase 4 — Menú y caja

- [ ] `applyNavigationOverrides` + pestaña Menú + bloqueo; `HomeResolver` en el ingreso.
- [ ] `posLayout`: lado del tiquete, vista del catálogo, teclas, total, riel, atajos y sonido.

**Aceptación:**

- Renombrar «Mercancía» a «Bodega» y ocultar «Mercadeo» se ve para todos.
- Una pantalla agregada después aparece sin reconfigurar.
- Un cajero entra directo a la caja.
- F3 reasignado a cobrar funciona, y el botón muestra F3.

---

## 10. Pruebas

| Archivo | Qué prueba |
|---|---|
| `SettingsSchemaTest` | `DEFAULTS` y `SettingsSchema::definitions()` tienen las mismas claves; toda clave no gestionada tiene reglas; toda clave de alcance sede está en la pestaña Tiquete |
| `BrandThemeServiceTest` | De fábrica → `css === ''`. Amarillo → acento claro con contraste ≥ 4,5 contra superficie, fondo y tinte. `accent_dark` inválido se rechaza. El CSS solo contiene valores de la lista blanca: un nombre con `</style>` no llega al CSS |
| `BrandSettingsTest` | `PUT /ajustes/marca` valida y guarda; un hex inválido da error en español; sin permiso `brand` → 403 |
| `BrandImageTest` | PNG → variantes creadas y `brand.mark` con rutas; reemplazar borra lo anterior; un `.svg` se rechaza; isotipo de 100 px se rechaza. Usar `->create('x.png', 120, 'image/png')` si no hay GD |
| `NavigationSettingsTest` | Ocultar `company` o `admin` → 422; claves raras se aceptan pero no rompen; `home_by_role` con ruta fuera de `PAGES` → 422 |
| `HomeResolverTest` | Cajero configurado a `pos.index` entra a la caja; sin permiso de caja cae en la primera accesible; `intended` sigue mandando |
| `PosSettingsTest` | Atajo repetido → 422 con el mensaje; `rail` incompleto → 422 |
| `ReceiptSettingsTest` | Excepción de sede gana; `null` la quita; encabezado de 45 caracteres con una sede en 58 mm → 422; sede de otra empresa → 404; el HTML del tiquete respeta ancho, campos y copias |
| `SettingsPermissionMigrationTest` | Quien tenía `settings.index.edit` recibe `brand` y `pos_layout`; un supervisor no recibe `brand` |
| `RouteSmokeTest` | Pasa sin eximir nada. `settings.index` debe traer todas sus props (incluida la lista de sedes para «Editando») |

---

## 11. Diferencias con la maqueta

La maqueta simplificó a propósito para caber en la conversación. Al implementar:

| En la maqueta | Definitivo |
|---|---|
| Claves `admin.*` para fuentes, radios y densidad | `appearance.*` (compartidas panel+caja) y `panel.*` (§4.2) |
| `admin.navigation`, `admin.home_by_role` | `panel.navigation`, `panel.home_by_role` |
| Un botón «Guardar ajustes» para todo | Un guardado por pestaña, cada una con su permiso |
| El acento se ajusta solo contra la superficie | Contra superficie, fondo y tinte suave (§5.5) |
| NIT e IVA discriminado se podían apagar | Fijos (D9); los campos opcionales son dirección, teléfono, cajero, vendedor y cliente |
| Tiquete con logo y código de barras por defecto | Defectos = lo de hoy: sin logo y sin código |
| Las imágenes se guardan al pulsar Guardar | Se suben en el acto, cada una con su endpoint |
| Sin color oscuro manual | `brand.accent_dark` opcional (avanzado) |
| Menú: claves de enlaces sueltos como `pos`, `reports` | La clave del enlace suelto es su `routeName` (`pos.index`, `reports.index`) |

---

## 12. Reglas nuevas para `CLAUDE.md`

Texto propuesto para «Trampas conocidas»:

```markdown
- **Marca del panel y la caja**: vive en ajustes (`brand.*`, `appearance.*`, `panel.*`, `pos.*`), no en
  `companies.logo` ni en literales. `BrandThemeService` la vuelve tokens y el blade la inyecta en
  `#brand-theme` **despues** de `@vite`; `BrandStyle` la mantiene al dia en las visitas de Inertia. Si
  la empresa no toco el color, no se inyecta nada y manda `app.css`.
- **Color de marca**: la interfaz nunca usa el color crudo si no se lee. `resolveAccent` lo acerca a negro
  o a blanco hasta 4,5:1 contra superficie, fondo y tinte. `App\Support\Color` y `lib/color.ts` hacen la
  misma cuenta: si cambias una, cambia la otra.
- **CSS de marca**: se arma solo con valores validados (hex por regex, enums). Nunca metas texto libre en
  `#brand-theme`: se imprime con `{!! !!}`.
- **Tamaños y esquinas**: nada de `text-[13px]` ni `rounded-[10px]` nuevos. Tamaños en `rem` (escalan con
  `--ui-scale`) y esquinas con `rounded-chip/control/card/sheet`. Los bordes si van en px.
- **Menu configurable**: `navigation.ts` es el catalogo; la empresa solo guarda diferencias. La
  configuracion oculta, el permiso filtra despues, y nunca al reves. `admin` y `company` no se pueden
  ocultar: ahi vive Ajustes.
- **Ajustes por sede**: solo los del tiquete, en `store_setting_overrides`, no en `settings` con
  `store_id` nulo (MariaDB deja repetir `NULL` en un indice unico). Se leen con `forStore()`.
- **Imagenes de marca**: `storeBrandAsset` guarda PNG con transparencia; `store()` guarda JPEG y le pone
  fondo al logo. SVG no se acepta sin saneador.
- **Tema por superficie**: el inicial sale de `panel.default_theme` / `pos.default_theme`, no de la ruta.
  Si agregas una pantalla con `PosLayout`, sumala igual al patron de `app.blade.php`: de ahi sale
  `data-surface`, y sin el se pinta con la escala y el tema del panel un instante.
```

---

## 13. Hallazgos colaterales

Encontrados al revisar el código para este diseño. No bloquean, pero conviene saberlos.

1. **La verificación de contraste de la tienda nunca rechaza nada.**
   - `ThemeController.php:41` rechaza si `contrast($accent) < 4.5`, y `AppearanceTab.tsx:117` hace lo mismo en el navegador.
   - Pero `contrast()` devuelve el **mayor** entre «contra blanco» y «contra negro». El producto de los dos siempre es 21, así que el mayor nunca baja de √21 ≈ 4,58.
   - Efecto: el aviso «no se guarda» es código muerto, y un acento claro (amarillo) usado como **texto** sobre blanco (precios, enlaces) pasa sin control. La letra sobre el botón sí se lee, porque `ink()` elige bien.
   - Arreglo: medir el acento contra la superficie de la tienda, como §5.5.
2. **`ImageService::encode()` guarda JPEG.** Sirve para fotos de artículos; para logos pone fondo negro o blanco donde había transparencia.
3. **`companies.logo`** existe, se comparte como `activeCompany.logo` y ningún componente lo lee. No revivirlo; retirarlo en una limpieza.
4. **`StoreLayout.tsx:58`** tiene «La Canasta» escrito a mano en la tienda en línea, que ya tiene `display_name` en su tema. Está fuera de alcance, pero es el mismo síntoma.
5. **Atajos de la caja:** F8 y F12 cobran los dos (`Sale.tsx:476-477`). La configuración lo conserva como `pay` y `pay_alt`. Si nadie usa F8, se puede liberar.
