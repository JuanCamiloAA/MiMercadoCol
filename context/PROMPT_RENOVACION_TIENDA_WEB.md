# PROMPT — Renovación de la tienda web (estilo supermercado) 100 % parametrizable desde el Editor de la tienda, con plantilla base del super usuario

> **Para:** Claude Opus, trabajando en `C:\xampp\htdocs\MiMercadoCol` (La Canasta — Laravel 12 + Inertia 2 + React 19 + TS estricto).
> **Fecha:** 2026-09-28.
> **Referencia visual:** la maqueta que el dueño aprobó en la conversación (sección 3 resume lo que muestra). Inspiración: tiendas en línea de supermercados de descuento colombianos (tarjetas limpias, precio protagonista, ofertas en rojo). **Se toma el estilo, no la marca de nadie:** cada tienda usa su propio nombre, logo y color.

---

## 0. Cómo trabajar

1. **Lee primero `CLAUDE.md` y `README.md`.** Mandan. Las reglas que más pesan aquí:
   - Bloque nuevo = clase en `app/Storefront/Blocks/` + `BlockCatalogue::TYPES` + renderizador en `StoreBlocks.tsx`.
   - El editor arma el formulario **solo** con `fields()`.
   - Los datos del servidor se resuelven en `StorefrontRenderer::resolve`, que alimenta tienda y vista previa.
   - La vista previa va en `iframe`.
   - La tienda es responsiva sin columna de celular.
   - Íconos de la tienda importados **uno por uno** en `StoreBlocks.tsx`, y cada nombre de `config/storefront_icons.php` también en el mapa `ICONS`.
   - El checkout lee siempre la **versión publicada** (`StorefrontConfig`).
   - `--accent-ink` se calcula en el servidor.
   - Precio solo por `PromotionService` / `ServicePricing`.
   - «Sede vendible» = `StorefrontContext::sellable()`.
   - `CartService` nunca mueve carritos entre empresas.
   - La tienda queda **fuera** de `codemod-units`: tiene su propio editor de aspecto.
   - Nada de arrays literales en `.tsx`, nada de botones sin acción.
2. **Verifica antes de escribir.** Las referencias salen del código del 2026-09-28. Si algo cambió, adapta y dilo.
3. **Por fases (§6 a §10).** Cada fase cierra con:

   ```bash
   npx tsc --noEmit
   npm run build
   php artisan test
   php artisan migrate:fresh --seed
   ```

   Más el recorrido manual en **celular y computador**. Reporta al cerrar cada una.
4. **No rompas lo que funciona:**
   - Checkout, cupones, zonas y franjas, pagos, pedidos, servicios, devoluciones en línea y registro de clientes.
   - Tienda multiempresa y landing de plataforma.
   - Versiones, publicación, historial y «copiar a otras sedes» del editor.
   - Una tienda ya personalizada debe seguir viéndose con **su** contenido; solo gana el diseño nuevo.
5. **Si algo no está decidido aquí, pregunta.**

---

## 1. Qué pidió el dueño

- **Renovar la tienda web** con un diseño de ecommerce de supermercado: encabezado de compra, categorías con ícono, banners, estantes de ofertas, tarjeta con precio grande, precio por unidad y botón que se vuelve contador, barra de carrito con avance al domicilio gratis.
- **Que todo sea parametrizable, integral y organizado desde el editor:** imágenes, carruseles, textos, colores, orden, qué se muestra y qué no, en todas las páginas de la tienda.
- **Decisión del dueño (cerrada):** la tienda se parametriza en el **Editor de la tienda** de cada empresa (por sede, como hoy). El **super usuario** define una **plantilla base**:
  - las tiendas nuevas nacen con ella;
  - las que no la han personalizado **la siguen** y reciben sus cambios;
  - al personalizarla, la tienda se **independiza**, y puede volver a la plantilla cuando quiera.

---

## 2. Lo que hay hoy (y se aprovecha)

| Pieza | Dónde | Estado |
|---|---|---|
| Sitio por sede | `storefronts` (uno por sede), `storefront_themes`, `storefront_settings` (claves `delivery`, `coverage`, `slots`, `payments`, `catalog`, `texts`, `seo`), `storefront_blocks` (`uuid`, `type`, `sort_order`, `is_visible`, `visible_from`/`visible_to`, `devices` all/mobile/desktop, `payload`), `storefront_versions` | **Sin concepto de página**: todos los bloques son de la portada |
| Editor | `pages/Storefront/Editor/{Editor,ContentTab,AppearanceTab,FunctionsTab,BlockFields,PreviewFrame}.tsx` | Pestañas Aspecto / Contenido / Funciones; vista previa en `iframe` con celular/computador; selección de bloque desde la vista previa (`activeUuid`, `onSelect`) |
| Bloques | `app/Storefront/Blocks/` | `announcement`, `hero`, `categories`, `product_grid`, `promo_carousel`, `benefits`, `rich_text`, `faq`, `store_info`. Campos que entiende el editor: `text`, `textarea`, `markdown`, `number`, `toggle`, `select`, `media`, `icon`, `target` (url/categoría/página), `repeater`, `categories`, `products`, con `show_if` y `options_from` |
| Render | `StorefrontRenderer` (`published`, `draft`, `render`, `resolve`, `media`, `categories`, `products`, `pricePerUnit`, `bestSellers`) y `StoreBlocks.tsx` | Mismo componente en tienda y vista previa |
| Medios | `media_assets` (`company_id` **NOT NULL**, `alt`, `focal_x`/`focal_y`, `variants`) y `MediaController` | Biblioteca por empresa |
| Tema | `storefront_themes`: `logo_media_id`, `favicon_media_id`, `display_name`, `tagline`, `accent`, `scheme` (light/dark/auto), `font_pair`, `radius`, `density`, `button_style` | Lo aplica `StoreTheme.tsx` |
| Cascarón | `layouts/StoreLayout.tsx` | Logo en SVG fijo, `ThemeToggle` en el encabezado, «Cambiar de tienda», pestañas Tienda/Carrito/Pedidos arriba (md+) y abajo (celular) |
| Portada | `pages/Storefront/Home.tsx` | Chips de categoría **y** bloque de categorías (duplicado); `ProductCard` local |
| Otras páginas | `Product.tsx`, `Service.tsx`, `Cart.tsx`, `Checkout.tsx`, `Orders.tsx`, `OrderTracking.tsx`, `ReturnRequest.tsx`, `Login.tsx`, `Register.tsx`, `Activate.tsx`, `Unavailable.tsx` | Cada una con su propio marcado |
| Carrito | `CartController@store/update/destroy` → `redirect()->back()` | Cada «Agregar» es una visita completa de Inertia |
| Categorías | `categories.icon` (string) y `categories.parent_id` | La tienda **no** usa el ícono (todas salen con la canasta) ni las subcategorías |
| Plantilla de plataforma | No existe. La landing de plataforma (`app/Landing/Blocks`, `landing_blocks`) es otra cosa y **no** se toca aquí | — |
| Permisos del editor | `storefront.editor.{view,content,appearance,settings,publish,history,copy_store}` | Se reutilizan; no hacen falta permisos nuevos de empresa |

**Defectos visibles que se corrigen en la Fase 1:**

- **«gratis desde $ 0».** `Home.tsx:85` pinta el umbral aunque sea 0; `delivery.free_from = 0` significa **sin domicilio gratis**.
- **`products.image_url` nunca se usa en la tienda.** La importación del catálogo externo la llena, pero `CatalogController` y `Home.tsx` solo leen `image_path` / `thumb_path`.
- **Precio en monoespaciada y color de acento:** parece código.
- **«35 und disponibles».** Existencia exacta a la vista del cliente.
- **`ThemeToggle` en el encabezado de la tienda.** El esquema lo decide el editor (`theme.scheme`).
- **Chips de categoría duplicados** con el bloque de categorías.

---

## 3. El diseño objetivo

### 3.1 Estructura global (igual en todas las páginas)

1. **Barra superior** delgada, color de marca oscuro. Uno o varios mensajes rotativos con ícono y enlace, por ejemplo: «Domicilio $ 5.000 · gratis desde $ 80.000», «Recíbelo hoy entre 4:00 y 6:00 p. m.».
   - Los mensajes pueden usar **variables** que se resuelven con la configuración publicada de la sede: `{domicilio}`, `{gratis_desde}`, `{proxima_franja}`, `{sede}`, `{horario}`.
   - Una variable sin valor oculta el mensaje (nunca «gratis desde $ 0»).
2. **Encabezado de compra**, fijo al desplazar si así se configura:
   - Logo o isotipo + nombre + lema.
   - **«Recibir en»:** sede y modo (domicilio / recoger si `delivery.pickup`). Abre una hoja para cambiar de sede (de la misma empresa) o ir al buscador de la landing.
   - **Buscador grande**, con sugerencias mientras se escribe.
   - «Entrar» o «Mi cuenta».
   - **Carrito** con cantidad y total.
3. **Menú de categorías**, horizontal:
   - Chip de **ofertas** en rojo.
   - Categorías con su ícono o imagen.
   - «Todas las categorías», que abre un menú grande con subcategorías (`parent_id`).
   - En celular es una fila deslizable.
4. **Barra de carrito flotante** (aparece con al menos un artículo): cantidad, total, avance y texto hacia el domicilio gratis («Te faltan $ 52.700 para domicilio gratis» / «Tu domicilio es gratis»), botón «Ver carrito».
   - En computador puede abrir un **cajón lateral** en vez de ir a la página del carrito.
5. **Navegación inferior en celular:** Inicio, Categorías, Buscar, Pedidos, Cuenta, configurables.
6. **Pie de página:**
   - Columnas de enlaces, contacto, redes, medios de pago aceptados, textos legales.
   - **Franja legal obligatoria**, siempre visible y no editable en su contenido: datos del vendedor desde la empresa (razón social, NIT, dirección, teléfono y correo) y enlaces a términos, tratamiento de datos y PQRS. En comercio electrónico en Colombia el vendedor debe identificarse.

### 3.2 Tarjeta de producto (un solo componente en toda la tienda)

- **Foto cuadrada** sobre fondo claro (`object-contain`). Respaldo: `image_path` → `thumb_path` → `image_url` → ícono de la categoría.
- **Etiqueta de oferta:** «-12%» o «Ahorra $ 1.500», configurable. Etiqueta «Servicio» para servicios.
- Nombre en 2 líneas, marca (opcional) y contenido («900 ml»).
- **Precio grande**, en letra normal y negrita. En rojo de oferta con el anterior tachado cuando hay promoción.
- **Precio por unidad de medida** («$ 12.778 x L»), calculado **en el servidor** (`StorefrontRenderer::pricePerUnit`, único cálculo).
- Aviso de existencias según la regla (§4.3): oculto, «Últimas N unidades» bajo un umbral, o «Agotado».
- **«Agregar» → contador (− n +) en la misma tarjeta**, sin recargar (§6.2).

### 3.3 Páginas

| Página | Contenido |
|---|---|
| **Portada** | Bloques del editor. Por defecto: banner principal + banner lateral, categorías con ícono, «Ofertas de la semana» (estante deslizable), «Lo más comprado aquí», un estante por categoría con «Ver todo», beneficios |
| **Categoría / Ofertas / Búsqueda** | Banner de la categoría (opcional). Subcategorías en chips. En computador, **filtros** a la izquierda (subcategoría, marca, rango de precio, solo ofertas, solo disponibles); en celular, en una hoja. Orden (relevancia, menor precio, mayor precio, más vendidos, nombre). Contador de resultados. Rejilla. Paginación o «Cargar más» |
| **Producto** | Galería, precio, precio por unidad, contador, descripción, «Incluye» (servicios), «También te puede interesar» y bloques del editor debajo |
| **Carrito** | Líneas con contador, avance a domicilio gratis, mínimo de pedido, cupón, resumen, bloque de venta cruzada del editor |
| **Checkout, pedidos, seguimiento, devolución, ingreso, registro, activación, no disponible** | Mismo cascarón y componentes (tipografía, botones, tarjetas); sin cambio de lógica |

---

## 4. Modelo de parametrización (qué se guarda y dónde)

Todo lo nuevo vive en las tablas que **ya** se versionan y publican. Así el borrador, la publicación, el historial, «copiar a otras sedes» y la vista previa lo cubren sin código aparte.

### 4.1 Páginas para los bloques

- **Migración:** `storefront_blocks.page` `string(16)` default `'home'`, e índice `(storefront_id, page, sort_order)`.
- **Constantes** `StorefrontBlock::PAGE_HOME`, `PAGE_CATEGORY`, `PAGE_PRODUCT`, `PAGE_CART`, `PAGE_SEARCH`:
  - `category` y `search` se pintan **arriba** de los resultados.
  - `product`, **debajo** de la ficha.
  - `cart`, **debajo** del resumen.
- **Cada tipo de bloque declara en qué páginas se puede usar:** `BlockData::pages(): array`, por defecto `['home']`.
  - `announcement`: todas.
  - `hero` / `banner_grid`: home, category, search.
  - `product_grid`: todas.
  - `faq`, `rich_text`, `benefits`: home, product.
  - `store_info`: home.
  - El editor solo ofrece en cada página los tipos permitidos. `BlockController@store` lo valida.
- **Bloques de categoría con condición:** un bloque de la página `category` puede limitarse a unas categorías (campo común `only_categories`, tipo `categories`). Así «banner de Aseo» solo sale en Aseo.
- `StorefrontManager::snapshot()` / `restore()` y `StorefrontRenderer::render()` incluyen `page`. `render()` recibe `?string $page` y filtra.
- **Instantáneas viejas sin `page`:** todo es `home`.

### 4.2 Estructura global en `storefront_settings`

Claves nuevas en `StorefrontSetting::KEYS`, con su defecto en `StorefrontManager::defaultSettings()`. Cada una con **FormRequest** propio y validación estricta (listas cerradas, longitudes, `media_id` de la empresa, colores hex).

| Clave | Contenido |
|---|---|
| `topbar` | `enabled`, `messages[]` (`icon`, `text` con variables, `target`), `rotate_seconds` (0 = fijo), `tone` (`brand_dark` \| `accent` \| `neutral` \| `deal`) |
| `header` | `style` (`light` \| `brand` \| `dark`), `sticky`, `show_tagline`, `show_location`, `show_account`, `cart_shows_total`, `search_placeholder`, `logo_mode` (`logo` \| `mark_name` \| `name`) |
| `nav` | `enabled`, `show_deals_chip` + `deals_label`, `items[]` (`kind`: `category` \| `page` \| `url`, `value`, `label`, `icon`, `highlight`), `show_all_categories`, `mega_menu` (bool, usa subcategorías) |
| `mobile_nav` | `items[]` elegidos y ordenados de una lista cerrada: `home`, `categories`, `search`, `orders`, `account`, `cart` (3 a 5) |
| `card` | `image_fit` (`contain` \| `cover`), `image_bg` (`light` \| `white`), `show_brand`, `show_content`, `show_unit_price`, `deal_badge` (`percent` \| `savings` \| `none`), `add_button` (`outline` \| `solid`), `stock_display` (`hidden` \| `low_only` \| `exact`), `low_stock_threshold` (1–20), `price_size` (`md` \| `lg`) |
| `cart_bar` | `enabled`, `show_free_delivery_progress`, `texts.missing` (con `{faltan}`), `texts.reached`, `desktop_behavior` (`drawer` \| `page`) |
| `category_page` | `layout` (`sidebar` \| `top`), `filters` (subconjunto de `subcategories`, `brand`, `price`, `deals`, `in_stock`), `sorts` + `default_sort`, `page_size` (12–60), `pagination` (`pages` \| `load_more`), `show_subcategory_chips` |
| `product_page` | `show_unit_price`, `show_description`, `related` (`same_category` \| `best_sellers` \| `none`) + `related_limit` |
| `search` | `suggestions` (bool), `popular_terms[]` (máx. 8), `empty_title`, `empty_text` |
| `footer` | `columns[]` (`title`, `links[]` con `label` + `target`), `contact` (teléfono, WhatsApp, correo, dirección; por defecto de la sede), `social[]` (`network` de lista cerrada + `url`), `payment_logos[]` (lista cerrada), `legal_text`, `terms_target`, `privacy_target`, `pqrs_target`. La franja legal de §3.1 no se configura: sale de la empresa |
| `catalog` (existente, se amplía) | `show_out_of_stock`, `price_per_unit` y además `hide_zero_price` (siempre verdadero; no se pinta) |

`StorefrontConfig` expone lectores tipados de estas claves **desde la versión publicada**, como ya hace con `delivery`.

### 4.3 Tema (`storefront_themes`)

- **Columnas nuevas:**

  | Columna | Tipo | Nota |
  |---|---|---|
  | `deal_color` | `string(9)` default `#D92D20` | Con verificación de contraste como el acento (4,5:1 sobre blanco), por la misma regla de `CLAUDE.md` |
  | `mark_media_id` | FK nullable | Isotipo cuadrado |
  | `allow_scheme_toggle` | bool default false | Si el cliente puede cambiar claro/oscuro; el interruptor pasa al pie o al menú de cuenta, nunca al encabezado |
  | `font_pair` | se amplía | Parejas nuevas de lista cerrada (p. ej. `mercado`: Nunito Sans + Nunito Sans 800) |

- **`display_name`, `tagline`, logos y `accent` son de la sede** (identidad). Con plantilla (§5) **no** los pone la plantilla.
- `--deal` y `--deal-ink` los calcula el servidor, igual que `--accent-ink`. `StoreTheme.tsx` los aplica.

### 4.4 Medios

- **Banners con imagen distinta para celular y computador.** Los campos de imagen de `hero`, `promo_carousel` y `banner_grid` pasan a ser `media` + `media_mobile` (opcional; si falta, la de computador con su punto focal).
- **`StorefrontRenderer::media()`** devuelve `srcset` desde `media_assets.variants` y el punto focal. El componente `<BannerImage>` pinta un `<picture>`:
  - `loading="eager"` + `fetchpriority="high"` solo en el primer banner visible;
  - `lazy` en el resto.
- **Texto alternativo:** `issues()` de cada bloque **bloquea publicar** un banner sin `alt` y avisa por imágenes de más de 1 MB.

### 4.5 Categorías

- **Migración:** `categories.image_media_id` (FK nullable a `media_assets`).
- En **Categorías** (panel) se editan ícono (selector con los nombres de `config/storefront_icons.php`) e imagen. **Es la fuente por defecto** del menú y del bloque de categorías; el bloque puede sobreescribir por ítem, como hoy.
- **Íconos nuevos:** agrégalos a `config/storefront_icons.php` **y** al mapa `ICONS` (regla de `CLAUDE.md`).

---

## 5. Plantilla base del super usuario

### 5.1 Datos

**`storefront_templates`**

| Columna | Tipo | Nota |
|---|---|---|
| `name`, `description` | string/text | — |
| `preview_media_id` | FK nullable | — |
| `draft` | json | Mismo formato que la instantánea de una tienda, **solo diseño** (ver abajo) |
| `published` | json nullable | — |
| `published_at` | `dateTime` nullable | — |
| `published_by_user_id` | FK nullable | — |
| `is_default` | bool | Una sola a la vez; lo hace cumplir el servicio en transacción |
| `is_active` | bool | — |
| timestamps | — | — |

**`storefront_template_versions`:** `template_id`, `number`, `snapshot`, `label`, `created_by_user_id`, `published_at`. Historial y restaurar, como las tiendas.

**`storefronts`:** agrega `template_id` (FK nullable) y `follows_template` (bool).

- Las **existentes** quedan con `follows_template = false`: ya tienen su contenido.
- Las **nuevas** (`StorefrontManager::for` / `CompanyProvisioner`) nacen con la plantilla por defecto y `follows_template = true`.

**`media_assets.company_id` pasa a nullable.** `null` = medio de plataforma. `BelongsToCompany` / `CompanyScope` debe seguir escondiéndolos a las empresas. Verifica que el scope no los muestre cuando la empresa es `null` en consolidado, y **pruébalo**.

### 5.2 Qué es diseño y qué no

| Lo pone la plantilla (diseño) | Es siempre de la sede |
|---|---|
| `blocks` (todas las páginas) | `theme.display_name`, `tagline`, logos, isotipo, favicon, `accent`, `deal_color` |
| `theme.scheme`, `font_pair`, `radius`, `density`, `button_style`, `allow_scheme_toggle` | `delivery`, `coverage`, `slots`, `payments`, `texts`, `seo` |
| `topbar`, `header`, `nav` (salvo ítems por id), `mobile_nav`, `card`, `cart_bar`, `category_page`, `product_page`, `search`, `footer` (salvo contacto y legal), `catalog` | Contacto, franja legal (de la empresa) |

Constante `StorefrontTemplate::DESIGN_KEYS` como **única fuente** de este reparto.

**La plantilla no puede referirse a nada de una empresa:**

- **Sin ids** de artículos, categorías o promociones. En modo plantilla, `product_grid` solo ofrece `featured`, `best_sellers`, `on_sale`, `new_arrivals` y `buy_again`. `categories` solo ofrece `all` o `top` (las N más vendidas). Los `target` solo ofrecen `page`, `search` y `url`.
- `BlockData::fields(string $context = 'store')` recibe el contexto y quita opciones. `normalize()` / `issues()` **rechazan** ids en contexto `template`.
- Los ítems de `nav` por categoría no aplican a la plantilla: se usa «categorías principales automáticas».

### 5.3 Render y ciclo de vida

- **`StorefrontRenderer::published(Store)`:** si `follows_template` y la plantilla tiene `published`, compone la instantánea con el **diseño de la plantilla** + lo que es de la sede (§5.2). Si no, la de la sede, como hoy. Lo mismo `draft()` para la vista previa. El checkout sigue leyendo `delivery` / `payments` de la sede.
- **Independizarse:** la primera edición de diseño en el Editor de la tienda de una sede que sigue la plantilla la independiza.
  - Aviso previo en el editor: «Esta tienda usa la plantilla «Supermercado». Si la personalizas, dejará de recibir sus cambios.» con [Personalizar] [Cancelar].
  - `StorefrontTemplateService::detach(Storefront)`, en una transacción: copia el diseño publicado de la plantilla al borrador de la sede, **copia los medios de plataforma a la biblioteca de la empresa** (archivos y filas nuevas, reescribiendo los `media_id` del contenido) y pone `follows_template = false`.
  - Copiar los medios evita que borrar o cambiar la plantilla rompa tiendas independizadas.
- **Volver a la plantilla:** `reattach(Storefront, ?Template)`, con confirmación: «Perderás tu diseño propio; tus datos de domicilio, pagos y marca se conservan.» Guarda antes una versión del borrador actual, para poder deshacer desde el historial.
- **Aplicar otra plantilla** (si hay varias activas): igual que volver, eligiendo cuál.
- **Publicar la plantilla** actualiza a todas las sedes que la siguen, sin tocar sus versiones.
- **«Copiar a otras sedes»** (existente) copia también `follows_template` y `template_id`.

### 5.4 Editor de plantillas (super usuario)

- **Menú:** sección Plataforma → **«Plantillas de tienda»**, `superAdminOnly`.
- **Permisos** del módulo `platform` (`super_admin_only`): `platform.store_templates.view`, `edit`, `publish`.
- **Rutas** bajo `/plataforma/plantillas-tienda`: listado, crear, editar (el mismo editor), publicar, historial, restaurar, marcar por defecto, activar/desactivar y vista previa.
- **Reutiliza el mismo editor** (`Editor.tsx`, `ContentTab`, `AppearanceTab`, `BlockFields`, `PreviewFrame`) con una prop `context: 'store' | 'template'`:
  - Sin la pestaña **Funciones** operativa.
  - Sin identidad de marca.
  - Con campos filtrados por contexto.
  - **No dupliques el editor.**
- **Vista previa:** con los artículos de una sede elegida por el super usuario («Ver con: Mercado El Vecino — Bello Niquía»), porque la plantilla no tiene catálogo propio.
- **Listado:** cuántas sedes la siguen.

---

## 6. Fase 1 — Componentes nuevos, carrito sin recargar y correcciones

### 6.1 Componentes (`resources/js/components/storefront/`)

Un componente por pieza, **usados por la tienda y por la vista previa del editor**:

| Componente | Qué hace |
|---|---|
| `TopBar` | Mensajes rotativos; se detiene si el usuario prefiere menos movimiento (`prefers-reduced-motion`) |
| `StoreHeader` | Con `LocationPill`, `SearchBox` y `CartButton` |
| `CategoryNav` | Con el menú grande de subcategorías |
| `ProductCard` | **Único**: se borra el de `Home.tsx` y se usa en portada, estantes, categoría, búsqueda, relacionados, venta cruzada y vista previa |
| `AddToCartControl` | Botón «Agregar» ↔ contador |
| `CartBar` | Barra de carrito flotante |
| `CartDrawer` | Cajón lateral del carrito en computador |
| `MobileNav` | Navegación inferior en celular |
| `StoreFooter` | Con la franja legal |
| `Carousel` | Accesible: flechas, puntos, deslizar, pausa al enfocar o pasar el mouse, `aria-roledescription="carrusel"`, sin autoplay con menos movimiento |
| `BannerImage` | `<picture>` con fuentes de celular y computador |
| `Price` | Precio, tachado, precio por unidad, rojo de oferta |
| `StockNote` | Aviso de existencias según §4.2 |

- `StoreLayout.tsx` se reescribe con estos componentes y lee la estructura de `page.settings` (publicado o borrador en la vista previa).
- **Se quita `ThemeToggle` del encabezado.**
- **Tokens:** la tienda sigue con su `StoreTheme` (`--accent`, `--accent-ink`, `--deal`, `--deal-ink`, `--store-font`, `--store-radius`, `--store-gap`). Nada de colores fijos en los componentes salvo el blanco de la foto.
- **Precio:** fuera la clase `font-mono`; cifras con `font-variant-numeric: tabular-nums` en la fuente de la tienda.

### 6.2 Carrito sin recargar la página

- **Prop compartida `cart`** en `HandleInertiaRequests`, solo en rutas `storefront.*` (lazy), leída de `CartService::current` sin crear carrito si no existe:
  - `count`, `total`;
  - `lines`: `{ key: 'p:12' | 's:3', quantity }`;
  - `free_delivery`: `{ from, missing, reached }` desde la configuración **publicada**;
  - `min_order_total`.
- **Endpoint nuevo:** `PUT /tienda/carrito/cantidad` (`storefront.cart.quantity`).
  - Recibe `{ product_id | service_id, quantity }`: **cantidad absoluta**; 0 quita. Absoluta e idempotente: dos toques rápidos no suman de más.
  - Responde JSON con el resumen `cart` y, si no alcanza, `{ error, available }`.
  - Valida existencia disponible en la bodega de la tienda (`onlineSourceFor`), empresa de la sede (`StorefrontContext`) y artículos vendibles. `throttle:60,1`.
- **`AddToCartControl`:**
  - Actualiza al instante (optimista).
  - Agrupa toques con 350 ms de espera.
  - Llama por `lib/http.ts` (CSRF).
  - Si el servidor corrige (sin existencias), revierte y muestra un aviso con el disponible.
  - Anuncia el cambio a lectores de pantalla (`aria-live`).
- **La página del carrito y el cajón** usan el mismo endpoint. `CartController@store/update/destroy` se conservan para no romper formularios existentes, pero la tienda deja de usarlos para agregar.
- **Precio:** el total del resumen sale del servidor (`PromotionService` / `ServicePricing`). El navegador no calcula precios.

### 6.3 Correcciones

- **Domicilio gratis:** con `free_from = 0` no hay texto ni barra de avance. Aplica en la barra superior, el carrito, `CartBar` y el checkout.
- **Imagen de respaldo:** `presentProduct()` (`CatalogController:200`) y `StorefrontRenderer::products()` devuelven `image` ya resuelta (`image_path` → `thumb_path` → `image_url` → `null`) y `category_icon`. Las fotos del catálogo externo mantienen su atribución (ver `config/catalog.php`, «Datos de catálogo por Open Food Facts»): una línea en el pie cuando alguna se muestra.
- **Existencias:** según `card.stock_display` (por defecto `low_only`, umbral 5). Nunca la cifra exacta por defecto.
- **Portada:** fuera los chips duplicados de `Home.tsx:108-121`. El menú de categorías va en el encabezado.

### 6.4 Aceptación de la Fase 1

- [ ] En celular y computador, la tienda de El Vecino se ve con el encabezado, el menú, las tarjetas, la barra de carrito y el pie nuevos, con los valores por defecto.
- [ ] Agrego tres artículos tocando «Agregar» y «+» sin que la página se recargue. El total y el avance a domicilio gratis coinciden al peso con el carrito y el checkout.
- [ ] Con `free_from = 0` no aparece «gratis desde».
- [ ] Un artículo importado del catálogo externo muestra su foto.
- [ ] No aparece la existencia exacta.
- [ ] La tienda de La Canasta, personalizada antes, conserva sus bloques y textos.

---

## 7. Fase 2 — Editor organizado y bloques nuevos

### 7.1 Organización del editor (`Editor.tsx`)

Tres pestañas, con estas secciones:

| Pestaña | Secciones |
|---|---|
| **Aspecto** | Marca (nombre, lema, logo, isotipo, favicon), Colores (acento y oferta, con contraste), Tipografía, Formas y densidad, Esquema (claro/oscuro/automático, permitir que el cliente cambie), **Tarjeta de producto** (`card`) |
| **Contenido** | Selector de **página** (Portada · Categoría · Producto · Carrito · Búsqueda) con su lista de bloques. Más la **estructura global**: Barra superior, Encabezado, Menú de categorías, Barra del carrito, Navegación del celular, Pie de página |
| **Funciones** | Lo de hoy (domicilio, cobertura, franjas, pagos, textos, SEO) + **Catálogo** (`catalog`), **Página de categoría**, **Página de producto** y **Búsqueda** |

- **Vista previa:**
  - Sigue en `iframe`, con celular/computador.
  - Gana un selector de **qué página ver**; para Categoría y Producto, cuál categoría y cuál artículo.
  - Tocar la barra superior, el encabezado, el menú o el pie **selecciona su sección** en el panel, como hoy los bloques (`activeUuid` / `onSelect` ampliado a zonas).
- **Guardado:** cada sección global guarda con su FormRequest, en borrador. «Publicar» publica todo junto, como hoy.
- **Lista de verificación** (`Checklist`) ampliada:
  - banners sin texto alternativo;
  - estantes vacíos;
  - categorías del menú desactivadas;
  - mensajes de la barra con variables sin valor;
  - pie sin enlace de tratamiento de datos → **bloquea publicar**.

### 7.2 Bloques: mejoras y nuevos

Todos con `fields()`, `defaults()`, `normalize()`, `summary()`, `issues()`, `pages()` y, si aplica, `fields('template')`.

| Tipo | Cambio |
|---|---|
| `hero` → **«Banner principal»** | Una o varias láminas (repeater) con `media` + `media_mobile`, título, texto, botón y destino, color del texto, oscurecido (0–60 %) y **vigencia por lámina** (`visible_from/to`). Diseño `full` o `main_side` (principal + 1–2 laterales, cada uno imagen + destino o bloque de color con texto). Autoplay (off/3–10 s), alto (bajo/medio/alto) |
| `promo_carousel` | Láminas con `media` + `media_mobile`, destino y vigencia por lámina; tamaño; cuántas se ven a la vez en computador (1–3) |
| **`banner_grid`** (nuevo) | 2 a 4 mosaicos (imagen o color + texto + destino), diseños `2`, `3`, `1+2`, `4` |
| `categories` | Estilo: `icon_circles` \| `image_tiles` \| `chips`. Fuente: todas, las que elijo, o las N más vendidas. Usa ícono e imagen de la categoría |
| `product_grid` → **«Estante de productos»** | `layout`: `grid` \| `carousel` (deslizable con flechas). Fuentes actuales + `new_arrivals` (últimos activados) + `buy_again` (lo que el cliente con sesión ya compró en esa empresa; si no hay sesión o no hay compras, el bloque no se pinta). Columnas en computador (4–6) y celular (2 o fila). Enlace «Ver todo» (destino). Fondo (ninguno, suave, color de marca) |
| **`deal_countdown`** (nuevo) | «Oferta relámpago»: una promoción, su cuenta regresiva hasta `ends_at` y sus artículos en estante. Desaparece sola al vencer |
| **`cta_band`** (nuevo) | Franja con texto, ícono y botón (p. ej. «Pide por WhatsApp»); destino `url` / `page` / `whatsapp` (con número de la sede) |
| `announcement`, `benefits`, `rich_text`, `faq`, `store_info` | Se conservan; pasan a usar los componentes nuevos |

- El renderizador de cada tipo va en `StoreBlocks.tsx`, que se parte en archivos por bloque bajo `pages/Storefront/blocks/` **sin** cambiar el punto de entrada `StoreBlocks`.
- **Íconos:** uno por uno (regla de `CLAUDE.md`).

### 7.3 Aceptación de la Fase 2

- [ ] Cambio los mensajes de la barra superior, el estilo del encabezado, los ítems del menú (con «Ofertas» en rojo), la tarjeta (sin marca, etiqueta «Ahorra $»), el pie y la navegación del celular. Todo se ve en la vista previa antes de publicar y en la tienda después.
- [ ] Armo la portada con banner principal + lateral (con imagen propia para celular), un estante deslizable de ofertas, «Vuelve a comprar» y una oferta relámpago.
- [ ] No puedo publicar un banner sin texto alternativo.
- [ ] Restauro una versión anterior y todo (bloques + estructura) vuelve como estaba.
- [ ] «Copiar a otras sedes» copia la estructura global.

---

## 8. Fase 3 — Páginas de categoría, búsqueda, producto y carrito

- **Rutas nuevas** (declaradas en `routes/storefront.php` **antes** de las que llevan parámetro, y sin chocar con `Company::RESERVED_SLUGS`):
  - `GET /tienda/categoria/{category:slug}` (`storefront.category`)
  - `GET /tienda/ofertas` (`storefront.deals`)
  - `GET /tienda/buscar` (`storefront.search`)
  - `GET /tienda/buscar/sugerencias` (JSON, `throttle:60,1`)
  - `storefront.home?category=` redirige a la ruta de categoría.
- **`CatalogController`:**
  - Filtros de `category_page.filters`: subcategorías (`parent_id`), marca (`products.brand`), rango de precio **por el precio vigente** (no `products.price`; si el filtro por precio con promociones es costoso, se filtra por el precio vigente calculado en SQL como hace `visibleProducts`, o se documenta la limitación), solo ofertas, solo disponibles.
  - Orden de `category_page.sorts`.
  - Paginación o «Cargar más» (Inertia `merge` o `only` + página).
  - Los servicios entran como hoy.
- **Sugerencias de búsqueda:** hasta 6 artículos (nombre, foto, precio) + categorías que coinciden + `search.popular_terms` cuando el campo está vacío. Sin tildes y sin mayúsculas en la comparación.
- **Producto:** secciones de `product_page` y bloques de la página `product` debajo.
- **Carrito:**
  - Contador, avance, mínimo de pedido (`delivery.min_order_total`: «Te faltan $ X para el pedido mínimo», y el botón de pagar se bloquea en el servidor **y** la UI lo explica).
  - Cupón (lo de hoy) y bloques de la página `cart`.
- **Checkout y demás páginas:** mismo cascarón y componentes; sin cambio de lógica.
- **Aceptación:**
  - [ ] Filtro «Aseo › Detergentes», marca y «solo ofertas», ordeno por menor precio; en celular los filtros salen en una hoja.
  - [ ] Busco «arros» y la sugerencia trae «Arroz».
  - [ ] En el carrito bajo del mínimo y el botón de pagar se explica y el servidor lo rechaza.

---

## 9. Fase 4 — Plantilla base del super usuario

Todo §5: migraciones, `StorefrontTemplate`, `StorefrontTemplateService` (`publish`, `setDefault`, `detach`, `reattach`, `apply`), render compuesto, editor reutilizado en contexto `template`, permisos `platform.store_templates.*`, menú y vista previa con catálogo de una sede elegida.

**Aprovisionamiento:** `CompanyProvisioner` y `StorefrontManager::for` asignan la plantilla por defecto con `follows_template = true`. La tienda sigue naciendo **apagada**; la primera publicación la enciende (regla vigente).

**Aceptación:**

- [ ] Publico un cambio en la plantilla «Supermercado» y El Vecino (que la sigue) lo muestra, conservando su nombre, logo, color, domicilio y pagos.
- [ ] Personalizo El Vecino: aparece el aviso, se independiza, sus imágenes quedan en su biblioteca, y un nuevo cambio en la plantilla ya no le llega.
- [ ] «Volver a la plantilla» restaura el diseño de la plantilla y deja una versión para deshacer.
- [ ] En modo plantilla no puedo elegir artículos, categorías ni promociones por id, y el servidor lo rechaza si lo intento.
- [ ] Una empresa nueva nace con la plantilla por defecto.

---

## 10. Fase 5 — Semillas, rendimiento, accesibilidad y pruebas

- **Semillas:**
  - Plantilla **«Supermercado»**, publicada y por defecto, con todo lo de §3 (banners con imágenes de ejemplo en `storage` como medios de plataforma, estantes, categorías, pie).
  - **El Vecino** la sigue.
  - **La Canasta** independizada con portada propia.
  - Íconos a las categorías de demostración.
  - Algunos artículos con `image_url`.
- **Rendimiento:**
  - Solo la primera imagen con prioridad.
  - `srcset` desde variantes.
  - Estantes con `limit`.
  - Sugerencias con índice por nombre.
  - El resumen `cart` sin consultas N+1.
- **Accesibilidad:**
  - Encabezados en orden.
  - Foco visible.
  - Carruseles con controles y pausa.
  - Contraste del acento y del rojo de oferta verificado en el servidor.
  - Contador con `aria-label` («Quitar uno de Arroz Vecino»).
  - Menú grande operable con teclado.
- **Pruebas (`tests/Feature`):**

  | Archivo | Qué prueba |
  |---|---|
  | `StorefrontStructureSettingsTest` | Cada clave nueva valida listas cerradas, `media_id` de la empresa, variables de la barra; se versiona, publica y restaura |
  | `StorefrontBlockPagesTest` | `pages()` limita tipos por página; instantáneas viejas sin `page` = home; `only_categories` |
  | `StorefrontBlocksNewTest` | `banner_grid`, `deal_countdown` (desaparece al vencer), `cta_band`; `product_grid` carrusel, `new_arrivals`, `buy_again` (sin sesión no se pinta; solo compras de esa empresa); `issues()` bloquea banner sin `alt` |
  | `StorefrontCartQuantityTest` | Cantidad absoluta idempotente; 0 quita; sin existencias devuelve disponible; carrito de otra empresa no se toca; resumen = total del checkout |
  | `StorefrontFreeDeliveryTest` | `free_from = 0` → sin texto ni barra; faltante correcto; mínimo de pedido bloquea en el servidor |
  | `StorefrontProductPresentationTest` | Respaldo de imagen hasta `image_url`; precio por unidad del servidor; `stock_display` (oculto, pocas, exacto) |
  | `StorefrontCategoryPageTest` | Filtros, orden por precio vigente, subcategorías, paginación, ofertas, búsqueda y sugerencias sin tildes |
  | `StorefrontTemplateTest` | Seguir la plantilla, composición diseño + sede, publicar propaga, `detach` copia medios y no rompe si se borra la plantilla, `reattach` con versión previa, contexto `template` rechaza ids, una sola por defecto, aprovisionamiento |
  | `MediaAssetPlatformScopeTest` | Medios de plataforma (`company_id` null) invisibles para empresas y visibles para el renderer |
  | `RouteSmokeTest` | Rutas nuevas del panel y de plataforma; exime por nombre de parámetro lo que el barrido no monte |

---

## 11. Reglas nuevas para `CLAUDE.md`

Agrégalas en «Trampas conocidas» al terminar:

```markdown
- **Tienda por paginas**: `storefront_blocks.page` (home, category, product, cart, search) y cada tipo declara
  en que paginas vale (`BlockData::pages()`). Una instantanea vieja sin `page` es portada.
- **Estructura global de la tienda** (barra superior, encabezado, menu, tarjeta, barra del carrito, navegacion
  del celular, pie, paginas de categoria/producto/busqueda) vive en `storefront_settings` con su defecto en
  `StorefrontManager::defaultSettings()`: se versiona y se publica con los bloques. No la pongas en el `.tsx`.
- **Un solo `ProductCard`** para toda la tienda y la vista previa. El precio por unidad lo calcula el
  servidor (`StorefrontRenderer::pricePerUnit`); la imagen llega resuelta (`image_path` → `thumb_path` →
  `image_url`).
- **Carrito de la tienda**: se agrega con `PUT /tienda/carrito/cantidad` (cantidad absoluta, idempotente) y el
  resumen viaja en la prop `cart`. El navegador es optimista pero el total siempre sale del servidor.
- **Domicilio gratis**: `delivery.free_from = 0` significa que no hay; nunca pintes «gratis desde $ 0».
- **Plantilla de tienda**: diseño = `StorefrontTemplate::DESIGN_KEYS`; marca, domicilio, pagos, contacto y
  datos legales son siempre de la sede. Una plantilla nunca guarda ids de articulos, categorias ni
  promociones (`fields('template')`). Independizarse copia los medios de plataforma a la biblioteca de la
  empresa; no referencies medios de plataforma desde una tienda independiente.
- **Medios de plataforma**: `media_assets.company_id` nulo. Nunca los muestres en la biblioteca de una empresa.
- **Franja legal del pie**: sale de la empresa (razon social, NIT, direccion, contacto) y no se puede quitar.
```

---

## 12. Entrega

Al cerrar cada fase, reporta:

- Archivos creados y modificados.
- Migraciones.
- Resultado de `npx tsc --noEmit`, `npm run build`, `php artisan test` y `php artisan migrate:fresh --seed`.
- **Capturas en celular y computador** de la portada, una categoría, un producto y el carrito.
- Lo que quedó pendiente, dicho sin rodeos.
