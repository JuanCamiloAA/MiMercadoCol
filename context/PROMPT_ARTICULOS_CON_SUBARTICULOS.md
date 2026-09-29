# PROMPT — Artículos con subartículos (tallas, colores, presentaciones)

> **Para:** Claude Opus, trabajando en `C:\xampp\htdocs\MiMercadoCol` (La Canasta — Laravel 12 + Inertia 2 + React 19 + TS estricto).
> **Fecha:** 2026-09-29.
> **Alcance:** cada artículo se crea como **individual** (como hoy) o **con subartículos**, a decisión de la empresa **en cada artículo**. En caja, compras e inventario cada subartículo es un artículo independiente; en la tienda web se ve **un solo artículo** con selectores de talla, color o presentación.

---

## 0. Cómo trabajar

1. **Lee primero `CLAUDE.md` y `README.md`.** Mandan. Lo que más pesa aquí:
   - COP entero en `bigInteger`.
   - `string` + constantes, nunca `enum`.
   - `dateTime`.
   - Un FormRequest por acción.
   - `permission:` en toda ruta.
   - `$fillable` completo.
   - **El escáner no puede tener dos respuestas** (`ScanCodeGuard`).
   - **EAN siempre string.**
   - **El precio solo lo sabe `PromotionService`.**
   - **«Lleva N paga M» se cuenta por artículo:** aquí cambia, ver D3.
   - **Cuentas por sede, no por fila.**
   - Importar maestras con `MasterImport` + `ImportRegistry` (pantalla y lector compartidos).
   - **Un solo `ProductCard` en la tienda.**
   - La tienda arma sus fichas en `StorefrontProducts`.
   - Permiso nuevo = migración de reparto.
   - Íconos Phosphor uno por uno.
   - Nada de arrays literales en `.tsx`.
2. **Verifica antes de escribir.** Las referencias salen del código del 2026-09-29. Si algo cambió, adapta y dilo.
3. **Por fases (§6 a §10).** Cada fase cierra con:

   ```bash
   npx tsc --noEmit
   npm run build
   php artisan test
   php artisan migrate:fresh --seed
   ```

   Más el recorrido manual. Reporta al cerrar cada una.
4. **No rompas lo que funciona.** Todos los artículos existentes quedan **individuales** y se comportan exactamente igual en caja, inventario, compras, traslados, lotes, devoluciones, servicios, promociones, informes, tienda y pedidos.
5. **Si algo no está decidido aquí, pregunta.**

---

## 1. Qué pidió el dueño

- **Al crear un artículo, la empresa decide** si es **individual** o **con subartículos**. No es un ajuste general: una misma empresa tiene de los dos tipos.
- **Un artículo con subartículos agrupa variaciones**, cada una con su precio, código de barras, descripción, existencia, etc.:
  - un aceite en 500 ml, 1 L y 2 L;
  - un jean en tallas 6 a 16 (y, si se quiere, en colores).
- **En caja cada subartículo es individual.** Se escanea su código, sale con su nombre completo, se cobra a su precio.
- **En la tienda web aparece un solo artículo** (el principal) con sus subartículos para escoger, como en la imagen de referencia:

  > **Jean skinny tono oscuro para mujer** · $239.900
  > Talla: [6] 8 10 12 14 ~~16~~ ← la agotada, tachada

### Decisiones del dueño (cerradas)

| # | Tema | Decisión |
|---|---|---|
| D1 | Características por artículo | **Hasta 2** (ej. Talla y Color). Un solo nivel: un subartículo no tiene subartículos. |
| D2 | De dónde salen los valores | **Catálogo de atributos por empresa** (Tallas de ropa, Tallas de calzado, Colores con su muestra, Presentación…), reutilizable. Se pueden crear valores nuevos al vuelo desde el artículo. |
| D3 | Promoción «lleva N paga M» | **Cuentan juntos los subartículos del mismo artículo principal:** 1 jean talla 8 + 2 talla 10 = 3, y se regala el más barato. Artículos distintos siguen sin juntarse. |
| D4 | Compras | **Con matriz:** se agrega el artículo principal a la orden y aparece una tabla (talla × color) para poner cantidades y costos de una vez, al pedir y al recibir. Cada casilla es un renglón normal de la orden. |

---

## 2. La idea central (no la cambies sin preguntar)

**Cada subartículo ES una fila de `products`.** El artículo principal es una fila nueva de **`product_families`**, que **no se vende, no tiene existencias, no se compra ni se traslada**.

Así, todo lo que hoy trabaja con `product_id` sigue funcionando sin tocarlo:

- caja, `sale_items`, kardex, `product_stocks`, lotes;
- órdenes de compra, recepción, devoluciones a proveedor y de clientes;
- traslados, servicios (`service_components`), pedidos, carrito, etiquetas, sugerido de compra.

El principal es una **capa de presentación y administración**: agrupa, hereda datos comunes y se muestra en la tienda.

> **Por qué no meter el principal en `products`:** sería una fila sin existencias ni precio real que habría que excluir en cada conteo, informe, sugerencia de compra, búsqueda de caja y listado de inventario. Es el mismo razonamiento por el que los servicios viven aparte. Un solo olvido y el inventario miente.

**El nombre del subartículo se compone y se guarda** en `products.name`, más `variant_label` aparte:

```
products.name = "Jean skinny tono oscuro para mujer · Talla 8 · Azul"
```

Lo mantiene `ProductFamilyService` cada vez que cambia el nombre del principal o los valores. Así el tiquete, el kardex, las compras, los informes y las etiquetas muestran el nombre completo **sin tocar su código**.

---

## 3. Modelo de datos

### 3.1 Catálogo de atributos (por empresa, D2)

**`product_attributes`**

| Columna | Tipo | Nota |
|---|---|---|
| `company_id` | FK | `BelongsToCompany` + `CompanyScope` |
| `name` | `string(40)` | «Talla», «Color», «Presentación» |
| `slug` | `string(40)` | `unique(company_id, slug)` |
| `display` | `string(10)` | `ProductAttribute::DISPLAY_BUTTONS` (chips como la imagen), `DISPLAY_SWATCHES` (muestras de color o foto), `DISPLAY_SELECT` (lista, para muchos valores) |
| `sort_order` | unsignedSmallInteger | — |
| `is_active` | bool | — |
| timestamps | — | — |

**`product_attribute_values`**

| Columna | Tipo | Nota |
|---|---|---|
| `product_attribute_id` | FK `cascadeOnDelete` | — |
| `value` | `string(40)` | «8», «M», «Azul oscuro», «1 L» |
| `slug` | `string(40)` | `unique(product_attribute_id, slug)`. Normaliza: «M», «m» y « M » son el mismo valor |
| `color_hex` | `string(9)` nullable | Muestra de color |
| `media_id` | FK nullable a `media_assets` | Muestra con foto (estampado) |
| `sort_order` | unsignedSmallInteger | El orden de las tallas: 6, 8, 10… no alfabético |
| `is_active` | bool | — |
| timestamps | — | — |

Un valor **usado por algún subartículo** no se borra: se desactiva. Desactivado, no se ofrece para artículos nuevos, pero los existentes lo conservan.

### 3.2 Artículo principal

**`product_families`**

| Columna | Tipo | Nota |
|---|---|---|
| `company_id` | FK | — |
| `code` | `string(40)` nullable | Referencia interna. Único por empresa y **sin chocar** con SKU ni código de barras de artículos ni con códigos de servicios (`ScanCodeGuard` se amplía) |
| `name` | `string(160)` | «Jean skinny tono oscuro para mujer» |
| `search_name` | `string(255)` | `SearchText::key(name + brand)`, como `products`, con índice `(company_id, search_name)` |
| `slug` | `string(180)` | `unique(company_id, slug)`; URL de la tienda |
| `description` | text nullable | Descripción común |
| `category_id` | FK | **Común a todos los subartículos** |
| `brand` | string nullable | Común |
| `supplier_id` | FK nullable | Proveedor principal común |
| `tax_rate` | `decimal(5,2)` | Común |
| `attribute_1_id` | FK a `product_attributes` | Obligatorio |
| `attribute_2_id` | FK nullable | Segunda característica (D1) |
| `default_variant_id` | FK nullable a `products` | El que se muestra primero en la tienda |
| `is_active`, `is_online_visible`, `is_featured` | bool | — |
| `deleted_at`, timestamps | — | `softDeletes`, como `products` |

**`product_family_images`**

| Columna | Tipo | Nota |
|---|---|---|
| `product_family_id` | FK `cascadeOnDelete` | — |
| `path`, `thumb_path` | string | Por `ImageService`, como `product_images` |
| `attribute_value_id` | FK nullable | Foto de **un color**: al elegirlo en la tienda, la galería cambia |
| `sort_order` | unsignedSmallInteger | — |
| timestamps | — | — |

### 3.3 Subartículos (columnas nuevas en `products`)

| Columna | Tipo | Nota |
|---|---|---|
| `product_family_id` | FK nullable | `null` = artículo individual (todos los existentes) |
| `variant_value_1_id`, `variant_value_2_id` | FK nullable a `product_attribute_values` | — |
| `variant_key` | `string(40)` nullable | `"{valor1}-{valor2|0}"`; `unique(product_family_id, variant_key)`. No se confía en un único con `NULL` (MariaDB deja repetirlo) |
| `variant_label` | `string(80)` nullable | «Talla 8 · Azul» |
| `variant_sort` | unsignedSmallInteger | Orden según `sort_order` de los valores |

Índice `(company_id, product_family_id)`. **Todo en `$fillable`** (trampa: sin eso `create()` las ignora en silencio).

**Qué es común y qué es de cada subartículo:**

| Común (vive en la familia y se **copia** al subartículo) | De cada subartículo |
|---|---|
| `category_id`, `brand`, `supplier_id` principal, `tax_rate` | `sku`, `barcode`, `price`, `online_price`, `wholesale_price`, `cost` |
| El nombre (se compone) | `content_amount` / `content_unit` (el aceite de 500 ml y el de 1 L) |
| Descripción (si el subartículo no tiene propia) | `description` propia (opcional; si está vacía, la de la familia) |
| Fotos (si el subartículo no tiene propias) | Fotos propias (`product_images`, opcional) |
| — | `min_stock`, `track_lots`, `allow_negative_stock`, `is_active`, `is_online_visible` (ocultar solo una talla), `unit`, `sold_by_weight`, `purchase_pack_size` |

**Lo común se copia a cada subartículo** (desnormalizado) porque medio sistema lee `products.category_id`, `tax_rate` y `brand`: promociones por categoría, informes, tienda, compras. **Solo `ProductFamilyService` escribe esas copias**; el formulario del subartículo no deja editarlas.

---

## 4. `ProductFamilyService` (único camino)

| Método | Qué hace |
|---|---|
| `create(array $data, User $user): ProductFamily` | En una transacción: crea la familia y sus subartículos desde la matriz (§6.2), con existencia inicial por sede como hoy (`ProductController@store` la registra como ajuste). |
| `update(ProductFamily $family, array $data, User $user)` | Actualiza lo común y **lo propaga** a los subartículos (categoría, marca, IVA, proveedor principal y **nombre compuesto**). Agrega subartículos nuevos. Los quitados: si **tienen movimientos** (kardex, ventas, compras, pedidos, servicios, carritos) se **desactivan**, nunca se borran; si no tienen nada, se eliminan (soft delete). |
| `convertToFamily(Product $product, array $data)` | Un artículo individual pasa a ser el **primer subartículo** de una familia nueva. Conserva su `id`, existencias, kardex y ventas. |
| `group(array $productIds, array $data)` | Agrupa artículos individuales existentes (el aceite de 500 ml y el de 1 L creados antes) en una familia, asignando a cada uno sus valores. Mismo `company_id`; ninguno puede estar ya en otra familia ni tener valores repetidos. |
| `detach(Product $variant)` / `ungroup(ProductFamily $family)` | El subartículo vuelve a ser individual: `product_family_id` y los valores a `null`, nombre = el compuesto actual (editable después). Deshacer la familia entera = todos, y la familia se elimina (soft). |
| `composeName(ProductFamily, Product)` | `"{familia} · {atributo1} {valor1} · {valor2}"`. Presentación: «Aceite de girasol · 900 ml». Respeta `products.name` máx. 255 |

**Reglas:**

- Una familia **no puede quedar sin subartículos activos** si está publicada en la tienda: la tienda la oculta.
- Si se desactivan todos los subartículos, se desactiva la familia.
- Cambiar `tax_rate` común **no toca ventas pasadas** (`sale_items.tax_rate` ya guarda la tasa del momento).
- `search_name` de cada subartículo se recalcula por el gancho de `Product::saving`, que ya existe, al cambiar el nombre compuesto.

---

## 5. Permisos, menú y catálogo de atributos

- **`PermissionHelper`, módulo `products`:** página nueva `attributes` («Atributos: tallas, colores, presentaciones»), acciones `view` y `manage`.
- **Crear y editar artículos con subartículos** usa los permisos de artículos que ya existen (`products.index.create`, `edit`, `delete`, y `products.show.edit_price` para los precios). **No inventes otros.**
- **Migración de reparto** (patrón `…_grant_sales_history_permission`):
  - `products.index.view` → `products.attributes.view`
  - `products.index.edit` → `products.attributes.manage`
  - Roles según la misma equivalencia.
- **Menú:** grupo `catalog` → «Atributos» (`routeName: 'attributes.index'`, `permission: 'products.attributes.view'`, ícono `TagSimpleIcon` suelto), después de Categorías.
- **Pantalla `pages/Attributes/Index.tsx`** (rutas `/atributos`, FormRequests propios):
  - Lista de atributos con sus valores. Crear, renombrar, forma de mostrarse, **reordenar valores arrastrando**, color o foto de muestra, desactivar.
  - Cuántos subartículos usan cada valor.
- **Semilla por empresa** (en `CompanyProvisioner` y para las empresas existentes en migración):

  | Atributo | Valores |
  |---|---|
  | Talla (ropa) | XS, S, M, L, XL, XXL |
  | Talla (numérica) | 4 a 18 de dos en dos |
  | Talla (calzado) | 34 a 44 |
  | Color | Negro, Blanco, Azul, Rojo, Gris, Beige, con `color_hex` |
  | Presentación | Sin valores: los pone cada empresa |

---

## 6. Fase 1 — Modelo, servicio y formulario del panel

### 6.1 Migraciones y modelos

- Migraciones de §3.
- Modelos:
  - `ProductAttribute`, `ProductAttributeValue`.
  - `ProductFamily`: `variants()` ordenados por `variant_sort`, `images()`, `category()`, `attribute1()`, `attribute2()`, `defaultVariant()`.
  - `ProductFamilyImage`.
  - `Product` gana `family()`, `value1()`, `value2()`, `isVariant()`.
- `ProductFamilyService`.
- `ScanCodeGuard` ampliado: el `code` de la familia entra en las dos direcciones.
- Permisos y atributos (§5).

### 6.2 Crear y editar (`pages/Products/Form.tsx`)

**Primera decisión, arriba del formulario** (control segmentado, solo al crear):

> **Tipo de artículo:** ( ● Individual ) ( ○ Con subartículos: tallas, colores o presentaciones )

- **Individual:** el formulario de hoy, **sin ningún cambio**.
- **Con subartículos:** `pages/Products/FamilyForm.tsx`, con los componentes de `ui/`:

1. **Identificación común:** nombre, código de referencia (opcional), categoría, marca, proveedor principal, IVA, descripción.
2. **Fotos comunes:** con la opción de marcar a qué color corresponde cada una.
3. **Características:**
   - Elegir 1 o 2 atributos del catálogo.
   - Marcar los valores que aplican (chips).
   - «+ Nuevo valor» crea el valor en el catálogo al vuelo (con `products.attributes.manage`; sin el permiso, el botón no se pinta).
4. **Subartículos (matriz):** una fila por combinación. Con 2 atributos, agrupadas por el primero. Columnas:

   | Columna | Nota |
   |---|---|
   | Nombre compuesto | Solo lectura |
   | SKU | Sugerido: `{código o SKU base}-{slug1}-{slug2}`, editable |
   | Código de barras | Enfocar la celda y **escanear** llena el campo y baja a la siguiente fila |
   | Precio | — |
   | Precio en línea | — |
   | Costo | Con `products.show.view_cost` |
   | Contenido | Cantidad + unidad, para presentaciones |
   | Mínimo | — |
   | Activo | — |
   | Visible en línea | — |
   | Existencia inicial por sede | Solo al crear |

   - **Acciones de llenado:** «Aplicar a todas» (precio, costo, precio en línea, mínimo); «Aplicar a las de Talla 14 y 16»; «Sumar $ X a las de…».
   - **Quitar combinaciones que no existen** (ej. talla 16 solo en negro).
   - **Cada fila abre un panel** con lo propio del subartículo: descripción propia, fotos propias, lotes, permitir negativo, por peso.
5. **Tienda:** visible, destacado, y cuál subartículo se muestra primero (por defecto el primero activo).

**Validación** (`StoreProductFamilyRequest` / `UpdateProductFamilyRequest`):

- Nombre y categoría obligatorios; atributos distintos entre sí; al menos 1 subartículo activo.
- SKU obligatorio y único por empresa, **también dentro de la misma matriz**.
- Códigos de barras string, únicos en la empresa y dentro de la matriz, y sin chocar con servicios ni familias (`ScanCodeGuard`).
- Precios ≥ 0. Un subartículo **activo y visible en línea** con precio 0 no se permite: «un artículo activo con precio cero es un artículo que la caja regala».
- Combinaciones sin repetir.
- Mensajes por fila: «Fila Talla 12 · Azul: ese código de barras ya es de Jean clásico · Talla 12».

**Editar** usa la misma pantalla. Las filas con movimientos no se pueden quitar, solo desactivar, con la explicación en pantalla.

**Acciones en el listado y la ficha de un artículo individual:**

- «Convertir en artículo con subartículos».
- «Agrupar con otros artículos» (selección múltiple en el listado → asistente que pide atributos y valores de cada uno).

**En la ficha de la familia:**

- «Separar subartículo».
- «Deshacer agrupación».

### 6.3 Listado y fichas

**`Products/Index.tsx`:**

- Filtro «Tipo: todos / individuales / con subartículos».
- Interruptor **«Agrupar subartículos»** (por defecto encendido): la familia sale en una fila con resumen (cuántos subartículos, rango de precios, existencia sumada de la sede) y se despliega para ver cada subartículo.
- La exportación y la acción masiva siguen siendo por subartículo.

**`pages/Products/FamilyShow.tsx`** (`/productos/familia/{family}`):

- Datos comunes.
- **Matriz de existencias** (talla × color) por sede, sumando las bodegas de la sede (regla «Cuentas por sede»), con resaltado de agotados y bajo mínimo.
- Ventas de los últimos 30 días por subartículo.
- Enlaces a la ficha de cada subartículo.

**La ficha del subartículo** es la de siempre (`Products/Show.tsx`), con un encabezado «Subartículo de: Jean skinny… — Talla 8 · Azul» y enlace a la familia.

**Etiquetas de precio:** por subartículo, con su nombre compuesto (ya sale solo) y su código de barras.

### 6.4 Aceptación de la Fase 1

- [ ] Creo «Aceite de girasol» con Presentación 500 ml / 900 ml / 2 L, cada uno con su código de barras, precio y contenido.
- [ ] Creo «Jean skinny tono oscuro para mujer» con Talla 6–16 × Color Azul/Negro, quito «16 · Azul» y escaneo los códigos en la matriz.
- [ ] Un código repetido dentro de la matriz se marca en su fila.
- [ ] Convierto un artículo individual con ventas en familia y conserva kardex, existencias y ventas.
- [ ] Agrupo dos aceites individuales existentes en una familia.
- [ ] Cambio el IVA común y todos los subartículos lo toman; una venta de ayer conserva el suyo.
- [ ] Quito una talla con ventas: queda desactivada, no borrada.

---

## 7. Fase 2 — Caja, promociones, compras e informes

### 7.1 Caja (sin cambios de comportamiento)

- `Pos\SaleController::productPayload` y la búsqueda no cambian: cada subartículo es un artículo, con su nombre compuesto, su precio (`PromotionService`), su código y su existencia.
- **Mejora visual mínima:** en la tarjeta y el tiquete de la caja, `variant_label` como distintivo («Talla 8 · Azul»), para leerlo rápido.
  - Buscar por el nombre del principal trae **todos** sus subartículos juntos, ordenados por `variant_sort`.
  - Agrega `products.variant_label` y `product_family_id` al `select` del payload.
- **El escaneo es exacto por código:** el código de la familia **no** agrega nada. Si se escanea, se ofrece elegir el subartículo en un diálogo con los disponibles.

### 7.2 Promociones (D3)

- **`PromotionTarget::TYPE_FAMILY = 'family'`:** una promoción puede apuntar a un artículo con subartículos y alcanza a **todos** sus subartículos. `reaches()` lo considera.
  - En `pages/Promotions`, el selector de destinos ofrece familias (una entrada) además de artículos y categorías.
- **`nForM()`** (`PromotionService.php:362`): la llave de agrupación pasa de `product_id` a **`product_family_id ?? product_id`**.
  - 1 talla 8 + 2 talla 10 = 3 y se regala la unidad más barata.
  - Un aceite y un agua siguen sin juntarse.
  - Actualiza el comentario del método y la regla de `CLAUDE.md` (§12).
- `descriptors()` y `unitPrices()` no cambian (por subartículo). El aviso de «Lleva 3 paga 2» en la caja y la tienda debe decir **«en cualquier talla»** cuando la promoción apunta a la familia.
- **`POST /pos/cotizar`** sigue siendo la única fuente de los totales con promociones de cantidad (regla vigente).

### 7.3 Compras con matriz (D4)

- **`Purchases/Create.tsx`:** al buscar, las familias aparecen como una entrada. Al elegirla se abre la **matriz** (valor 1 en filas × valor 2 en columnas; con un solo atributo, una columna):
  - cantidad y costo por casilla (costo sugerido: `product_suppliers.last_cost` de ese subartículo con el proveedor de la orden; si no, el costo);
  - existencia de la sede, mínimo y sugerido por casilla (de `PurchaseSuggestionService`, por subartículo).
  - «Aplicar costo a todas».
  - Cada casilla con cantidad > 0 se vuelve **un renglón normal** de la orden (`product_id` = subartículo). **El servidor no conoce la matriz:** recibe renglones como hoy.
- **Ficha de la orden y recepción:** los renglones de una misma familia se agrupan visualmente con su matriz. En la recepción se escribe lo que llegó por casilla, con lote y vencimiento por casilla si el subartículo los controla. Por debajo es el `receive` de siempre, por renglón.
- **Separar por proveedor** (`PurchaseOrderSplitter`) y **devoluciones a proveedor:** por renglón, sin cambios.

### 7.4 Informes y panel

- **`ReportService`** (más vendidos, ventas por artículo): opción **«Agrupar subartículos»**, por defecto encendida, que suma por `COALESCE(products.product_family_id, …)` y muestra el nombre del principal. Apagada, cada subartículo por separado.
  - **Plata no cambia.**
  - Respeta `SaleItem::COUNTS_AS_SOLD_SQL` (componentes de servicio).
- **Inventario** (`InventoryController`, `DashboardService`): las métricas de referencias siguen contando **subartículos** (cada uno es una referencia que se compra y se agota). Agrega el filtro «Artículo principal» en existencias. La regla «agrupar por `product_id` y sumar bodegas» no cambia.
- **Servicios:** el selector de componentes muestra el nombre compuesto; nada más cambia.

### 7.5 Aceptación de la Fase 2

- [ ] En caja escaneo la talla 8 azul y sale «Jean skinny… · Talla 8 · Azul» a su precio. El inventario baja solo esa.
- [ ] Una promoción 3×2 sobre el jean regala la más barata de 1 talla 8 + 2 talla 10, en caja (vía `cotizar`) y en la tienda.
- [ ] Hago una orden al proveedor con la matriz: 5 de cada talla en azul y 3 en negro. Se crean los renglones correctos; la recibo por la matriz con un faltante y queda en `partial`.
- [ ] «Más vendidos» agrupa las tallas del jean en una línea y, al apagar la opción, las separa.

---

## 8. Fase 3 — Tienda web

### 8.1 Una ficha por familia en los listados

- **`StorefrontProducts::query()`** (hoy: artículos activos, visibles, con precio, con o sin existencia) **excluye los subartículos** y agrega **una fila representante por familia**.
- **Forma recomendada**, que conserva la paginación, el orden y los filtros de `StorefrontListing`: incluir `products.product_family_id IS NULL` **o** el subartículo representante, con una subconsulta correlacionada:
  - el primero **vendible** de la familia (activo, visible, precio > 0);
  - con existencia en la bodega de la tienda si la hay;
  - el de menor precio en línea.

  Luego `present()` reemplaza el representante por la ficha de la familia. Si encuentras una forma más limpia con el mismo resultado (paginación y orden correctos), explícala antes.
- **Ficha de la familia** (el mismo `ProductCard`, con `kind: 'family'`):
  - Nombre del principal; foto (la del representante o la común).
  - **«Desde $ X»** cuando los precios difieren (el menor precio **vigente** de `PromotionService` canal en línea entre los subartículos vendibles; si todos cuestan igual, el precio sin «desde»).
  - Etiqueta de oferta si alguno está en promoción («Hasta -20%»).
  - Resumen de opciones («Tallas 6–16 · 2 colores» / «3 presentaciones»); muestras de color si el atributo es `swatches`.
  - «Agotado» solo si **todos** lo están.
  - El precio por unidad **solo** en la ficha del subartículo elegido, no en la tarjeta de la familia: el de 500 ml y el de 2 L no tienen el mismo.
- **Botón de la tarjeta:** en vez de «Agregar», **«Elegir talla»** (el nombre del primer atributo; «Elegir» con 2). Abre una hoja rápida (`Sheet`, celular) o ventana (`StoreDialog`, computador) con los selectores y el contador. Con un solo subartículo vendible, agrega directo.
- **Clave:** `key: 'f:{id}'`; el carrito sigue siendo por subartículo (`p:{id}`).
- **Filtros de categoría** (`category_page.filters`): opción nueva **`attributes`**, con facetas por atributo en la categoría (ej. «Talla: 8, 10, 12…») que filtran familias con algún subartículo vendible con ese valor. Se configura en el Editor de la tienda como los demás filtros. **El precio para ordenar y filtrar es el del representante:** documéntalo.

### 8.2 Página del artículo

- **Ruta nueva:** `GET /tienda/articulo/{familySlug}` (`storefront.family`), declarada sin chocar con `Company::RESERVED_SLUGS` ni con `producto/{product}`.
- `?variante={id}` preselecciona un subartículo; es el enlace compartible.
- **`GET /tienda/producto/{id}` de un subartículo redirige** (301) a la página de su familia con `?variante=`.
- **Contenido**, como la imagen de referencia:
  - Galería: cambia al elegir un color si hay fotos marcadas con ese valor; si no, las del subartículo; si no, las comunes.
  - Nombre del principal y precio del subartículo elegido (vigente; tachado si hay promoción).
  - Precio por unidad y contenido del elegido.
  - Por atributo, su selector según `display`:
    - `buttons`: chips como la imagen.
    - `swatches`: círculos de color o foto con nombre accesible.
    - `select`: lista.
  - **Valores sin existencia, tachados y no elegibles** (el «16» de la imagen), con `aria-disabled` y texto «Agotado» para lectores de pantalla. Con 2 atributos, lo tachado depende de lo ya elegido en el otro (talla 16 disponible en negro, no en azul).
  - Aviso de existencias del elegido (`StockNote`, regla `card.stock_display`).
  - Contador y agregar al carrito con **el `product_id` del subartículo** (endpoint `PUT /tienda/carrito/cantidad`, sin cambios).
  - Descripción: la del subartículo, o la común.
  - Relacionados, como hoy.
- **Todo el cálculo de disponibilidad y precio llega del servidor** en la carga de la página, por subartículo (`variants: [{id, value_1_id, value_2_id, price, compare_at, unit_price, stock, images}]`). El `.tsx` solo cruza los valores elegidos; no calcula precios.

### 8.3 Carrito, pedidos, checkout, bloques y editor

- **Carrito, checkout, pedido, alistamiento, entrega, seguimiento y devoluciones en línea:** por subartículo, como hoy. La línea muestra nombre del principal + `variant_label` y la foto del subartículo o la común.
- **Bloques de la tienda** (`StorefrontRenderer::products()` y fuentes de `product_grid`):
  - Destacados, ofertas, más vendidos, por categoría y «vuelve a comprar» muestran **familias**, no tallas sueltas. Sin duplicados: si dos subartículos de la misma familia califican, sale una ficha.
  - En la fuente manual, el selector de artículos del editor ofrece la familia como una entrada. Si un bloque viejo guardó el id de un subartículo, se pinta su familia.
- **«Artículos en línea»** (`StorefrontCatalogManagerController`, `Storefront/Manage.tsx`): publicar o destacar la **familia**, y dentro, ocultar subartículos puntuales (`products.is_online_visible`).
- **Buscador y sugerencias de la tienda:** comparan contra `product_families.search_name` y devuelven la familia, no cada talla.

### 8.4 Aceptación de la Fase 3

- [ ] En la tienda el jean sale **una vez**, «Desde $ 229.900», «Tallas 6–16 · 2 colores».
- [ ] En su página elijo Azul y la talla 16 aparece tachada; en Negro, disponible. La galería cambia de color.
- [ ] Agrego talla 10 negro y el carrito, el pedido y el alistamiento muestran ese subartículo.
- [ ] El aceite muestra «Desde $ 6.900»; al elegir 2 L el precio y el precio por unidad cambian.
- [ ] Un enlace viejo a `/tienda/producto/{id de la talla 8}` abre la página del jean con la talla 8 elegida.
- [ ] Filtro la categoría por «Talla: 12» y solo salen los artículos que la tienen disponible.

---

## 9. Fase 4 — Importación y exportación

- **`ProductImport`** (`app/Imports/ProductImport.php`) gana columnas **opcionales**, sin romper los archivos de hoy:

  | Columna | Contenido |
  |---|---|
  | `articulo_principal` | Código de la familia o su nombre exacto |
  | `atributo_1` | «Talla» |
  | `valor_1` | «8» |
  | `atributo_2` | Opcional |
  | `valor_2` | Opcional |

  - Las filas con el mismo `articulo_principal` forman o completan una familia.
  - Atributos y valores que no existan se crean en el catálogo (D2).
  - Lo común (categoría, marca, IVA) sale de la primera fila de la familia. Una fila que contradiga la familia **falla con su motivo** («la categoría no coincide con la de Jean skinny…»).
  - Sin `articulo_principal`, la fila es un artículo individual, como hoy.
- **La carga masiva de plataforma** hereda esto porque recorre `ImportRegistry`: verifícalo.
- **Exportar** (`ProductController@export`) incluye esas columnas, para que exportar → importar dé lo mismo.
- **La importación del catálogo externo** (Open Food Facts, por EAN) sigue creando individuales; se agrupan después con «Agrupar con otros artículos».

---

## 10. Fase 5 — Semillas y pruebas

**Semillas:**

- Atributos por empresa (§5).
- En La Canasta: «Aceite de girasol» (500 ml, 900 ml, 2 L), convirtiendo el que ya existe en el primer subartículo, y «Leche entera» (1 L, 1,1 L, bolsa x6).
- En la segunda empresa de demostración, o una tienda de ropa nueva si la creas: «Jean skinny tono oscuro para mujer» (Talla 6–16 × Azul/Negro, con alguna agotada) y «Camiseta básica» (XS–XL × 3 colores), con fotos marcadas por color.
- Existencias, movimientos y consecutivos **por los servicios de siempre**, nunca a mano.

**Pruebas (`tests/Feature`):**

| Archivo | Qué prueba |
|---|---|
| `ProductAttributeTest` | Valores normalizados sin repetir; orden; no se borra un valor usado; aislamiento por empresa |
| `ProductFamilyCreateTest` | Matriz con 1 y 2 atributos; SKU y códigos únicos (también dentro de la matriz y contra servicios y familias); nombre compuesto; precio 0 visible rechazado; existencia inicial por sede |
| `ProductFamilyUpdateTest` | Propaga categoría/IVA/marca/nombre; `search_name` al día; quitar con movimientos desactiva; sin movimientos elimina; venta pasada conserva su IVA |
| `ProductFamilyConvertGroupTest` | Convertir conserva id, kardex y ventas; agrupar existentes; separar y deshacer |
| `PosVariantTest` | Escaneo del subartículo; búsqueda por el principal trae todos; el código de la familia ofrece elegir |
| `PromotionFamilyTest` | Destino `family` alcanza a todos; N×M junta tallas de la misma familia y no de familias distintas; `cotizar` y la venta coinciden |
| `PurchaseMatrixTest` | La matriz crea renglones por subartículo; costos sugeridos por subartículo y proveedor; recepción por casillas con faltante → `partial` |
| `ReportVariantGroupingTest` | Más vendidos agrupado y separado; plata idéntica |
| `StorefrontFamilyListingTest` | Una ficha por familia; «Desde» con el menor precio vigente; agotada solo si todos; sin duplicados en bloques; paginación y orden correctos; facetas por atributo |
| `StorefrontFamilyPageTest` | Disponibilidad cruzada con 2 atributos; galería por color; redirección de `/producto/{id}` del subartículo; agregar al carrito por subartículo |
| `ProductImportVariantsTest` | Filas agrupadas en familia; atributos nuevos creados; contradicción falla la fila; archivo viejo sin columnas nuevas funciona igual; exportar → importar |
| `ProductAttributesPermissionTest` | Rutas con permiso; reparto por equivalencia |
| `RouteSmokeTest` | `family` y `attribute` en el mapa de modelos; todas las GET nuevas abren |

---

## 11. Lo que queda por fuera (dilo si alguien lo pide)

- **Más de 2 características** (D1) o subartículos de subartículos.
- **Precio por atributo** («las tallas grandes valen $ 10.000 más» como regla): hoy cada subartículo tiene su precio; «Sumar $ X a las de…» en el formulario cubre el caso al crear.
- **Existencia compartida** entre subartículos (un tarro que se vende en dos presentaciones): cada subartículo tiene la suya.

---

## 12. Reglas nuevas para `CLAUDE.md`

Agrégalas en «Trampas conocidas» al terminar:

```markdown
- **Articulo con subarticulos**: el principal vive en `product_families` y no se vende, no tiene existencias ni
  se compra. Cada subarticulo es una fila normal de `products` (`product_family_id`, `variant_value_1_id/2_id`,
  `variant_key`, `variant_label`). Caja, compras, inventario, lotes, servicios, pedidos y devoluciones trabajan
  por subarticulo sin saber de familias. Nunca metas el principal en `products`.
- **Lo comun lo escribe solo `ProductFamilyService`**: categoria, marca, proveedor principal, IVA y el nombre
  compuesto se copian a cada subarticulo porque medio sistema los lee de `products`. Si editas un subarticulo
  por otro camino, esas columnas no se tocan.
- **Un subarticulo con movimientos no se borra**: se desactiva. Convertir o agrupar conserva el `id`, el kardex y
  las ventas.
- **Unico de combinaciones por `variant_key`**, no por las dos columnas de valor: MariaDB deja repetir `NULL`.
- **Lleva N paga M** se cuenta por `product_family_id ?? product_id`: las tallas de un mismo articulo se juntan;
  articulos distintos no. `PromotionTarget::TYPE_FAMILY` alcanza a todos los subarticulos.
- **Tienda**: una ficha por familia (subarticulo representante en `StorefrontProducts::query()`), «Desde» con el
  menor precio vigente de `PromotionService`, agotada solo si todos lo estan. El carrito, el pedido y la venta
  van siempre por subarticulo. `/tienda/producto/{id}` de un subarticulo redirige a su familia.
- **Compras con matriz**: la matriz es solo pantalla; al servidor llegan renglones por subarticulo, como siempre.
- **Codigo de una familia**: entra en `ScanCodeGuard`; escanearlo en caja ofrece elegir el subarticulo, no cobra nada.
```

---

## 13. Entrega

Al cerrar cada fase, reporta:

- Archivos creados y modificados.
- Migraciones, incluida la de reparto de permisos.
- Resultado de `npx tsc --noEmit`, `npm run build`, `php artisan test` y `php artisan migrate:fresh --seed`.
- El recorrido manual de aceptación (con capturas del formulario con matriz y de la página del artículo en celular y computador).
- Lo que quedó pendiente, dicho sin rodeos.
