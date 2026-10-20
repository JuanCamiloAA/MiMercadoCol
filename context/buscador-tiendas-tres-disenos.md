# Buscador de tiendas: tres diseños parametrizables desde el editor de la landing

> Especificación para implementar en AgoraShop. Bloque afectado: `store_finder`
> (`app/Landing/Blocks/StoreFinderBlock.php`, `LandingRenderer::stores()`,
> `resources/js/components/landing/LandingBlocks.tsx`, `StoreFinderMap.tsx`).
> Referencia visual: el lienzo «Encuentra tu tienda — propuestas» (artboards A, B, C y celular).

## 1. Objetivo

Que quien edita la landing elija entre tres diseños del buscador, y ajuste cada uno, sin tocar código:

| Clave `layout` | Nombre en el editor | Para qué sirve |
|---|---|---|
| `list_map` | Lista compacta + mapa | Muchas sedes en pocas ciudades; la persona compara y elige en el mapa |
| `nearest_first` | La más cercana primero | La mayoría compra en la sede que le queda cerca; una decisión, un botón |
| `by_company` | Agrupado por comercio | Varias empresas con varias sedes; la persona elige la marca y luego la sede |

Todo lo que hoy funciona se conserva: filtros por ciudad y clasificación, `?tipo=` en el enlace,
«Usar mi ubicación» bajo demanda (nunca al cargar, nunca guardada), «Seguir comprando en…»,
la ventana ampliada del mapa, «Buscar en esta zona» y la vista previa honesta.

## 2. Problemas del diseño actual que los tres corrigen

1. Un botón lleno «Comprar aquí» por tarjeta (siete en la demo, en tres colores): ninguno destaca.
2. La distancia, que es el criterio de decisión, es el texto más chico de la tarjeta.
3. Lista y mapa no se conectan: el pin dice «L» o «M», no cuál tarjeta es.
4. La tarjeta repite la clasificación que ya está en los filtros, y la empresa aparece dos veces.
5. «Usar mi ubicación» se ve deshabilitado (gris) siendo la acción más útil.

Reglas comunes a los tres diseños:

- **Un solo llamado principal visible** a la vez: el de la sede elegida (A), el de la más cercana (B)
  o uno por comercio (C). El color de la sede (`accent` / `accent_ink`) solo se usa ahí y en el pin.
- **La distancia va en negrita a la derecha** cuando existe (`distance_km`).
- **Horario como frase**: «Abierta · cierra 9:00 p. m.» o «Cerrada · abre 7:00 a. m.», no el rango.
- **La clasificación no se repite en la fila** cuando hay filtro de clasificación en pantalla.

## 3. Campos nuevos del bloque

Todos con defecto en `defaults()`. Un bloque o una versión publicada sin estas claves vale con sus
defectos (igual que las `map_*`), así que no hace falta migración de datos.

### 3.1 Comunes

| Clave | Tipo | Opciones / máx. | Defecto | Etiqueta |
|---|---|---|---|---|
| `layout` | select | `list_map`, `nearest_first`, `by_company` | `list_map` | Diseño |
| `cta_label` | text | 40 | `Comprar en esta tienda` | Texto del botón de compra |
| `last_store_style` | select | `chip`, `banner`, `off` | `chip` | «Seguir comprando en…» |
| `filter_open_now` | toggle | | `true` | Ofrecer el filtro «Abiertas ahora» |
| `filter_delivery` | toggle | | `true` | Ofrecer el filtro «Con domicilio» |
| `show_delivery` | toggle | | `true` | Mostrar si lleva domicilio y desde cuánto |
| `show_next_slot` | toggle | | `false` | Mostrar la próxima franja de entrega |

`chip` pone «Tu última tienda · Seguir comprando» al lado del título; `banner` es la franja de hoy.

### 3.2 Solo `list_map` (A)

| Clave | Tipo | Opciones | Defecto | Etiqueta |
|---|---|---|---|---|
| `list_numbered` | toggle | | `true` | Numerar sedes y pines del mapa |
| `list_width` | select | `narrow` (≈26 rem), `medium` (≈30 rem) | `medium` | Ancho de la lista |
| `cta_mode` | select | `selected`, `all` | `selected` | Botón de compra: solo en la sede elegida / en todas |
| `preselect_nearest` | toggle | | `true` | Dejar elegida la primera de la lista |
| `mobile_mode` | select | `tabs`, `sheet` | `sheet` | En el celular: selector Lista \| Mapa / mapa con hoja deslizable |

### 3.3 Solo `nearest_first` (B)

| Clave | Tipo | Opciones / máx. | Defecto | Etiqueta |
|---|---|---|---|---|
| `hero_badge` | text | 30 | `La más cercana` | Etiqueta de la tienda destacada |
| `hero_fallback` | select | `last_store`, `prompt`, `none` | `prompt` | Sin ubicación, destacar: la última tienda / una invitación a compartir ubicación / nada |
| `hero_map` | toggle | | `true` | Mapa pequeño en la tienda destacada |
| `others_title` | text | 60 | `Otras tiendas cerca` | Título del resto |
| `others_columns` | select | `2`, `3`, `4` | `3` | Columnas del resto (computador) |
| `others_limit` | select | `6`, `9`, `12`, `all` | `9` | Cuántas mostrar antes de «Ver todas» |

### 3.4 Solo `by_company` (C)

| Clave | Tipo | Opciones | Defecto | Etiqueta |
|---|---|---|---|---|
| `company_order` | select | `nearest`, `name`, `stores_count` | `nearest` | Orden de los comercios |
| `company_header` | select | `accent`, `neutral` | `accent` | Encabezado de la tarjeta: color de la marca / neutro |
| `stores_per_company` | select | `3`, `5`, `all` | `3` | Sedes visibles por comercio |
| `company_cta` | toggle | | `true` | Botón «Comprar en ‹sede más cercana›» al pie |

`company_order = nearest` sin ubicación cae a `name` (no hay distancia que ordenar).

### 3.5 El mapa según el diseño

`map_layout` sigue existiendo, pero no toda combinación tiene sentido. `normalize()` la corrige:

| `layout` | `map_layout` permitidos | Si llega otro |
|---|---|---|
| `list_map` | `side` | `side` (el diseño **es** lista + mapa) |
| `nearest_first` | `button`, `top`, `none` | `button` |
| `by_company` | `button`, `none` | `button` |

Si la plataforma no tiene proveedor de mapas (`map === null`), los tres funcionan sin mapa, como hoy.

## 4. Editor: mostrar solo los campos del diseño elegido

`LandingBlock::fields()` hoy no tiene condiciones. Agregar una clave genérica, opcional:

```php
'list_numbered' => ['type' => 'toggle', 'label' => '…', 'when' => ['layout' => ['list_map']]],
```

- El formulario del editor de la landing (el que arma los campos desde `toCatalogue()['fields']`)
  oculta el campo si el valor actual de la clave de `when` no está en la lista. Es genérico: sirve para
  cualquier bloque futuro. Documentarlo en el docblock de `LandingBlock` junto a los tipos de campo.
- `normalize()` **no** borra los valores ocultos: cambiar de diseño y volver no pierde lo configurado.
- Agrupar en el formulario con un subtítulo por sección: «Diseño», «Contenido», «Mapa».
- En `map_layout`, `options` puede declarar `when` por opción para que el select solo ofrezca las
  permitidas por el diseño (tabla 3.5). La corrección de `normalize()` se mantiene igual: el editor
  ayuda, el servidor manda.

## 5. Servidor

### 5.1 `StoreFinderBlock`

- Sumar los campos de la sección 3 a `fields()` y `defaults()`.
- Sobrescribir `normalize()`: llamar a `parent::normalize()` y luego aplicar la tabla 3.5 a
  `map_layout`. Los select numéricos (`others_columns`, `stores_per_company`…) se guardan como string,
  igual que el resto de select; el `.tsx` los compara con `String(...)`.
- Actualizar `help()`: «Elige el diseño: lista con mapa, la más cercana primero o por comercio.»

### 5.2 `LandingRenderer::stores()` — datos que hoy faltan

Cada sede suma un resumen de entrega, **siempre de la versión publicada** de su tienda
(`StorefrontConfig`, no el borrador ni `SettingsService`):

```php
'delivery' => [
    'enabled' => bool,            // settings($store)['delivery']['enabled']
    'fee_from' => ?int,           // el menor entre la tarifa base y las de zones(); null si no hay domicilio
    'free_from' => ?int,          // null cuando free_from = 0 (0 significa «nunca gratis»)
    'min_order_total' => int,     // minOrderTotal()
    'next_slot' => ?string,       // etiqueta de la primera franja con cupo de slots($store, 3); null si no hay franjas
],
'hours_label' => string,          // «cierra 9:00 p. m.» / «abre 7:00 a. m.» / «abre mañana 7:00 a. m.»
```

- La frase del horario se arma en el servidor (Bogotá, formato `g:i a` en español) para que la tarjeta,
  el mapa y la ventana ampliada digan lo mismo. `is_open` no cambia.
- Costo: hoy `stores()` ya pide el cascarón de cada sede. Cargar los `storefront_settings`
  publicados de todas las sedes en una sola consulta (como se hace con `Storefront`) y calcular desde
  ahí; no llamar a `StorefrontConfig` sede por sede con su propia consulta.
- `next_slot` solo se calcula si **algún** bloque `store_finder` visible tiene `show_next_slot`
  (cuenta cupos ocupados; no se paga si nadie lo muestra).
- `nearby()` hereda todo esto porque parte de `stores()`.
- Los montos viajan en COP entero; el `.tsx` los formatea con `money()`.

Nada del bloque calcula precios ni lee `products`: el resumen es de domicilio, no de catálogo.

## 6. Front (`LandingBlocks.tsx`)

`StoreFinderBlock` conserva todo el estado y los filtros actuales (ciudad, clasificación, búsqueda,
cercanía, zona, sede elegida). Lo único que cambia es **cómo se pinta la lista**: se parte en tres
componentes que reciben la misma lista filtrada (`listStores`) y los mismos manejadores.

```
StoreFinderBlock
├─ StoreFinderHeader          título + chip/banner de última tienda
├─ StoreFinderFilters         ubicación, búsqueda, ciudad, chips (+ Abiertas ahora, Con domicilio)
└─ según data.layout
   ├─ ListMapLayout           (A)
   ├─ NearestFirstLayout      (B)
   └─ ByCompanyLayout         (C)
```

Filtros nuevos (estado local, no van al enlace): `openNow` filtra `is_open`; `withDelivery` filtra
`delivery.enabled`. Se aplican en el mismo `shown` de hoy, antes de la zona del mapa.

«Usar mi ubicación»: botón lleno con `--accent` siempre (hoy se ve gris en oscuro: revisar que el
contraste use `--accent-ink`). En A y en el celular puede ir dentro del campo de búsqueda como
«Cerca de mí».

### 6.1 A · `ListMapLayout`

- Rejilla `lg:grid-cols-[<list_width>_minmax(0,1fr)]`; el mapa `lg:sticky lg:top-20` y alto completo
  de la columna (`map_height` sigue mandando).
- `StoreRow` (nuevo, reemplaza a `StoreCard` en este diseño): número en círculo con el color de la
  sede, nombre en negrita, distancia a la derecha, segunda línea «Empresa · dirección», tercera
  «Abierta · cierra 9:00 p. m. · Domicilio desde $ 4.000». Toda la fila es un `<button>` que elige
  la sede (`select(id)` + `focus`); el enlace de compra va **dentro** solo cuando está elegida
  (`cta_mode = selected`) o siempre como enlace secundario (`all`).
- Sede elegida: borde de `--fg`, y debajo `cta_label` (lleno, color de la sede) + «Cómo llegar»
  (abre Google Maps con lat/lng, `target="_blank" rel="noopener"`).
- `preselect_nearest`: al llegar `nearby`, elegir la primera; sin ubicación, no elegir nada.
- `list_numbered`: el número es la posición en `listStores` (1…n). `StoreMap` recibe un mapa
  `labels: Record<id, string>` y lo pinta en el marcador en vez de la inicial. Al agrupar
  (`map_cluster`) el círculo sigue mostrando la cantidad.
- Celular, `mobile_mode = sheet`: el mapa ocupa la pantalla del bloque (alto fijo `h-[36rem]`, no
  `100vh`) y la lista va en una hoja con tres alturas (cerrada con la sede elegida, media, completa).
  La hoja es un elemento propio con `map-overlay`, nunca tapa el crédito del proveedor. `tabs` es el
  comportamiento de hoy.

### 6.2 B · `NearestFirstLayout`

- **Destacada**: la primera de `listStores` cuando hay `nearby`. Tarjeta ancha: mapa pequeño
  (`hero_map`, con el punto de la persona y el pin de la sede; si no hay mapa, el logo grande),
  etiqueta `hero_badge`, empresa, nombre, dirección, tres datos (Distancia · Domicilio · Próxima
  franja — cada uno solo si existe) y `cta_label` lleno + «Ver en el mapa».
- **Sin ubicación**, según `hero_fallback`:
  - `last_store`: destaca la última tienda (si sigue vendiendo) con la etiqueta «Tu última tienda».
  - `prompt`: una tarjeta del mismo tamaño que invita a compartir la ubicación, con el botón
    «Usar mi ubicación» y el campo de búsqueda; no inventa una «más cercana».
  - `none`: no hay destacada, solo la rejilla.
- **El resto**: rejilla de `others_columns` (1 en celular, 2 en `sm`) con tarjetas compactas: logo
  o inicial, nombre, empresa, estado + distancia, flecha. Toda la tarjeta es el enlace de compra
  (guarda la última tienda con `remember`). Corte en `others_limit` y botón «Ver todas (n)».
- Mapa: `button` → botón «Ver todas en el mapa» que abre `StoreMapDialog`; `top` → franja arriba de
  la rejilla.

### 6.3 C · `ByCompanyLayout`

- Agrupa `listStores` por `company_id` en el navegador (los filtros ya se aplicaron; no hay SQL nuevo).
  Una empresa sin sedes después de filtrar no sale.
- Tarjeta por comercio: encabezado con logo o inicial, nombre (`company`), clasificación y
  «n sedes»; fondo `accent` con `accent_ink` (`company_header = accent`) o superficie neutra con una
  línea de acento.
- Lista de sedes ordenada por distancia (o por nombre sin ubicación), cortada en
  `stores_per_company` con «+ n sedes más» que despliega en el sitio. Cada sede es un enlace de compra.
- Pie (`company_cta`): «Comprar en ‹sede›» con la primera de la lista.
- Orden de los comercios: `nearest` por la distancia de su sede más cercana; `stores_count` de más a
  menos sedes; `name` alfabético.

### 6.4 Tema y unidades

- Tamaños en `rem` y esquinas con `rounded-chip/control/card/sheet`; nada de `text-[13px]` nuevo
  (`node scripts/codemod-units.mjs --check`).
- Colores por variables (`--surface`, `--line`, `--muted`, `--accent`…): los tres diseños funcionan en
  claro y oscuro. El color de cada sede solo en número/pin/botón, con su `accent_ink`.
- Íconos de Phosphor importados uno por uno, como el resto del archivo.

## 7. Vista previa honesta

En `isPreview` no se pide ubicación. Para que quien edita vea algo real:

- **A**: con `preselect_nearest`, elige la primera de la lista y muestra el aviso «En la tienda en
  línea se elige la más cercana al visitante».
- **B**: la destacada es la primera de la lista con la etiqueta cambiada a «Ejemplo: así se verá la
  más cercana». Si `hero_fallback = prompt`, se muestra además la tarjeta de invitación debajo del
  aviso para que se vea cómo queda.
- **C**: sin cambios; el orden `nearest` se ve como `name` y el aviso lo dice.
- Se mantiene el aviso de sedes sin coordenadas.

## 8. Accesibilidad

- Filas de A: `<button aria-pressed>`; el enlace de compra fuera del botón (no anidar interactivos).
- Pines numerados: `aria-label="3. Sede Centro, La Canasta"`.
- Hoja del celular: `role="dialog"` no modal con su asa como `<button aria-label="Ver más tiendas">`.
- Objetivos táctiles ≥ 44 px; texto secundario ≥ 4,5:1 contra `--surface`.
- Estado abierta/cerrada no solo por color: siempre con la palabra.

## 9. Pruebas (`tests/Feature/LandingTest.php`)

1. Un `store_finder` guardado sin `layout` se normaliza a `list_map` y conserva sus `map_*`.
2. `layout = list_map` con `map_layout = button` se guarda como `side`; `by_company` con `top` se
   guarda como `button`.
3. Los campos de un diseño no elegido se conservan al cambiar de diseño y volver.
4. `source.stores[*].delivery` sale de la versión **publicada**: cambiar el borrador de domicilio no
   cambia la landing hasta publicar.
5. `free_from = 0` llega como `null` (nunca «gratis desde $ 0»).
6. Una sede sin domicilio llega con `enabled = false` y `fee_from = null`.
7. `next_slot` no se calcula (sin consultas de cupos) cuando ningún bloque lo pide.
8. `hours_label`: antes de abrir, abierta y después de cerrar, con `Carbon::setTestNow` en Bogotá.
9. `landing.nearby` devuelve el mismo `delivery` y `hours_label` que la carga inicial.

`RouteSmokeTest` ya cubre el editor; si el formulario nuevo pide una prop, ahí sale.

## 10. Orden de trabajo sugerido

1. `when` genérico en el formulario del editor + docblock de `LandingBlock`.
2. Campos, defectos y `normalize()` de `StoreFinderBlock`; pruebas 1–3.
3. `delivery` y `hours_label` en `LandingRenderer::stores()`; pruebas 4–9.
4. Partir `StoreFinderBlock` del `.tsx` en cabecera, filtros y diseño; `labels` en `StoreMap`.
5. `ListMapLayout` (incluida la hoja del celular), luego `NearestFirstLayout`, luego `ByCompanyLayout`.
6. Vista previa honesta y accesibilidad.
7. `npx tsc --noEmit`, `npm run build`, `php artisan test`.

## 11. Fuera de alcance

- Guardar la ubicación del visitante (sigue sin guardarse).
- Calcular el costo de domicilio por dirección: el resumen dice «desde», el valor exacto lo da el
  checkout de la tienda con sus zonas.
- Cambiar el proveedor de mapas o `MapConfig`.
