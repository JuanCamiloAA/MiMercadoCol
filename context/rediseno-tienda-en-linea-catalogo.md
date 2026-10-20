# Rediseño del módulo «Tienda en línea» (catálogo publicado) + regla de publicados sin stock

> Prompt de implementación para ejecutar con Claude Opus sobre el repositorio MiMercadoCol (Laravel 12 + Inertia 2 + React 19 + TS estricto).
> Lee `CLAUDE.md` completo antes de empezar: todas sus reglas aplican (inglés en código, español de Colombia en interfaz, COP entero, `permission:` en cada ruta, nada de arrays literales en `.tsx`, nada de botones sin acción, `rem` y `rounded-chip/control/card/sheet` en vez de `text-[13px]`/`rounded-[10px]`, listados con `Table`).
> Diseño de referencia: lienzo «Tienda en línea · Catálogo publicado», tablero 1 (catálogo), 2 (publicados sin stock) y 3 (sugerencias).

---

## 0. Objetivo

1. **Validación interna (obligatoria):** un artículo publicado (`is_online_visible = true`) **sin disponible** en la bodega que respalda la tienda de la sede **no se le muestra al cliente en ningún lugar** de la tienda. No se despublica: vuelve a mostrarse solo cuando tenga disponible.
2. **Apartado especial «Artículos publicados sin stock»** en el módulo, con contexto para reponer (otras bodegas, compras y traslados en camino) y acciones.
3. **Rediseño funcional del módulo** `storefront.catalog.index`: existencias por sede, estado real en tienda, indicadores, vistas rápidas, filtros nuevos, ventas de caja y sugerencias de qué publicar.

Nada se entrega sin funcionar de punta a punta: cada botón, chip, filtro y enlace del diseño tiene su ruta, controlador, consulta real y prueba.

---

## 1. La regla de visibilidad (parte crítica)

### 1.1 Definición única

```
disponible_tienda(articulo, sede) = product_stocks.quantity − product_stocks.reserved_quantity
                                    en la bodega WarehouseService::onlineSourceFor(sede)
                                    (sin fila en product_stocks = 0)

visible_al_cliente(articulo, sede) =
      products.is_active
  AND products.is_online_visible
  AND precio_tienda > 0                          (regla que ya existe)
  AND ( disponible_tienda > 0 OR products.allow_negative_stock )
  AND (si es subartículo) la familia está activa y publicada
```

- `allow_negative_stock = true` **sigue contando como disponible** (es lo que hace hoy `StorefrontListing::candidates`, `familyCards` y la caja). Ver la duda D1 al final: si se decide lo contrario, cambia solo en el punto 1.2.
- La bodega es **siempre** `onlineSourceFor($store->id)`. Nunca sumar otras bodegas de la sede: el alistador no encuentra en el estante lo que está atrás.

### 1.2 Dónde se aplica: un solo lugar

`App\Services\Storefront\StorefrontProducts`:

1. `query(Store $store, array $catalog = [])`: el filtro de disponible deja de depender de `catalog.show_out_of_stock` y se aplica **siempre**:
   ```php
   ->where(fn (Builder $inner) => $inner
       ->where('products.allow_negative_stock', true)
       ->orWhereRaw('COALESCE(product_stocks.quantity, 0) - COALESCE(product_stocks.reserved_quantity, 0) > 0'))
   ```
   Agrega un comentario corto en español explicando la decisión (la tienda no ofrece lo que no puede entregar).
   Extrae la expresión SQL a una constante pública (`StorefrontProducts::AVAILABLE_SQL`) porque el módulo de gestión la reutiliza (sección 3) y las dos tienen que decir lo mismo.
2. `sellableVariants()`: aplica el mismo filtro. Así `familyCards()` y `familyDetail()` solo reciben presentaciones con disponible.
3. `familyDetail()`: elimina el `if (! ($settings['catalog']['show_out_of_stock'] ?? true))`, que ya no hace falta.
4. Una familia cuyas presentaciones publicadas no tienen disponible **no produce tarjeta** (hoy se pinta «Agotada»). En `familyCards()` y en `StorefrontListing::collapseFamilies()` se descarta el grupo vacío. El `pool` con agotados (`$group->where('in_stock', true)->isNotEmpty() ? ... : $group`) deja de existir.

Como todo lo demás pasa por `query()`, la regla alcanza sin código adicional: listados de categoría, ofertas y búsqueda (`StorefrontListing::candidates`), sugerencias del buscador (`CatalogController::suggestions`), panel del menú (`StorefrontListing::top`), relacionados (`StorefrontListing::related`), destacados (`CatalogController::featured`), bloques de la tienda y la vista previa (`StorefrontRenderer`, líneas que llaman `$this->products->query($store)`). **Verifica con `grep` que no quede ninguna consulta de artículos para el cliente que no pase por `query()` o `sellableVariants()`.** Si aparece una, pásala por ahí.

### 1.3 Ficha directa, carrito y pedido

| Punto | Comportamiento |
|---|---|
| `GET /tienda/producto/{id}` (`CatalogController::show`) | Ya usa `query()`: con la regla devuelve **404** si no hay disponible. Si el artículo es subartículo de una familia con otras presentaciones disponibles, redirigir (301 ya existente) a la familia en vez de 404. |
| `GET /tienda/articulo/{slug}` (`family`, `familyOptions`) | Sin presentaciones disponibles → 404 (ya pasa con `variants === []`). |
| Carrito (`CartService::summary`) | Una línea cuyo artículo ya no es `visible_al_cliente` se devuelve con `unavailable: true` y motivo `out_of_stock`. `Cart.tsx` la pinta como «Se agotó» con botón **Quitar**, no suma al subtotal y no deja seguir al pago mientras exista. |
| `PUT /tienda/carrito/cantidad` (`CartController::quantity`) y `POST` de agregar | Rechazan con 422 y mensaje «Este artículo se agotó» si no hay disponible (respetando `allow_negative_stock`). |
| Checkout (`CheckoutController::store` → `OrderService::create`) | Revalida con la fila bloqueada. Si una línea quedó sin disponible entre el carrito y el pago, vuelve al carrito con el aviso. Revisa que `OrderService::create` ya reserve con validación de disponible; si no, añádela usando `StockService` (`requireAvailable: true`). |
| Servicios publicados | Un servicio cuyo disponible (`StorefrontServiceCatalog::available` / `services->available`) es < 1 se oculta igual que un artículo (ver duda D2). Se aplica en `StorefrontServiceCatalog::query`. |

### 1.4 El ajuste `catalog.show_out_of_stock`

- Queda **sin efecto**. Se quita del formulario de la pestaña Funciones (`FunctionsTab.tsx`) y de su request en `SettingRequests::MAP`.
- La clave se mantiene en `StorefrontSetting::KEYS` y en los defectos para que las versiones publicadas viejas sigan validando; nada la lee.
- `card.stock_display` sigue funcionando para «Pocas unidades», pero ya nunca llega a pintarse «Agotado». Revisa `StorefrontProducts::stock()` y `ProductCard` para que no quede código muerto que lo prometa.
- El filtro del cliente `in_stock` (`StorefrontListing`, `allowedFilters`) pierde sentido: retíralo de las opciones del editor y del listado.
- Actualiza la vista previa honesta (`emptyReason` en `StoreBlocks.tsx`): un estante cuyos artículos están todos sin stock dice «Todos los artículos de este estante están sin existencias».

### 1.5 «Sin stock desde»

Columna nueva `product_stocks.out_of_stock_since` (`dateTime`, nullable; no `timestamp`).

- La mantiene **solo `StockService`** (es el único que toca `product_stocks`). En `move()`, `reserve()` y `releaseReservation()`: después de actualizar la fila, si `quantity − reserved_quantity <= 0` y la columna está en null, se llena con `now()` (America/Bogota); si es > 0, vuelve a null.
- Agrégala a `$fillable` de `ProductStock` (ver la trampa de `CLAUDE.md`).
- Migración con relleno: para las filas que hoy están en ≤ 0, toma la fecha del último `stock_movements` de ese artículo en esa bodega; si no hay, `null` (la UI muestra «—»).

---

## 2. Rutas y permisos

Todas bajo el grupo del panel, con `company.selected`, y **declaradas antes de cualquier ruta con parámetro** del mismo prefijo.

| Método | URI | Controlador | Nombre | Permiso |
|---|---|---|---|---|
| GET | `tienda-en-linea` | `StorefrontCatalogManagerController@index` | `storefront.catalog.index` | `storefront.catalog.manage` |
| POST | `tienda-en-linea` | `@update` (publicar/ocultar/destacar, ya existe) | `storefront.catalog.update` | `storefront.catalog.manage` |
| GET | `tienda-en-linea/sin-stock` | `StorefrontOutOfStockController@index` | `storefront.catalog.out_of_stock` | `storefront.catalog.manage` |
| GET | `tienda-en-linea/sugerencias` | `StorefrontSuggestionController@index` | `storefront.catalog.suggestions` | `storefront.catalog.manage` |
| POST | `tienda-en-linea/sugerencias/descartar` | `StorefrontSuggestionController@dismiss` | `storefront.catalog.suggestions.dismiss` | `storefront.catalog.manage` |
| DELETE | `tienda-en-linea/sugerencias/descartar` | `StorefrontSuggestionController@restore` | `storefront.catalog.suggestions.restore` | `storefront.catalog.manage` |
| GET | `tienda-en-linea/exportar` | `StorefrontCatalogManagerController@export` | `storefront.catalog.export` | `storefront.catalog.manage` |

- **No se crea ningún permiso nuevo**: todo es parte de decidir el surtido, que ya cubre `storefront.catalog.manage`. Así no hace falta la migración de datos que pide `CLAUDE.md` para permisos nuevos.
- Las acciones que llevan a otros módulos (solicitar traslado, crear compra, ver compra, ver traslado, crear promoción, completar ficha, fijar precio) son **enlaces** a pantallas existentes y exigen su propio permiso allá. El servidor manda un objeto `can` (`transfer_requests_create`, `purchases_create`, `purchases_view`, `transfers_view`, `promotions_create`, `products_edit`) calculado con los permisos efectivos (`EffectivePermissionService`). **Si falta el permiso, el botón no se pinta** (no se deshabilita).
- `update` amplía su validación: `field` admite además `is_featured` con `value=false` (ya lo admite) y el front deja de mandar la acción «Quitar destacado» por otro camino.

---

## 3. Backend del módulo

### 3.1 Servicio nuevo: `App\Services\Storefront\StorefrontAssortmentService`

Es el único que calcula el estado de un artículo frente a la tienda **para el panel**. Usa `StorefrontProducts::AVAILABLE_SQL` para no contradecir a la tienda.

Entrada común: `companyId`, `Store $store` (la sede elegida en el selector), filtros.

```php
public function baseQuery(int $companyId, Store $store): Builder   // products + stock de onlineSourceFor + agregados
public function metrics(int $companyId, Store $store): array        // indicadores y conteos de vistas rápidas
public function statusOf(object $row): string                       // ver 3.2
public function categoryCoverage(int $companyId, Store $store): array
public function outOfStock(int $companyId, Store $store, array $filters): LengthAwarePaginator
public function suggestions(int $companyId, Store $store, array $filters): array
```

`baseQuery()` arma, en **una** consulta paginable (sin N+1):

- `products` activos de la empresa (`is_active = true`).
- `LEFT JOIN product_stocks` de la bodega `onlineSourceFor($store->id)` → `stock_quantity`, `reserved_quantity`, `available` (expresión), `out_of_stock_since`.
- Subconsulta `sold_pos_30d`: `SUM(sale_items.quantity)` de ventas `Sale::CHANNEL_POS`, estado `paid` o `credit`, de esa sede, `created_at >= now()-30 días`, filtrando con `SaleItem::COUNTS_AS_SOLD_SQL` y descontando las líneas de devolución (cantidades negativas por `refunded_from_sale_item_id`).
- Subconsulta `sold_online_30d`: igual con `Sale::CHANNEL_ONLINE`.
- `other_warehouses_available`: suma del disponible en las **demás** bodegas activas de la sede (`WarehouseService::forStore`), con el nombre de la que más tiene (`top_other_warehouse`).
- `has_image`: `image_path` / `image_url` no vacíos o existe fila en `product_images`.
- `has_description`: `description` no vacía.
- `store_price`: `CASE WHEN online_price > 0 THEN online_price ELSE price END` (solo para filtrar y ordenar; **el precio que se muestra** sale de `PromotionService::unitPrices(..., CHANNEL_ONLINE)`, igual que hoy, sobre la página ya cortada).
- `margin_pct`: `(store_price base sin IVA − cost) / base`, con la base calculada con `Money::baseFromGross` sobre la página (el filtro de margen usa la misma cuenta en SQL con `tax_rate`).
- `expiring_lot_days`: días al vencimiento del lote más próximo con saldo en esa bodega (`product_lots`), usando la misma lógica de `StockService::expiringLots`.
- `has_active_promotion`: existe promoción vigente para canal en línea que alcance el artículo (preguntar a `PromotionService`; si no hay método por lote de ids, agrégalo ahí, no en el controlador).
- `family_published`: para subartículos, `product_families.is_online_visible`.

Recuerda la trampa de `CLAUDE.md`: como se une **una sola** bodega, hay una fila por artículo. Ningún conteo agrupa filas de varias bodegas.

### 3.2 Estado en tienda (`statusOf`)

Orden de evaluación; el primero que aplique gana:

| Clave | Etiqueta | Condición | Pill |
|---|---|---|---|
| `not_published` | No publicado | `is_online_visible = false` | gris |
| `family_hidden` | Oculto: artículo principal sin publicar | subartículo y familia no publicada | gris |
| `no_price` | Publicado sin precio | precio tienda ≤ 0 | rojo |
| `out_of_stock` | Publicado sin stock | disponible ≤ 0 y sin `allow_negative_stock` | ámbar, con texto «Oculto al cliente» |
| `visible` | Visible | todo lo anterior falso | verde |

Marcas secundarias (se pintan debajo, no cambian el estado): `low_stock` (disponible < `products.min_stock` y > 0) «Bajo mínimo», `expiring` «Lote vence en N días», `incomplete` «Sin foto» / «Sin descripción», `suggested` «Sugerido · se vende en caja», `promo` «Promoción activa», `other_warehouse` «Bodega X de la sede tiene N».

### 3.3 `StorefrontCatalogManagerController@index`: props

```ts
interface ManageProps {
  tab: 'products' | 'families' | 'services';
  stores: { value: number; label: string; warehouse: string }[]; // sedes visibles para el usuario
  store_id: number;                                                // por defecto la sede activa (StoreContext)
  rows: Paginated<CatalogRow>;
  filters: CatalogFilters;
  options: {                                                       // TODO llega del servidor
    categories: { value: number; label: string; depth: number }[]; // árbol aplanado
    brands: { value: string; label: string }[];
    suppliers: { value: number; label: string }[];
    statuses: { value: string; label: string }[];
    sheet: { value: string; label: string }[];                     // completa / sin foto / sin descripción
    sorts: { value: string; label: string }[];
    quick_views: { value: string; label: string; count: number; tone: 'neutral'|'warn'|'accent' }[];
  };
  metrics: {
    visible: number; out_of_stock: number; stock_unpublished: number;
    published: number; active: number; featured: number; featured_out_of_stock: number; incomplete: number;
    out_of_stock_transferable: number;                              // para el aviso ámbar
  };
  suggestions_preview: SuggestionItem[];                            // 3, para la columna lateral
  suggestions_total: number;
  coverage: CategoryCoverage[];                                     // top 5 por activos
  can: Record<string, boolean>;
}

interface CatalogRow {
  id: number; name: string; sku: string; barcode: string | null; brand: string | null;
  category: string | null; family: string | null; variant_label: string | null;
  thumb_url: string | null;                                        // ya resuelto por Uploads::url
  available: number; min_stock: number | null; stock_unit: string;
  sold_pos_30d: number; sold_online_30d: number;
  price: number; online_price: number | null; current_price: number; // current_price = PromotionService
  is_online_visible: boolean; is_featured: boolean;
  status: 'not_published'|'family_hidden'|'no_price'|'out_of_stock'|'visible';
  flags: { key: string; label: string; href?: string }[];
}
```

Para `families` y `services` el mismo contrato, con `variants_total`, `variants_published`, `variants_available` en familias, y `open_price` en servicios (sigue sin publicarse).

### 3.4 Filtros (query string, `useFilters`)

| Clave | Valores | Efecto |
|---|---|---|
| `store` | id de sede | Sede de la que se leen existencias y ventas |
| `view` | `all`, `visible`, `out_of_stock`, `stock_unpublished`, `unpublished`, `low_stock`, `expiring`, `promo`, `incomplete`, `featured`, `new` | Vista rápida (chips). `new` = creados en los últimos 30 días |
| `search` | texto | Nombre (`search_name` con `SearchText::key`), SKU, código de barras (string, nunca `(int)`) y marca |
| `category` | id | Incluye subcategorías (`StorefrontRenderer::withChildren` o equivalente del panel) |
| `brand` | texto | Igualdad exacta |
| `supplier` | id | Por `product_suppliers` (cualquiera de sus proveedores, no solo el principal) |
| `stock` | `all`, `in`, `out`, `low` | Sobre `available` |
| `status` | claves de 3.2 | Sobre el estado calculado (en SQL, con la misma precedencia) |
| `sheet` | `complete`, `no_image`, `no_description` | |
| `price_min`, `price_max` | COP entero | Sobre `store_price` |
| `promo` | `1` | Con promoción activa en línea |
| `expiring_days` | entero (por defecto 7) | Lote con saldo que vence en ≤ N días |
| `no_online_sales` | `1` | `sold_online_30d = 0` y publicado |
| `price_differs` | `1` | `online_price > 0 AND online_price <> price` |
| `margin_below` | entero % (por defecto 15) | |
| `other_warehouse` | `1` | `other_warehouses_available > 0` |
| `sort` | `name`, `sold_pos_desc`, `sold_online_desc`, `available_desc`, `available_asc`, `price_asc`, `price_desc`, `recent`, `out_since` | Por defecto `name` |
| `family` | id | Se conserva el comportamiento actual (subartículos de una familia) |

Los conteos de `quick_views` se calculan con **una** consulta agregada (`SUM(CASE WHEN ...)`) sobre `baseQuery()` con los filtros de búsqueda/categoría/marca/proveedor aplicados, pero sin `view`, para que el número del chip diga cuántos hay si lo eligen.

### 3.5 Apartado «Publicados sin stock»: `StorefrontOutOfStockController@index`

Página `Storefront/OutOfStock.tsx`. Pestañas Artículos / Con subartículos / Servicios (la misma regla en las tres).

Filas = `status = out_of_stock` de la sede elegida. Columnas y origen:

| Columna | Origen |
|---|---|
| Artículo (nombre, SKU, marca «Destacado» si `is_featured`) | `products` |
| Categoría | `categories.name` |
| Disponible tienda | `available` (0 o negativo se pinta 0) y, si `reserved_quantity > 0`, «N en bodega · N apartados» |
| Otras bodegas de la sede | `other_warehouses_available` + nombre de la bodega con más |
| Reposición | La más próxima entre: orden de compra `sent`/`partial` con renglón pendiente (`quantity − received_quantity > 0`) hacia la bodega de la tienda (`purchase_orders.warehouse_id`), con `code` y `expected_at` («llega 16 oct»); o traslado `in_transit` con `to_warehouse_id` = bodega de la tienda («TR-000045 · en camino»). Si no hay: «Ninguna» |
| Sin stock desde | `out_of_stock_since` relativo («hoy», «hace 2 días»); en rojo si > 3 días |
| Vendido en línea 30 d | `sold_online_30d` |
| Acciones | Ver 3.5.1 |

Indicadores: `hidden` (total), `replenishing` (con compra o traslado en camino, y el desglose «N compras y N traslado»), `transferable` (sin reposición en camino y con disponible en otra bodega), `no_replenishment` (ni lo uno ni lo otro). Los tres últimos suman el total.

Filtros: `search`, `category`, `replenishment` (`any`, `purchase`, `transfer`, `transferable`, `none`).

Bloque «Cómo decide la tienda»: texto fijo de interfaz (no son datos), con los tres pasos del diseño.

Bloque informativo de subartículos (solo pestaña Artículos): «Una presentación sin disponible sale del selector de la ficha. La tarjeta del artículo solo se oculta cuando ninguna de sus presentaciones publicadas tiene disponible».

#### 3.5.1 Acciones por fila

| Acción | Cuándo se pinta | Qué hace |
|---|---|---|
| Solicitar traslado | `transferable` y `can.transfer_requests_create` | Enlace a la creación de solicitud de traslado (`TransferRequests`) con `?to_warehouse={tienda}&from_warehouse={bodega con más}&products[]={id}`. **Si esa pantalla no acepta precarga, agrégala**: el formulario lee esos parámetros y los propone; guardar sigue pasando por `StockTransferRequestService` |
| Crear solicitud de traslado (N) (cabecera) | Hay seleccionados transferibles desde la misma bodega | Lo mismo con varios `products[]` |
| Crear compra | `no_replenishment` y `can.purchases_create` | Enlace a nueva orden de compra con `?warehouse={tienda}&products[]={id}`; el proveedor se resalta, no se filtra (regla de compras). Precarga igual que arriba |
| Ver compra / Ver traslado | Hay reposición y `can.purchases_view` / `can.transfers_view` | Enlace a la orden o traslado |
| Despublicar | Siempre | `POST storefront.catalog.update` con `is_online_visible=false` |
| Exportar CSV | Siempre | `storefront.catalog.export?view=out_of_stock&store=…` |

### 3.6 Sugerencias: `StorefrontSuggestionController`

Página `Storefront/Suggestions.tsx`. Candidatos: artículos **activos, no publicados, con disponible > 0** en la bodega de la tienda, **no descartados**. Un artículo puede estar en varios grupos.

| Grupo (`key`) | Criterio | Orden | Acciones |
|---|---|---|---|
| `pos_sellers` «Se venden en caja y no están en línea» | `sold_pos_30d > 0` | `sold_pos_30d desc` | Publicar · Descartar |
| `expiring` «Lote por vencer» | `expiring_lot_days <= 7` | días asc | Publicar y destacar · Promoción (enlace a crear promoción con el artículo, si `can.promotions_create`) |
| `overstock` «Inventario alto y poca rotación» | cobertura = `available / (sold_pos_30d/30)` > 60 días, o `sold_pos_30d = 0` con `available >= 2 × min_stock` | cobertura desc | Publicar · Descartar |
| `incomplete` «Completa la ficha antes de publicar» | sin foto, sin descripción o precio tienda ≤ 0 (de cualquiera de los anteriores o de todos los candidatos) | por ventas | Completar ficha / Fijar precio (enlace a la ficha del artículo, si `can.products_edit`) |

Cada grupo trae sus 3 primeros y el total; «Ver los N» abre el catálogo con la vista equivalente (`view=stock_unpublished&sort=sold_pos_desc`, `view=expiring`, etc.). Ningún umbral queda en el `.tsx`: 7 días, 60 días y el factor 2 viven en `SettingsService::DEFAULTS` como `storefront_catalog.expiring_days`, `storefront_catalog.overstock_days` y `storefront_catalog.overstock_min_factor`.

Botón de cabecera «Publicar los N más vendidos»: publica los ids del grupo `pos_sellers` que el servidor mandó (`POST storefront.catalog.update`, `field=is_online_visible`, `value=true`).

«Publicar y destacar»: una sola petición nueva o dos llamadas a `update`. Preferible: `update` acepta `fields: { is_online_visible: true, is_featured: true }` en una sola transacción. Mantén compatibilidad con el `field`/`value` actual.

**Descartar**: tabla nueva `storefront_suggestion_dismissals`:

| Columna | Tipo |
|---|---|
| id | bigIncrements |
| company_id | foreignId, index |
| store_id | foreignId (el descarte es por sede: lo que no conviene en una puede convenir en otra) |
| product_id | foreignId |
| group | string(32) (`pos_sellers`, `overstock`, …; constantes en el modelo) |
| dismissed_by_user_id | foreignId nullable |
| dismissed_at | dateTime |
| unique | (`store_id`, `product_id`, `group`) |

Modelo `StorefrontSuggestionDismissal` con `$fillable` completo y `CompanyScope`. `dismiss` valida con un `FormRequest` (`DismissSuggestionRequest`: `store_id`, `product_id`, `group` dentro de las constantes). `restore` borra la fila. «Ver descartados (N)» lista los descartes de la sede con el botón **Restaurar**. Un descarte deja de aplicar si el artículo se publica y luego se despublica (borrar los descartes del artículo al publicarlo, dentro de `update`).

`categoryCoverage()`: por categoría raíz (sumando subcategorías), cuatro conteos de artículos activos: `visible`, `out_of_stock`, `stock_unpublished`, `rest`, más `suggestions`. La barra apilada usa esos cuatro valores; el clic abre el catálogo con `category`.

### 3.7 Exportar

`GET tienda-en-linea/exportar` aplica los mismos filtros que la pantalla y escribe CSV (UTF-8 con BOM, `;` como separador para Excel en español) con: SKU, código de barras (string), nombre, marca, categoría, estado en tienda, disponible, mínimo, vendido caja 30 d, vendido en línea 30 d, precio mostrador, precio tienda, precio vigente, publicado, destacado, sin stock desde, reposición. Respuesta en streaming; sin tope de paginación.

---

## 4. Frontend

Archivos:

- `resources/js/Pages/Storefront/Manage.tsx` (reescrito)
- `resources/js/Pages/Storefront/OutOfStock.tsx` (nuevo)
- `resources/js/Pages/Storefront/Suggestions.tsx` (nuevo)
- `resources/js/components/storefront-manage/` para piezas compartidas: `StoreStockSelect`, `StatusPill`, `RowFlags`, `QuickViews`, `CoverageBars`, `SuggestionCard`, `ManageTabs`.

Reglas: componentes existentes (`PageHeader`, `Tabs`, `StatCard`, `Card`, `SearchInput`, `Select`, `Checkbox`, `Button`, `Table`/`THead`/`TBody`/`TR`/`TH`/`TD`, `Pagination`, `EmptyState`), `useFilters`, `money()`/`number()`. Nada de `text-[13px]`, `text-[11.5px]` ni `rounded-[12px]` (el `Manage.tsx` actual los tiene: corrígelos al reescribir). Iconos de `@phosphor-icons/react` importados uno por uno. Pasa `node scripts/codemod-units.mjs --check`.

### 4.1 Catálogo (tablero 1)

De arriba hacia abajo:

1. **Encabezado**: título «Tienda en línea», descripción «Qué ve el cliente, qué está publicado pero no se muestra y qué conviene publicar». A la derecha:
   - Selector «Existencias de»: «{Sede} · {Bodega} (tienda)». Cambia `store` en la URL. Solo si el usuario ve más de una sede; con una sola, se muestra como texto.
   - **Exportar CSV**.
   - **Ver tienda** (enlace a `/tienda/{empresa}/{sede}`).
2. **Pestañas**: Artículos · Con subartículos · Servicios · Sugerencias (con el total en una pill verde). Sugerencias navega a `storefront.catalog.suggestions` conservando `store`.
3. **Seis indicadores** (`StatCard` como `button`; el clic aplica la vista rápida equivalente):
   - Visibles al cliente (número en verde) · «Publicados con existencia»
   - Publicados sin stock (tarjeta en tono ámbar) · «Ocultos al cliente hoy» → navega al apartado
   - Con stock sin publicar · «Oportunidad de venta»
   - Publicados · «de N activos»
   - Destacados · «N sin stock» (solo si > 0)
   - Ficha incompleta · «Sin foto o descripción»
   En móvil pasan a 2 columnas y en tableta a 3.
4. **Aviso ámbar** (solo si `metrics.out_of_stock > 0`): «**N artículos publicados no se le están mostrando al cliente** porque no tienen disponible en la bodega de la tienda. Vuelven solos cuando entre mercancía.» + «M se pueden trasladar desde otra bodega de la sede» si `out_of_stock_transferable > 0`. Botón «Ver publicados sin stock».
5. **Vistas rápidas**: fila de chips con desplazamiento horizontal, cada uno con su conteo; el activo en verde; «Publicados sin stock» siempre en tono ámbar. `role="group"` con `aria-label="Vistas rápidas"`.
6. **Filtros** (`Card`):
   - Fila 1: Buscar (nombre, SKU, código de barras o marca) · Categoría (con sangría por profundidad, «Todas (incluye subcategorías)») · Marca · Proveedor.
   - Fila 2: Existencias en la bodega de la tienda (segmentado Todas / Con stock / Sin stock / Bajo mínimo) · Estado en la tienda · Ficha · Precio tienda desde/hasta · Ordenar por.
   - Fila 3 «Más filtros»: chips conmutables (Con promoción activa, Lote vence en ≤ N días, Sin ventas en línea 30 d, Precio tienda ≠ mostrador, Margen bajo N %, Con stock en otra bodega) y «Limpiar filtros» (solo si hay filtros activos).
   - Los textos de N salen de los ajustes del servidor, no del `.tsx`.
7. **Diseño en dos columnas** (`minmax(0,1fr) 340px`; en < 1100 px la lateral baja):
   - **Barra de selección** (aparece con ≥ 1 seleccionado): «N seleccionados» · Publicar (primario) · Ocultar · Destacar · Quitar destacado · Quitar selección.
   - **Tabla** (`Table`, con conmutador Tabla/Tarjetas). Cabecera: «N artículos · existencias de {bodega}». Columnas: casilla · Artículo (miniatura 40 px o marcador con borde punteado si no tiene foto; nombre; SKU en mono; «Subartículo de X · presentación»; marcas secundarias) · Categoría · Disponible (cifra + unidad; barra relativa al máximo de la página; ámbar con «mínimo N» si está bajo mínimo; rojo si es 0 y está publicado) · Vendido caja 30 d · Precio tienda (vigente; «mostrador $ X» si difiere) · Estado en tienda (pill de 3.2 + subtexto) · Publicado (interruptor accesible `aria-pressed`) · Destacado (estrella).
   - Paginación «1–25 de N».
   - **Columna lateral**:
     - «Sugerencias para publicar» con el total, 3 tarjetas (`suggestions_preview`) con su motivo y acciones (Publicar / Descartar / Publicar y destacar / Crear promoción según el grupo; la de lote por vencer con borde ámbar), y el enlace «Ver las N sugerencias».
     - «Cobertura por categoría»: leyenda (Visible verde, Publicado sin stock ámbar, Con stock sin publicar azul), 5 barras apiladas con «publicados / activos», y cada fila es un enlace que filtra la tabla por esa categoría.
8. Interruptor y estrella usan `router.post(update)` con `preserveScroll`; feedback por el flash existente.

Estados vacíos: sin resultados con filtros («Ningún artículo coincide · Cambia los filtros»); vista `out_of_stock` vacía («Todo lo publicado tiene existencias»); sin sugerencias («No hay artículos con stock por publicar»).

### 4.2 Publicados sin stock (tablero 2)

- Enlace de vuelta «‹ Catálogo en línea» (conserva `store`).
- Título «Artículos publicados sin stock» + pill ámbar con el total. Descripción: «Siguen publicados, pero el cliente no los ve: la tienda no muestra lo que no puede entregar. Cuando entre mercancía a la bodega de la tienda vuelven a aparecer solos, sin tocar nada aquí.»
- Botones: Exportar CSV · «Crear solicitud de traslado (N)» (solo con seleccionados transferibles desde una misma bodega).
- 4 indicadores: Ocultos al cliente (ámbar, «N de ellos está destacado»), Reposición en camino («N compras y N traslado»), Se pueden trasladar, Sin reposición prevista (cifra en rojo).
- «Cómo decide la tienda»: 3 pasos numerados (en móvil, uno bajo otro).
- Filtros: buscar, categoría, reposición.
- Tabla de 3.5 con acciones de 3.5.1. Pie: «N seleccionados · se pueden pedir en una sola solicitud a {bodega}» cuando aplique.
- Nota informativa de subartículos.
- Mismo selector de sede que el catálogo (componente compartido).

### 4.3 Sugerencias (tablero 3)

- Pestañas del módulo con «Sugerencias» activa.
- Título, descripción («Artículos con disponible en la bodega de la tienda que todavía no están en línea, agrupados por la razón para publicarlos. Uno puede estar en más de un grupo.»), «Ver descartados (N)» y «Publicar los N más vendidos».
- Cuadrícula de 2 columnas (1 en < 1000 px) con las 4 tarjetas de grupo de 3.6: título, criterio, conteo, 3 filas (nombre, métricas en mono, acciones) y «Ver los N».
- «Cobertura por categoría» completa: leyenda de 4 colores, y por categoría: nombre (enlace), barra apilada, «N visibles / M» y pill «N sugerencias».

### 4.4 Colores y accesibilidad

- Usa los tokens del tema (`--accent`, `--warn`, `--danger`, `--muted`, `--surface`, `--line`); el diseño está en tema oscuro, pero debe verse bien en claro.
- Los estados se distinguen también por texto («Visible», «Publicado sin stock»), no solo por color.
- Los botones de icono llevan `aria-label` («Publicar X», «Ocultar X», «Destacar», «Quitar de destacados»). Las casillas llevan etiqueta con el nombre del artículo.
- Áreas táctiles ≥ 36 px en el panel (convención actual de `Button`).

---

## 5. Migraciones y semillas

1. `add_out_of_stock_since_to_product_stocks_table`: columna + relleno (1.5).
2. `create_storefront_suggestion_dismissals_table` (3.6).
3. `SettingsService::DEFAULTS`: `storefront_catalog.expiring_days = 7`, `storefront_catalog.overstock_days = 60`, `storefront_catalog.overstock_min_factor = 2`, `storefront_catalog.low_margin_percent = 15`.
4. Semillas: que `FashionCompanySeeder` y la semilla de La Canasta dejen al menos un artículo publicado sin stock, uno con stock en otra bodega, uno con compra en camino y uno sin publicar con ventas en caja, para que las tres pantallas tengan datos al hacer `migrate:fresh --seed`. Si mueves existencias en la semilla, hazlo por `StockService` (y el reloj con `Carbon::setTestNow` como ya hace).

---

## 6. Pruebas (PHPUnit, `tests/Feature`)

Regla de visibilidad:

- Un artículo publicado con disponible 0 en la bodega de la tienda **no aparece** en: categoría, búsqueda, sugerencias del buscador, ofertas, destacados, panel del menú, relacionados, bloques `product_grid` del renderizador y vista previa.
- Con existencia 5 y apartado 5 tampoco aparece; con existencia 6 y apartado 5 sí.
- Con existencia en **otra** bodega de la sede pero 0 en la de la tienda: no aparece.
- `GET /tienda/producto/{id}` de un publicado sin stock → 404; subartículo sin stock con hermanas disponibles → redirige a la familia.
- Familia con todas sus presentaciones en 0 → sin tarjeta y `/tienda/articulo/{slug}` → 404; con una disponible → tarjeta y el selector solo ofrece esa.
- `allow_negative_stock = true` con 0 → aparece (o lo contrario si se resuelve D1).
- Al entrar mercancía (`StockService::move` positivo) vuelve a aparecer **sin** tocar `is_online_visible`.
- `catalog.show_out_of_stock = true` en una versión publicada vieja no hace aparecer agotados.
- Carrito: línea que se agotó → `unavailable: true`; `PUT /tienda/carrito/cantidad` → 422; checkout rechaza y no crea pedido.
- `out_of_stock_since` se llena al llegar a 0 (por venta, por reserva de pedido) y se limpia al reponer.

Módulo:

- `storefront.catalog.index`, `.out_of_stock`, `.suggestions`, `.export` → 403 sin `storefront.catalog.manage`; 200 con él. `RouteSmokeTest` las cubre (agrega lo que el barrido necesite sin aflojar la aserción).
- Los indicadores cuadran: `visible + out_of_stock = published` (entre los que tienen precio); `stock_unpublished + (no publicados sin stock) = unpublished`.
- Con dos bodegas en la sede, cada artículo cuenta una vez (no se duplican filas).
- El selector de sede cambia existencias y ventas; un usuario no puede pedir una sede de otra empresa (403/404).
- Cada filtro de 3.4 filtra lo que dice (una prueba por filtro, con datos mínimos).
- Ventas de caja 30 d excluyen anuladas, devoluciones y componentes de $0; las del día 31 no cuentan.
- Reposición: OC `sent` con renglón pendiente hacia la bodega de la tienda cuenta; OC hacia otra bodega no; traslado `in_transit` hacia la tienda cuenta; `draft` no.
- Sugerencias: no incluye publicados, ni sin disponible, ni descartados; descartar es por sede; restaurar lo devuelve; publicar borra sus descartes.
- `update` con `fields` publica y destaca en una sola transacción; la forma vieja `field`/`value` sigue funcionando.
- Exportar respeta filtros y conserva los ceros a la izquierda del código de barras.
- Los botones de traslado/compra/promoción no llegan en `can` sin el permiso correspondiente.

---

## 7. Documentación

Agrega a `CLAUDE.md`, en «Trampas conocidas», una entrada corta:

> **Publicado no es visible**: la tienda solo muestra lo publicado con disponible (existencia − apartado) en la bodega `onlineSourceFor`, o con `allow_negative_stock`. La regla vive en `StorefrontProducts::query()` / `sellableVariants()` (`AVAILABLE_SQL`) y el panel la lee de ahí (`StorefrontAssortmentService`). No vuelvas a leer `catalog.show_out_of_stock`: quedó sin efecto. Una familia sin presentaciones disponibles no tiene tarjeta. `product_stocks.out_of_stock_since` la mantiene solo `StockService`.

Y en el `README.md`, la sección del módulo de tienda en línea con las tres pantallas.

---

## 8. Antes de dar por terminado

```bash
npx tsc --noEmit
npm run build
node scripts/codemod-units.mjs --check
php artisan test
php artisan migrate:fresh --seed
```

Recorre a mano: catálogo con cada vista rápida, cambio de sede, apartado sin stock (las acciones abren sus pantallas con la precarga), sugerencias (publicar, descartar, restaurar), y en la tienda confirma que el artículo de prueba sin stock no aparece y reaparece al recibir mercancía.

---

## 9. Dudas abiertas (resueltas así mientras no se diga otra cosa)

- **D1 · `allow_negative_stock`**: se trata como disponible (se sigue mostrando aunque esté en 0), como hoy hace la caja. Si se quiere que también se oculte, el cambio es solo en `AVAILABLE_SQL` y en la prueba correspondiente.
- **D2 · Servicios**: un servicio publicado cuyos componentes no alcanzan para 1 unidad se oculta y aparece en el apartado (pestaña Servicios), con la misma lógica.
- **D3 · Familias**: la tarjeta desaparece cuando ninguna presentación tiene disponible (antes se pintaba «Agotada»).
