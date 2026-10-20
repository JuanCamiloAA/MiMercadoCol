# PROMPT — 12 plantillas nuevas de tienda y pestaña «Diseño» por sección y por banner (tienda y landing)

> **Para:** Claude Opus, trabajando en `C:\xampp\htdocs\MiMercadoCol` (Laravel 12 + Inertia 2 + React 19 + TS estricto).
> **Fecha:** 2026-10-15.
> **Datos de las plantillas:** `context/plantillas-tienda.json` (va junto a este archivo). Trae las 12 plantillas ya traducidas a parámetros: tema, estructura y bloques en orden.
> **Referencia visual:** la galería que aprobó el dueño (artifact «Plantillas Ventaio»). Las plantillas de la familia «Estilo Aura» siguen la línea de `aura.toastore.com`: **se toma el estilo, no la marca**.
> **Referencia de la pestaña «Diseño»:** el editor de la landing pública de **miTallerCol** (`C:\xampp\htdocs\ERP_CONFECCION`):
> - `config/landing_appearance.php`: el catálogo de opciones.
> - `resources/js/Components/Public/appearance.ts` y `BlockShell.tsx`: su traducción a CSS, el paralaje y la animación de entrada.
>
> Léelos antes de empezar. Aquí se replica **la idea y el comportamiento**, adaptados a las dos superficies de este proyecto.

---

## 0. Cómo trabajar

1. **Lee primero `CLAUDE.md` y `README.md`.** Mandan. Las reglas que más pesan aquí:
   - Bloque nuevo de tienda = clase en `app/Storefront/Blocks/` + `BlockCatalogue::TYPES` + render en `StoreBlocks.tsx` + su `emptyReason`.
   - Bloque nuevo de landing = clase en `app/Landing/Blocks/` + `LandingBlockCatalogue` + `LandingBlocks.tsx`.
   - El editor arma los formularios **solo** con `fields()`. Ningún campo se escribe a mano en el `.tsx`.
   - La vista previa va en `iframe`.
   - La tienda es responsiva sin columna de celular.
   - **Plantilla:** diseño = `StorefrontTemplate::DESIGN_KEYS`. La marca, el domicilio, los pagos, el contacto y los datos legales son de la sede. Una plantilla nunca guarda ids de artículos, categorías ni promociones (`fields('template')`).
   - **Medios de plataforma:** `company_id` nulo. Independizarse los copia a la biblioteca de la empresa (`copyPlatformMedia`).
   - **Íconos de la tienda:** uno por uno en `components/storefront/icons.tsx`, más su nombre en `config/storefront_icons.php`.
   - **Videos:** siempre con `useLoopingVideo`/`VideoFrame` y `App\Support\VideoOptions`.
   - Nada de arrays literales en `.tsx` ni de botones sin acción.
2. **Verifica antes de escribir.** Las referencias salen del código del 2026-10-15. Si algo cambió, adapta y dilo.
3. **Trabaja por fases (§8).** Cada fase cierra con:

   ```bash
   npx tsc --noEmit
   npm run build
   php artisan test
   php artisan migrate:fresh --seed
   ```

   Más el recorrido manual en **celular y computador**, con capturas. Reporta al cerrar cada fase.
4. **No rompas lo que funciona.**
   - Una tienda o landing que nunca toque la pestaña «Diseño» **se ve exactamente igual que hoy**.
   - Siguen funcionando: versiones, publicación, historial, «copiar a otras sedes», seguir la plantilla, independizarse y volver.
   - Siguen funcionando: checkout, carrito, buscador de tiendas y videos.
5. **Si algo no está decidido aquí, pregunta.** No inventes comportamiento.

---

## 1. Qué pidió el dueño

1. **Implementar las 12 plantillas elegidas** en el apartado de plantillas del super usuario (**Plataforma › Plantillas de tienda**), con su migración y su seeder. Todo lo que muestran debe quedar parametrizable y editable desde el **Editor de la tienda**.
   - Primera tanda: **Ahorro, Despensa, Lookbook, Barrio, Taller, Noir, Huerta**.
   - Estilo Aura: **Vitrina, Pasarela, Urbano, Sastre, Botánica**.
   - No se implementan **Atelier** ni **Costa**.
2. **Cada sección y cada banner editable en diseño**, en el **Editor de la tienda** y en el **Editor de la landing** (Plataforma › Landing):
   - tamaño (por ejemplo 100 % de la pantalla en alto y de borde a borde en ancho);
   - fondo, encuadre, velo, desenfoque, paralaje y animación de entrada.

   La referencia es la pestaña «Diseño» de miTallerCol. La captura que mandó el dueño muestra estos controles:
   - **Contenido | Diseño.**
   - **Tamaño y espacio:** alto de la sección, contenido en vertical, ancho del contenido, espacio arriba y espacio abajo.
   - **Fondo:** tipo de fondo, imagen de fondo, ajuste, encuadre, fondo fijo al desplazar, desenfoque, velo y paralaje.
   - **Animación al aparecer.**

> **Supuesto que tomé:** «apartado de plantillas de landing» se lee como **Plataforma › Plantillas de tienda** (`storefront_templates`, página `Platform/StoreTemplates`). Es donde viven las plantillas que adoptan las tiendas. La landing de plataforma no tiene plantillas: recibe la pestaña «Diseño». Si el dueño quería otra cosa, para y pregunta antes de la Fase 4.

---

## 2. Lo que hay hoy y se aprovecha

| Pieza | Dónde | Nota |
|---|---|---|
| Plantillas | `storefront_templates` (`draft`, `published`, `is_default`, `is_active`, `preview_media_id`) + `storefront_template_versions`; `StorefrontTemplateService` (`create`, `publish`, `setDefault`, `apply`, `detach`, `reattach`, `copyPlatformMedia`) | Hoy solo existe «Supermercado», sembrada por `StorefrontTemplateSeeder`, que se queda como la plantilla por defecto |
| Reparto diseño/sede | `StorefrontTemplate::DESIGN_KEYS` | El tema de la plantilla solo trae `scheme, allow_scheme_toggle, font_pair, radius, density, button_style`. **El color es de la sede** |
| Bloques de tienda | `app/Storefront/Blocks/*BlockData`, `BlockData::normalize/upgrade/violations/issues/mediaIds/toCatalogue`, `BlockCatalogue` | `fields($context)` con `CONTEXT_STORE` / `CONTEXT_TEMPLATE` |
| Fondo de bloque (tienda) | `App\Storefront\Blocks\BlockBackground` (`background`: none/soft/dark/custom + `background_color`), `blockSurface()` en `VideoBlocks.tsx` | **Lo reemplaza la pestaña Diseño** (§4.6) |
| Bloques de landing | `app/Landing/Blocks/*`, `LandingBlock::normalize/withMediaUrls/mediaUrlMap/toCatalogue`, `landing_blocks.data` | Las imágenes de la landing son rutas bajo `landing/` |
| Fondo de sección (landing) | `App\Landing\Blocks\LandingBackground` (`tone`: light/dark/custom + `background_color`), `sectionSurface()` en `landing-ui.tsx` | **Lo reemplaza la pestaña Diseño** (§4.6) |
| Tema de la tienda | `StoreTheme.tsx` (`FONTS`, `RADIUS`, variables `--accent`, `--deal`, `--store-*`), `StorefrontStructure` (`CHOICES`, `HEADER_STYLES`, `TOPBAR_TONES`, `LOGO_MODES`), `StorefrontManager::defaultSettings()` | Aquí entran los valores nuevos (§5) |
| Pintor de imágenes de ejemplo | `database/seeders/Support/BannerPainter.php` (GD, motivos `fruits` y `garments`), `GarmentPainter.php` | Aquí entran los motivos nuevos de las plantillas (§7.2) |
| Editor de la tienda | `pages/Storefront/Editor/*` (`ContentTab`, `AppearanceTab`, `StructureForms`, `BlockFields`, `PreviewFrame`) | El panel de un bloque gana las pestañas Contenido/Diseño |
| Editor de la landing | `pages/Platform/Landing/Editor.tsx` + `Fields.tsx` | Igual |

---

## 3. Decisiones (cerradas)

1. **Un solo catálogo de apariencia para las dos superficies:** `App\Support\SectionAppearance`. Vale para la tienda y la landing, con la misma lista de opciones, los mismos defectos y la misma traducción a CSS. Solo cambia cómo se guarda una imagen: en la tienda un `media_assets.id` y en la landing una ruta bajo `landing/`. Lo resuelve el parámetro `$surface` (§4.2).
2. **Dónde se guarda:**
   - Tienda: `payload.appearance` del bloque (`storefront_blocks.payload`). Así se versiona, se publica, se copia a otras sedes y viaja en las plantillas sin columnas nuevas.
   - Landing: `data.appearance` (`landing_blocks.data`). Igual que en miTallerCol.
3. **El diseño de cada banner** (lámina de portada, mosaico, tarjeta alta, lateral) se guarda en `style` dentro de su elemento del repetidor (§4.5).
4. **Los colores de marca no viajan en la plantilla.** Cada plantilla trae una `palette_suggestion` (acento y oferta). La sede la aplica con un botón en Aspecto, «Usar los colores sugeridos por la plantilla», que guarda en su `storefront_themes` como cualquier cambio de color y muestra el aviso de contraste de siempre. Nunca se aplica sola.
5. **`BlockBackground` y `LandingBackground` se retiran** en favor de `appearance`, con una migración de datos y `upgrade()` para las instantáneas viejas (§4.6).
6. **«Supermercado» sigue siendo la plantilla por defecto.** Las 12 nuevas quedan publicadas y activas, sin ser la predeterminada.

---

## 4. Pestaña «Diseño» (secciones y banners)

### 4.1 Catálogo de apariencia de la sección

Cada sección (bloque) guarda un objeto `appearance`. **Una clave vacía o ausente cae al defecto del tipo de bloque** (`appearanceDefaults()`), igual que `resolveAppearance` en miTallerCol. Así lo ya publicado no cambia.

**Tamaño y espacio**

| Clave | Tipo | Opciones (valor → efecto) | Nota |
|---|---|---|---|
| `height` | select | `auto` (según el contenido), `third` 38svh, `half` 55svh, `tall` 78svh, `screen` **100svh** | Es un alto mínimo: si el contenido es más largo, la sección crece |
| `height_mobile` | select | `same` (como en computador), `auto`, `half`, `tall`, `screen` | Nueva respecto a miTallerCol: en celular un 100svh casi nunca conviene |
| `align` | select | `start`, `center`, `end` | Contenido en vertical cuando sobra alto |
| `frame` | select | `contained`: tarjeta dentro del ancho de la página, con las esquinas del tema. `bleed`: **de borde a borde**, sin margen lateral ni esquinas | Es el «100 % horizontal». Con `bleed` el fondo y el bloque ocupan todo el ancho |
| `width` | select | `narrow` 48rem, `normal` (el `--cw` de la tienda / 72rem en la landing), `wide` 88rem, `full` 100 % | Ancho del **contenido**. Con `full` y `bleed` el contenido también toca los bordes (banners sin margen) |
| `pad_top` / `pad_bottom` | select | `none`, `xs`, `sm`, `md`, `lg`, `xl` (escala `clamp()` como en miTallerCol) | `md` es **exactamente** el espacio que usa hoy cada superficie entre secciones. Mídelo y fíjalo así |
| `gap` | select | `none`, `sm`, `md`, `lg` | Separación entre piezas internas (mosaicos, tarjetas, columnas). `md` = la de hoy (`--store-gap`). `none` = banners pegados |
| `radius` | select | `theme`, `none`, `lg` | Esquinas de la sección cuando `frame = contained` |

**Fondo**

| Clave | Tipo | Opciones | Nota |
|---|---|---|---|
| `bg_type` | select | `none`, `color`, `gradient`, `image`, `video`, `texture` | |
| `bg_color` | color | Tokens: tienda `page`, `soft`, `brand`, `brand_dark`, `deal`, `dark`, `light`; landing los de su tema. O un **hex** propio | Con textura o degradado es el color de base. Los tokens salen del tema de la sede (tienda) o del de la plataforma (landing), nunca del panel |
| `bg_gradient` | select | `brand` (fondo → marca), `soft`, `dark`, `halo` (centro claro), `sunset` | |
| `bg_texture` | select | `dots`, `grid`, `lines`, `noise`, `glow` | |
| `bg_media_id` / `bg_media_mobile_id` | media | Solo tienda | La de celular es opcional; si falta, se recorta la de computador |
| `bg_image` / `bg_image_mobile` | image | Solo landing | Ruta bajo `landing/`; las URL las pone `withMediaUrls` |
| `bg_video_media_id` / `bg_video` | video | Tienda / landing | Con `VideoOptions` (sin sonido, en bucle, solo mientras se ve); en «menos movimiento» se queda en la primera imagen |
| `bg_fit` | select | `cover`, `contain`, `repeat` | Ajuste |
| `bg_position` | select | `center`, `top`, `bottom`, `left`, `right` | Encuadre |
| `bg_fixed` | toggle | | Fondo fijo al desplazar. Se apaga solo si hay paralaje |
| `bg_blur` | range | 0–24 px, de 2 en 2 | Desenfoque (la capa se agranda un poco para tapar los bordes) |
| `overlay` | range | 0–90 %, de 5 en 5 | Velo sobre el fondo |
| `overlay_color` | select | `dark`, `light`, `brand` | Nueva respecto a miTallerCol |
| `parallax` | select | `none`, `soft`, `medium`, `strong` | Mismo cálculo que `BlockShell` |
| `ink` | select | `auto`, `light`, `dark` | Color del texto. `auto` lo escoge según el fondo con `ink()`, la misma función que ya calcula `--accent-ink` |

**Animación al aparecer:** `anim`, `anim_speed`, `anim_stagger`, `anim_delay`, `anim_once`. Las mismas opciones y el mismo comportamiento que miTallerCol:

- con `IntersectionObserver`;
- sin JavaScript nunca queda contenido invisible (clase `*-js` en `<html>`);
- con `prefers-reduced-motion` no anima.

El catálogo declara grupos (`size`, `background`, `animation`) con `label`, `icon`, `help` y campos con `show_if`, exactamente como `config/landing_appearance.php`. **El editor pinta la pestaña desde el catálogo:** agregar un ajuste no obliga a tocar React.

### 4.2 Backend

- **`app/Support/SectionAppearance.php`:**
  - `groups(string $surface): array`, el catálogo para el editor, con `$surface` = `store` | `landing`; los campos de imagen y video cambian según la superficie.
  - `normalize(array $saved, array $defaults, string $surface): array`, que limpia con la misma lógica de `cleanSelect`/`cleanNumber` y descarta claves desconocidas.
  - `violations(...)`: hex válido; media de la empresa o de plataforma; video con `mime video/*`.
  - `KEYS`.
- **`BlockData`:**
  - `appearanceDefaults(): array`, neutro por defecto, que cada tipo sobrescribe para conservar su aspecto de hoy. Ejemplo: `hero` = `frame contained`, `pad_top sm`; los videos heredan lo que hoy decide su `background`.
  - `normalize()` normaliza `payload.appearance`.
  - `toCatalogue()` agrega `appearance_defaults`.
  - `mediaIds()` ya encuentra `bg_media_id`, `bg_media_mobile_id` y `bg_video_media_id` porque terminan en `_media_id`. **Verifica** que publicar, copiar a otras sedes, independizarse (`rewriteMedia`) y `issues()` (imagen pesada, sin descripción) los cubran. Si no, súmalos.
- **`LandingBlock`:** lo mismo (`appearanceDefaults()`, `normalize`, `withMediaUrls` también dentro de `appearance` y `mediaUrlMap`).
- **Editor de la tienda y de plantillas:** el `payload` del bloque ya viaja entero por `block_update`, así que `appearance` entra por ahí. Valida con `SectionAppearance::violations` en el mismo `FormRequest` del bloque.
- **Editor de la landing:** igual, por el endpoint actual de guardar bloque.
- Las props del editor suman `appearanceCatalogue` (de `groups($surface)`) y `appearanceDefaults` por tipo.

### 4.3 Front compartido

- **`resources/js/components/sections/appearance.ts`:**
  - `resolveAppearance(defaults, saved)`;
  - `shellStyle` (variables `--sec-min`, `--sec-min-m`, `--sec-align`, `--sec-max`, `--sec-pt`, `--sec-pb`, `--sec-gap`, `--sec-radius`);
  - `backgroundStyle`, `overlayStyle`, `parallaxFactor` y `animates`.

  Es una traducción directa del `appearance.ts` de miTallerCol, con las claves nuevas. Los tokens de color se resuelven contra las variables de **cada superficie**: en la tienda `--accent`, `--brand-dark`, `--deal`, `--soft`; en la landing, las de `landing-ui`.
- **`SectionShell.tsx`:** la envoltura común, como `BlockShell`:
  - capa de fondo (imagen, `picture` con la versión de celular, video con `VideoFrame`, textura o degradado);
  - velo, paralaje con `requestAnimationFrame` en captura (el editor desplaza dentro del iframe);
  - revelado y `part(i)` para el escalonado.
- **CSS:** `resources/css/sections.css`, importado por la tienda y por la landing. Usa `@media (max-width: 767px)` para `--sec-min-m`.
- **`StoreBlocks.tsx` y `LandingBlocks.tsx`:** cada bloque se envuelve en `SectionShell`. Quita a mano los márgenes, anchos y fondos que hoy pone cada bloque y que ahora decide la envoltura. **El resultado sin tocar Diseño debe ser idéntico** (§9, prueba de no regresión visual).
- Con `frame = bleed`, el bloque no usa el contenedor de la página. Con `width = full` y `gap = none`, los mosaicos quedan pegados de borde a borde, como los banners de Aura.

### 4.4 Editor: pestañas Contenido | Diseño

- **`resources/js/components/sections/DesignPanel.tsx`**, compartido por los dos editores. Pinta los grupos del catálogo como acordeones («Tamaño y espacio», «Fondo», «Animación al aparecer»), con `show_if`. Los controles son:
  - `select`;
  - `toggle`;
  - `range` con su valor y unidad a la derecha (`0px`, `35%`);
  - `color` con las muestras de los tokens más un hex;
  - `media` o `image` con el selector de la biblioteca (tienda: el mismo de `BlockFields`; landing: `ImageField`), con «Subir imagen» y «Quitar».
- Abajo va **«Restablecer el diseño»**, que borra `appearance` y vuelve al defecto del tipo.
- **Editor de la tienda:** el panel del bloque seleccionado (hoy en `ContentTab` + `BlockFields`) gana las pestañas **Contenido** (los `fields()` de hoy) y **Diseño**. El guardado es el mismo autoguardado y la vista previa refleja el cambio en vivo. El revelado se repite al cambiar un ajuste (`replayKey`).
- **Editor de la landing (`Platform/Landing/Editor.tsx`):** lo mismo en el panel del bloque. Los campos `tone`/`background_color` que hoy salen en la sección «Diseño» de `fields()` desaparecen, porque los reemplaza esta pestaña.
- **Permisos:** la pestaña Diseño de la tienda exige el permiso de **aspecto** (`storefront.editor.look` o el que use hoy Aspecto). Sin él no se pinta (nada de pestañas deshabilitadas). En la landing basta `platform.landing.edit`.
- **Plantillas:** el editor de plantillas del super usuario es el mismo editor en contexto `template`, así que también tiene la pestaña Diseño.

### 4.5 Diseño de cada banner (por elemento)

Los repetidores con imagen ganan un subgrupo plegable **«Diseño de esta lámina»** en cada elemento, guardado en `item.style`. Aplica a:

- `hero.slides` y `hero.side`;
- `banner_grid.tiles`;
- `categories.preview_media`, las tarjetas altas (§6);
- `image_text`;
- `look`.

| Clave | Opciones | Efecto |
|---|---|---|
| `ratio` | `auto`, `square` 1:1, `portrait` 3:4, `tall` 3:4.2, `landscape` 16:9, `wide` 21:9 | Proporción del banner (en el `hero` manda el alto de la sección) |
| `text_position` | 9 posiciones: `top-left` … `bottom-right` | Dónde va el texto |
| `title_size` | `sm`, `md`, `lg`, `xl`, `display` | `display` = el título gigante de Aura |
| `text_color` | `light`, `dark` (ya existe en slides; se extiende a mosaicos) | |
| `overlay` + `overlay_style` | 0–60 % · `flat`, `bottom` (degradado abajo), `top` | |
| `button_style` | `solid`, `outline`, `pill_white` (píldora blanca de Aura), `link` (subrayado en mayúsculas) | |
| `media_mobile_id` | (ya existe en algunos) | Se extiende a todos los que tienen imagen |

Se declaran en el `item` del repetidor con `group: 'design'`. El componente de repetidor de `BlockFields` los pinta plegados aparte, así que siguen saliendo de `fields()`.

### 4.6 Retiro de `BlockBackground` y `LandingBackground`

- **Migración de datos:** `2026_10_15_000100_move_section_backgrounds_to_appearance.php`. Recorre `storefront_blocks.payload` (borrador y publicado), `storefront_versions.snapshot`, `storefront_templates.draft/published`, `storefront_template_versions.snapshot`, `landing_blocks.data/published_data` y `landing_versions.snapshot`, y convierte así:

  | Valor viejo | `appearance` |
  |---|---|
  | Tienda `none` | sin `appearance` (defecto) |
  | Tienda `soft` | `bg_type color`, `bg_color soft` |
  | Tienda `dark` | `bg_type color`, `bg_color brand_dark` |
  | Tienda `custom` | `bg_type color`, `bg_color <hex>` |
  | Landing `light` | sin `appearance` (defecto) |
  | Landing `dark` | `bg_type color`, `bg_color dark` |
  | Landing `custom` | `bg_type color`, `bg_color <hex>` |

  Deja `ink: auto`. El `down()` revierte lo que pueda.
- **`upgrade()`:** `BlockData::upgrade()` y un `LandingBlock::upgrade()` nuevo hacen lo mismo al vuelo con instantáneas que llegan sin migrar, por ejemplo al restaurar una versión.
- Borra las clases viejas cuando nada las use, y también `blockSurface`/`sectionSurface` si quedan huérfanos. **`VideoBlocks.tsx` pasa a usar `SectionShell`.**
- `product_grid.background` (`none`/`soft`/`brand`) se migra igual (`soft` → `bg_color soft`, `brand` → `bg_color brand`) y se retira el campo.

---

## 5. Parámetros nuevos de tema y estructura

Todos con su defecto **igual al comportamiento de hoy**. Van en `StorefrontStructure::CHOICES`/constantes, `StorefrontManager::defaultSettings()`, los `FormRequest` de cada clave (`SettingRequests::MAP`) y `StoreTheme.tsx`/componentes.

### 5.1 Tema (`storefront_themes` + `DESIGN_KEYS['theme']`)

| Clave | Valores | Defecto | Notas |
|---|---|---|---|
| `font_pair` | se suman `grotesca` (Archivo/Archivo 900), `minimal` (Manrope 500), `lujo` (Jost + Cormorant Garamond 500), `tecnica` (IBM Plex Sans 700 + IBM Plex Mono para precios: `--store-price-font`), `vitrina` (Ubuntu 700), `urbano` (Archivo + Anton), `sastre` (Inter + Playfair Display 600) | — | En `FONTS` de `StoreTheme.tsx`, con su `href` de Google Fonts |
| `logo_font` | `none`, `italiana`, `cormorant`, `playfair`, `anton` | `none` | Letra del nombre cuando `logo_mode = name`: el nombre en serif fina de Aura |
| `surface` | `white`, `warm` (#F7F2EA), `cool` (#F2F3F5), `sage` (#F3F6EF), `sand` (#F6F0E7) | `white` | Fondo y tarjeta de la tienda; en esquema oscuro manda el oscuro |
| `heading_case` | `normal`, `upper` | `normal` | Títulos en mayúsculas con espaciado |
| `container` | `normal` 1200, `wide` 1320, `full` (sin tope, gutter 48 px) | `normal` | |
| `button_shape` | `default` (el radio del tema), `pill`, `square` | `default` | |

Suma `logo_font, surface, heading_case, container, button_shape` a `DESIGN_KEYS['theme']`. **No** sumes `accent`/`deal_color`: ver §3.4 (`palette_suggestion`).

### 5.2 Estructura (`storefront_settings`)

| Clave | Nuevo | Defecto |
|---|---|---|
| `topbar.tone` | `dark` | — |
| `header.style` | `minimal` (blanco, línea fina), `overlay` (transparente sobre la portada) | — |
| `header.layout` | `search` (el de hoy), `center` (logo centrado, menú debajo), `inline` (logo, menú, buscador e íconos en una fila) | `search` |
| `header.overlay` | bool. Solo en la **portada** y solo si el primer bloque visible es un `hero` con `frame bleed`; si no, se pinta sólido | `false` |
| `header.sticky_style` | `solid`, `blur_dark`, `blur_light`: cómo queda al bajar | `solid` |
| `header.search_style` | `pill` (hoy), `underline`, `icon` | `pill` |
| `nav.style` | `bar` (hoy), `text` (mayúsculas pequeñas, dentro del encabezado si `layout inline`, centrado debajo si `center`), `none` | `bar` |
| `nav.active_style` | `none`, `underline` | `none` |
| `nav.items[].opens_menu` | bool: el elemento abre el `MegaMenu` («Categorías ⌄») | `false` |
| `card.style` | `boxed` (borde), `flat`, `minimal` (solo foto, nombre y precio), `photo` (foto grande con nombre en negrita y descripción) | `boxed` |
| `card.image_ratio` | `1:1`, `3:4`, `4:5` | `1:1` |
| `card.image_bg` | `tint` (suave de la marca) | — |
| `card.add_button` | `icon` («+» sobre la foto), `none` (la tarjeta lleva a la ficha) | — |
| `card.price_size` | `sm` | — |
| `card.text_align` | `left`, `center` | `left` |
| `card.show_swatches` | bool: colores de la familia (`product_families`), máximo 4 | `false` |
| `card.show_description` | bool: una línea con la descripción que ya tenga la ficha (verifica la columna que dejó `add_content_to_products_table`; si está vacía, no se pinta nada) | `false` |
| `card.image_hover` | `none`, `second` (segunda foto de la galería al pasar el mouse; en celular nada) | `none` |
| `footer.style` | `full` (hoy), `minimal` (una línea) | `full` |
| `footer.tone` | `light` (hoy), `dark`, `brand` | `light` |
| `footer.newsletter` | bool. Requiere `newsletter_subscriptions` (§6.5); sin eso, **no** se agrega | `false` |
| `mobile_nav.enabled` | bool | `true` |
| `floating` (**clave nueva**) | `{ whatsapp: bool, position: 'right'|'left' }`. El número sale del contacto de la sede (`footer.contact.whatsapp`); sin número no se pinta | `{ whatsapp: false }` |

Para `floating`: súmalo a `StorefrontSetting::KEYS`, a `SettingRequests::MAP` con su request, a su ruta, a `DESIGN_KEYS['settings']` y al formulario de Estructura en Contenido.

---

## 6. Bloques: variantes y nuevos

Cada cambio: `fields($context)`, `defaults()`, `normalize`, `summary`, `isEmpty` + `emptyReason`, render en `StoreBlocks.tsx` y versión plantilla sin ids.

### 6.1 Variantes de bloques que ya existen

| Bloque | Agregar |
|---|---|
| `hero.layout` | `split` (texto en un panel y foto al lado; `image_side: left|right`), `editorial` (a sangre, texto abajo a la izquierda, eyebrow + título grande + botón link), `cover_center` (a sangre, centrado; `wordmark: bool` pinta el **nombre de la sede** con `logo_font` en tamaño gigante; si no, el `title`; más `subtitle` en mayúsculas espaciadas y botón opcional). Campos `eyebrow`, `subtitle` y `wordmark` en `fields()`. `hero.height` gana `full` = equivalente a `appearance.height screen` |
| `categories.style` | `text_list` (nombres grandes separados por «/», con el número de artículos en superíndice: `StorefrontRenderer::resolve` cuenta los vendibles), `tall_cards` (carrusel de tarjetas altas con la **imagen de la categoría** (`categories.image_media_id`), título encima según `title_position: top|center|bottom` y botón opcional `button_label`; si a la categoría le falta imagen, usa `preview_media` de la plantilla y, si no hay, la portada de su artículo más vendido), `photo_circles` (círculos con foto, tipo historias) |
| `categories.preview_media` | Solo contexto plantilla: lista `{label, media_id}` para que la plantilla se vea con fotos sin nombrar categorías; en una tienda se usan las de sus categorías por orden |
| `product_grid` | `columns_desktop` 2 y 3; `title_align: left|center`; `arrows: side|top` (redondas junto al título, como Aura); `scrollbar: bool` (barra de avance bajo el carrusel) |
| `banner_grid.layout` | `1` (una franja) |
| `rich_text.style` | `plain` (hoy), `bold` (título enorme en mayúsculas, centrado, como «Estilo casual & esencial»), `serif`. Más `eyebrow` y `button_label` + `target` |
| `deal_countdown.style` | `flash` (hoy), `drop` (contador grande días/horas/minutos con `starts_at` propio y botón «Avísame» que abre WhatsApp de la sede; sin fecha futura, `isEmpty`) |
| `benefits.style` | `cards` (hoy), `inline` (una fila con líneas finas) |

### 6.2 Bloques nuevos

| Tipo | Campos | Render | Vacío cuando |
|---|---|---|---|
| `image_text` | `media_id`, `media_mobile_id`, `reverse`, `eyebrow`, `title`, `text`, `button_label`, `target`, `style` | Imagen y texto en dos columnas; en celular la imagen arriba | Sin imagen o sin título |
| `brand_logos` | `title`, `items[]` (`media_id` del logo **o** `text`, más `target` opcional) | Franja de logos en gris, en carrusel en celular | Sin elementos |
| `ticker` | `items[]` (texto, máx. 8), `tone: accent|dark|deal|soft`, `speed: slow|normal|fast`, `separator` | Cinta que corre en bucle con CSS (`@keyframes`, duplicando el contenido); pausada con «menos movimiento» y al pasar el mouse | Sin textos |
| `look` (Compra el look) | `media_id`, `title`, `text`, `button_label`, `hotspots[]` (`x`, `y` en %, `product_id`; en plantilla `source` en vez de ids: `best_sellers` o `new_arrivals` y cuántos, máx. 4) | Foto con puntos numerados y, al lado, la lista de prendas con precio vigente y talla (`VariantPicker`). El botón agrega todas al carrito con `PUT /tienda/carrito/cantidad` por cada una y muestra el total del servidor | Sin imagen o sin artículos vendibles |

### 6.3 Encabezado sobre la portada (`header.overlay`)

- En la portada, el encabezado va `absolute` sobre la primera sección, con letra blanca y un degradado suave arriba.
- Al bajar se vuelve sticky con `sticky_style`: por ejemplo `blur_dark` = fondo `rgba(8,16,29,.72)` + `backdrop-filter`.
- Se calcula en el servidor (`StorefrontRenderer`) si aplica, y viaja en la prop `storefront`.
- En la vista previa del editor funciona igual.

### 6.4 Contexto plantilla

Ninguno de estos campos guarda ids de la empresa en una plantilla:

- `look` usa `source`;
- `categories` usa `preview_media`;
- `brand_logos` solo acepta medios de plataforma o texto.

`fields('template')` lo garantiza y `violations` lo prueba.

### 6.5 Newsletter

- El pie con newsletter necesita dónde guardar los correos: tabla `newsletter_subscriptions`, con:
  - `company_id`, `store_id`, `email`, `consent_at`, `source`, `ip`;
  - único por empresa y correo.
- Ruta `POST /tienda/newsletter` con throttle y su `FormRequest`.
- Una lista en el panel, **Tienda en línea › Suscriptores**, con exportar a CSV y el permiso `storefront.newsletter.view`, más su migración de permisos como las otras `grant_*`.
- Si prefieres no hacerlo en esta entrega, **no pintes el formulario**. `footer.newsletter` queda fuera del editor hasta que exista.

---

## 7. Las 12 plantillas

### 7.1 Datos

- `context/plantillas-tienda.json` trae, por plantilla:
  - `slug`, `name`, `description`, `for`;
  - `palette_suggestion`, `theme`, `settings` (solo las claves de diseño);
  - `blocks` en orden, con `type`, `page`, `sort_order`, `payload` y `appearance`.
- Las imágenes vienen como **receta** en `media` / `media` de cada elemento: `["motivo", ...parámetros]`. Las pinta el seeder (§7.2).
- Cópialo a `database/seeders/data/storefront_templates.json` y **ajústalo a los nombres finales** que quedes usando en §4 a §6. El JSON usa los de este documento.
- Los íconos de los mensajes y beneficios del JSON (`Truck`, `Tag`, `Clock`, `Leaf`…) deben existir en `config/storefront_icons.php` y en `icons.tsx`. Si falta alguno, agrégalo en los dos.
- Si algún bloque de una plantilla no tiene equivalente porque decidiste otra cosa, dilo en el reporte; no lo quites en silencio.
- Las páginas `product` y `cart` de cada plantilla copian las de «Supermercado» (estante relacionado + beneficios / «¿Se te olvidó algo?»), adaptando el estante a la tarjeta de la plantilla.

### 7.2 Imágenes de ejemplo

- Extiende `BannerPainter` (GD, sin internet, sin fotos de nadie) con los motivos de la galería:
  - `produce`, `pantry`, `clean`;
  - `studio` (fondo de estudio con una figura con abrigo o vestido del color dado);
  - `rack` (pared, riel con prendas colgadas y una mesa con prendas dobladas);
  - `flatlay` (prendas dobladas vistas desde arriba);
  - `fold`, `workshop`, `glow` (producto de belleza iluminado sobre fondo oscuro), `beach`, `leafy`, `farm`.
- Cada motivo recibe los colores del JSON.
- Cada imagen sale en computador (1920×1080 para portadas a pantalla completa, 1600×600 para banners, 900×1200 para tarjetas altas) y en celular (900×1400) cuando la receta lo pide.
- Todas quedan como **medios de plataforma** (`company_id` null) con su descripción (`alt`).
- **`preview_media_id`** de cada plantilla: una miniatura 1200×800 que el seeder arma pegando la portada pintada con el nombre de la plantilla.

### 7.3 Seeder y migración

- **Migración:** `2026_10_15_000200_add_catalog_fields_to_storefront_templates.php`. Suma a `storefront_templates`:
  - `slug` (único);
  - `audience` (string 120, el «para quién»);
  - `palette_suggestion` (json, null);
  - `sort_order` (unsigned int).

  Rellena `slug = supermercado` en la existente.
- **Seeder:** `StorefrontTemplateCatalogSeeder`, que se llama desde `StorefrontTemplateSeeder` después de crear «Supermercado».
  - Lee el JSON.
  - Por cada plantilla: si ya existe por `slug`, no la toca (idempotente). Si no, la crea con `StorefrontTemplateService::create`, guarda el borrador normalizado con `BlockCatalogue::for($type)->normalize(..., CONTEXT_TEMPLATE)` + `SectionAppearance::normalize`, la publica (`publish`, etiqueta «Plantilla inicial») y la deja activa.
  - Nunca cambia la predeterminada.
- **Validación:** `StorefrontTemplateService::validate()` debe pasar sin advertencias para las 12. Si una advierte, arregla el JSON, no la validación.

### 7.4 Plataforma › Plantillas de tienda

- **El listado (`Platform/StoreTemplates/Index.tsx`)** pasa a ser una galería de tarjetas:
  - miniatura (`preview_media`), nombre, `audience`;
  - insignias «Por defecto» / «Inactiva»;
  - cuántas tiendas la siguen (`followers`, ya existe).
- Orden por `sort_order` y filtro por familia de comercio («Mercado», «Moda», «Belleza y hogar», «Ferretería»), guardado como `audience_group` en la misma migración.
- **Acciones:** las de hoy (editar, publicar, marcar por defecto, activar/desactivar) más **Duplicar**, que crea una copia en borrador con «(copia)».

### 7.5 Elegir plantilla desde el Editor de la tienda

- El selector de plantilla del editor (hoy `TemplateInfo.available`) se vuelve un diálogo con la misma galería (miniatura, nombre, para quién).
- **«Usar esta plantilla»** llama al `apply` de hoy y advierte que reemplaza el diseño, nunca la marca ni la operación.
- Si la plantilla trae `palette_suggestion`, al final ofrece «Usar también sus colores sugeridos» (§3.4).

---

## 8. Fases

| Fase | Contenido | Aceptación |
|---|---|---|
| **1. Apariencia de sección** | §4.1–4.4 y §4.6 en tienda **y** landing; retiro de los fondos viejos con su migración | Sin tocar Diseño, la tienda de «La Canasta», la de la empresa de ropa y la landing se ven iguales que antes (capturas antes/después en celular y computador). Con Diseño: una sección a 100svh centrada, a sangre, con foto, velo 35 %, paralaje y animación «Subir», en las dos superficies y en la vista previa |
| **2. Diseño de banners** | §4.5 | Cambiar la proporción, la posición del texto, el tamaño del título y el botón de una lámina y de un mosaico desde el editor, y verlo en vivo |
| **3. Tema, estructura y bloques** | §5 y §6 | Cada valor nuevo se elige en el editor, se publica, se ve en la tienda y vuelve atrás con el historial |
| **4. Plantillas** | §7 | `migrate:fresh --seed` deja 13 plantillas publicadas (Supermercado por defecto). Una sede nueva adopta «Vitrina», se ve como en la galería con sus propios productos, se independiza (los medios se copian) y vuelve |

---

## 9. Pruebas

| Prueba | Mide |
|---|---|
| `SectionAppearanceTest` | Normaliza opciones válidas, descarta las inválidas, aplica defectos por tipo y valida hex y medios ajenos; da el mismo catálogo para `store` y `landing` salvo los campos de imagen y video |
| `SectionAppearanceRenderTest` | Un bloque sin `appearance` produce exactamente las mismas variables/clases que antes (no regresión), tienda y landing |
| `MoveSectionBackgroundsMigrationTest` | La migración de §4.6 sobre borradores, publicados, versiones y plantillas, y su `upgrade()` al restaurar una versión vieja |
| `StorefrontAppearanceMediaTest` | Publicar, copiar a otra sede e independizarse llevan `bg_media_id`/`bg_media_mobile_id`/`bg_video_media_id`; una imagen de otra empresa se rechaza |
| `LandingAppearanceMediaTest` | `withMediaUrls` también dentro de `appearance`; subir y quitar la imagen de fondo |
| `StorefrontNewBlocksTest` | `image_text`, `brand_logos`, `ticker`, `look`: normalizan, se resumen, quedan vacíos cuando deben y en contexto plantilla no aceptan ids |
| `LookBlockCartTest` | «Agregar el look» suma las prendas vendibles con su variante y el total sale del servidor |
| `StorefrontThemeNewValuesTest` | Los valores nuevos de tema y estructura se guardan, se publican y viajan en `DESIGN_KEYS`; `accent` no viaja |
| `HeaderOverlayTest` | Solo se activa en portada con un `hero` a sangre de primero; en las demás páginas, encabezado sólido |
| `StorefrontTemplateCatalogSeederTest` | Siembra las 12 una sola vez, todas publicadas, activas y validadas sin advertencias, sin ids de empresa, con medios de plataforma; Supermercado sigue por defecto |
| `NewsletterSubscriptionTest` | Si se implementa §6.5: suscribe, no duplica, throttle, permiso del listado |
| `RouteSmokeTest` | Las rutas nuevas |

---

## 10. Reglas nuevas para `CLAUDE.md`

Agrégalas en «Trampas conocidas» al terminar, ajustadas a lo que quedó:

```markdown
- **Diseño de sección (tienda y landing)**: un solo catalogo, `App\Support\SectionAppearance` (`groups($surface)`), guardado
  en `payload.appearance` (tienda) o `data.appearance` (landing). Clave vacia = defecto del tipo (`appearanceDefaults()`);
  sin tocar Diseño un bloque se ve como antes. Lo pinta `SectionShell.tsx` y lo traduce `components/sections/appearance.ts`.
  Agregar un ajuste = entrada en el catalogo + su traduccion en `appearance.ts`; el editor lo pinta solo.
- **Diseño de banner**: `item.style` en los repetidores con imagen (`group: 'design'` en el `item`).
- **`BlockBackground` y `LandingBackground` ya no existen**: su valor se migro a `appearance`; `upgrade()` convierte
  instantaneas viejas.
- **Colores y plantilla**: una plantilla nunca cambia el acento ni la oferta de la sede; trae `palette_suggestion` y la
  sede decide aplicarla desde Aspecto.
- **Catalogo de plantillas**: `database/seeders/data/storefront_templates.json` + `StorefrontTemplateCatalogSeeder`
  (idempotente por `slug`). Las imagenes las pinta `BannerPainter` como medios de plataforma.
```

---

## 11. Entrega

Al cerrar cada fase, reporta:

- Archivos creados y modificados.
- Migraciones.
- Resultado de `npx tsc --noEmit`, `npm run build`, `php artisan test` y `php artisan migrate:fresh --seed`.
- **Capturas en celular y computador.**
  - Fase 1: antes/después sin Diseño, más una sección con todo el Diseño activado.
  - Fase 4: la portada de cada una de las 12 plantillas con los productos de demostración. Moda con la empresa de ropa, mercado con «La Canasta».
- Lo que quedó pendiente, dicho sin rodeos.
