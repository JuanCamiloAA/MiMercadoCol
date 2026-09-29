# PROMPT — Módulo de Servicios (maestro, caja con artículos editables, devoluciones, informes y tienda web)

> **Para:** Claude Opus, trabajando en `C:\xampp\htdocs\MiMercadoCol` (La Canasta — Laravel 12 + Inertia 2 + React 19 + TS estricto).
> **Fecha:** 2026-09-26.
> **Alcance:** módulo nuevo **Servicios**, por empresa, más los cambios en caja, ventas, devoluciones, inventario, informes, pedidos y tienda en línea para que un servicio se venda igual de bien que un artículo.

---

## 0. Cómo trabajar

1. **Lee primero `CLAUDE.md` y `README.md`.** Sus reglas mandan. Las que más pesan aquí:
   - COP entero en `bigInteger`.
   - `string` + constantes, nunca `enum`.
   - `dateTime`.
   - Un FormRequest por acción.
   - `permission:<nombre>` en toda ruta.
   - `$fillable` completo (o la columna queda en `null` sin avisar).
   - Solo `StockService` toca `product_stocks`, dentro de una transacción con `stock_movements`.
   - `StockService` recibe **bodega**, no sede.
   - El precio de cobro solo lo sabe `PromotionService`.
   - Los totales del POS con promociones de cantidad salen de `POST /pos/cotizar`.
   - Nunca sumes `sale_payments.amount` a secas.
   - «Línea sin artículo» (el domicilio) sigue existiendo.
   - Permiso nuevo = migración de reparto.
   - Íconos Phosphor importados uno por uno.
   - Nada de arrays literales en `.tsx`.
   - Nada de botones sin acción; sin permiso **no se pinta**.
2. **Verifica antes de escribir.** Cada referencia de archivo y línea de este prompt se tomó del código el 2026-09-26. Ábrela y confírmala; si cambió, adapta y dilo.
3. **Por fases (§5 a §8).** Cada fase cierra con:

   ```bash
   npx tsc --noEmit
   npm run build
   php artisan test
   php artisan migrate:fresh --seed
   ```

   Más el recorrido manual de aceptación. Reporta al cerrar cada una.
4. **No rompas lo que funciona:** venta de artículos, promociones, cupón, redondeo, cobro combinado, crédito, tiquetes suspendidos, devoluciones, pedidos, alistamiento, domicilio e informes deben quedar idénticos para quien no use servicios.
5. **Si algo no está decidido aquí, pregunta** antes de inventarlo.

---

## 1. Qué pidió el dueño del producto

- Un módulo **Servicios**, muy parecido a Artículos (crear, editar, ficha, listado, exportar, importar). Cada empresa crea y administra los suyos.
- Un servicio puede ser **solo por valor** o **consumir artículos** de la tienda.
- Los servicios **aparecen en la caja** al cobrar. Si llevan artículos, estos van **sublistados** bajo el servicio en la venta.
- El cajero puede **cambiar esos artículos en la venta sin tocar el maestro**. Ejemplo: el servicio consume dos artículos, el cliente no quiere el segundo o lo quiere en menor cantidad; se quita o se reduce solo en esa venta.
- Los servicios se **publican o se ocultan en la tienda web**, igual que los artículos.

### Decisiones del dueño (cerradas)

| # | Tema | Decisión |
|---|---|---|
| D1 | Cómo se cobra un servicio con artículos | **Configurable por servicio** (`pricing_mode`). **Precio cerrado** (`bundled`): el precio del servicio ya incluye los artículos; quitar o reducir uno no cambia el precio, y si hay que rebajar, el cajero da un descuento con las reglas de siempre. **Servicio + artículos** (`itemized`): el servicio cobra su valor y cada artículo se cobra aparte a su precio de venta; quitar o reducir baja el total. |
| D2 | Qué puede cambiar el cajero | **Por permisos, uno por tipo de cambio:** quitar, bajar cantidad, subir cantidad, agregar un artículo que no estaba. **Por defecto** (plantillas de rol y reparto): quitar, bajar y subir. **Agregar** existe pero no se entrega por defecto, salvo al administrador, que tiene todo por definición. Sin el permiso, el cambio se puede autorizar con el **PIN de supervisor** (mismo flujo que el descuento). |
| D3 | Tienda web | El servicio **se vende tal cual el maestro**. El cliente no ajusta artículos; quien alista el pedido sí registra lo que de verdad usó. |
| D4 | Devolución de un servicio con artículos | **Decide el cajero, artículo por artículo:** si vuelve o no al inventario. Cada artículo del maestro trae la opción marcada por defecto (una ancheta sí vuelve; un corte de carne no). |

### Supuestos (aplícalos; el dueño puede cambiarlos)

| # | Supuesto |
|---|---|
| S1 | **Tabla propia `services`**, no una bandera en `products`. Un servicio no tiene existencias, lotes, compras, traslados, mínimos ni etiquetas. Meterlo en `products` obligaría a filtrarlo en cada informe, conteo, sugerencia de compra y listado de inventario (ver la trampa «Cuentas por sede» de `CLAUDE.md`), y un solo olvido ensucia el inventario. |
| S2 | **Categorías compartidas** con artículos (`categories`), opcional en el servicio. |
| S3 | **Precio variable** (`open_price`): opción por servicio para que el cajero digite el valor (ej. recarga de celular). No se vende en la tienda web. |
| S4 | **Promociones:** en esta versión **no** aplican a la línea del servicio. Sí aplican el cupón de la tienda y el descuento manual del cajero. En modo `itemized`, los artículos se cobran a su precio vigente por canal (`PromotionService`: promociones de **precio** sí) pero **no participan en promociones de cantidad** (N×M, combos): son parte de un servicio, no mercancía tomada del estante. |
| S5 | **Cantidad del servicio > 1:** los artículos se multiplican (maestro × cantidad). Si el cajero cambia la cantidad del servicio después de ajustar artículos, se recalculan desde el maestro, **previo aviso** de que se pierden los ajustes. |
| S6 | Un servicio que consume un artículo **inactivo o eliminado** no se puede cobrar hasta que se corrija el maestro, o el cajero quite ese artículo (si tiene permiso). El panel lo advierte en el listado y en la ficha. |
| S7 | **Anular** una venta (no devolver) regresa **todos** los artículos consumidos al inventario, igual que hoy con los artículos: la venta no existió. |

---

## 2. Cómo está el código hoy (lo que el diseño respeta)

| Pieza | Dónde | Qué importa |
|---|---|---|
| `sale_items` | `2025_01_01_000450_create_sale_items_table.php` | `product_id` **nullable**, `description`, `quantity decimal(12,3)`, `unit_price`, `tax_rate`, `discount`, `line_total`, `unit_cost`. Ya admite líneas sin artículo (domicilio). |
| `SaleService::create()` | `app/Services/SaleService.php:71-294` | Aplica `PromotionService::apply()`; resuelve artículos; la línea sin `product_id` pide `description` y no mueve inventario; reparte el cupón con `spreadDiscount()`; totales con `SaleTotalsCalculator`; redondeo solo POS + todo efectivo; mueve existencias con `StockService::move(TYPE_SALE, warehouse)`. |
| `SaleService::cancel()` / `refund()` / `refundedQuantities()` | `SaleService.php:299-530` | La devolución es una venta negativa. **`refundedQuantities()` agrupa por `product_id.'|'.description`**: con servicios, un artículo que se vende suelto **y** como componente en la misma venta se confundiría. Se corrige en §7. |
| `returnWarehouse()` | `SaleService.php:799` | Devuelve a la bodega del movimiento original. Se conserva para los componentes. |
| POS | `app/Http/Controllers/Pos/SaleController.php` (`index`, `products`, `store`, `quote`, `refund`, `detail`, `productPayload`) y `resources/js/pages/Pos/Sale.tsx` (2.026 líneas; `interface CartLine` en la línea 72) | La búsqueda devuelve artículos con precio de `PromotionService::descriptors()` y existencia de la bodega de caja. `quote` hoy **exige `items.*.product_id`**. |
| Petición de venta | `app/Http/Requests/StorePosSaleRequest.php` | `items.*.product_id` **required**. `approver` sale de `AuthorizationController::approverFor($this, $action)`, con **una sola** acción por venta. |
| PIN de supervisor | `app/Http/Controllers/Pos/AuthorizationController.php` | `ACTIONS = ['discount' => 'pos.sale.discount', 'credit' => 'pos.sale.credit']`, TTL 180 s, guarda en sesión y registra en `AccessLog`. |
| Tiquetes suspendidos | `Pos/HeldSaleController.php`, `held_sales.payload` json | Guarda el `CartLine[]` del navegador. |
| Tiquete impreso | `resources/views/pos/receipt-print.blade.php:118`, `resources/views/pdf/receipt.blade.php:66`, `ReceiptService` | Recorre `$sale->items`. |
| Tienda | `Storefront/CatalogController` (`visibleProducts`), `CartController@store` (`product_id` required), `CartService`, `cart_items` (`product_id` NOT NULL, único `cart_id+product_id`), `StorefrontRenderer.php:219,239` (bloques, destacados) | Todo cuelga de `product_id`. |
| Pedidos | `OrderService::create()` (reserva con `StockService::reserve`), `pick()`, `collect()`/entrega → `SaleService::create()`; `order_items.product_id` NOT NULL `restrictOnDelete`; `Orders/Form.tsx` (pedido desde el panel, `orders.create`) | Todo por artículo. |
| Publicar en línea | `StorefrontCatalogManagerController` (`is_online_visible`, `is_featured`) y `pages/Storefront/Manage.tsx` | Solo artículos. |
| Informes | `app/Services/ReportService.php:38-44` (ingreso y costo desde `sale_items`), `:105-114` (por categoría, `leftJoin products`), `:181-188` (más vendidos, **agrupa por `description`**) | Los componentes a $0 de un servicio cerrado aparecerían como «más vendidos» si no se excluyen. |
| Permisos | `app/Helpers/PermissionHelper.php` (`products` en la línea 142, `pos.sale` en la 55, `presetPermissions` en la 857) | El cajero hoy tiene `pos.sale.view/create/discount/suspend`, entre otros. |
| Importar maestras | `app/Imports/ImportRegistry.php`, `MasterImport` (recibe `companyId`) | Procedimiento de `CLAUDE.md` «Importar una maestra nueva». |
| Menú | `resources/js/lib/navigation.ts`, grupo `catalog` (Artículos, Categorías, Importar artículos) | Servicios va aquí. |

---

## 3. Modelo de datos

### 3.1 `services`

| Columna | Tipo | Nota |
|---|---|---|
| `id` | — | — |
| `company_id` | FK | `BelongsToCompany` + `CompanyScope` |
| `category_id` | FK nullable, `nullOnDelete` | Categorías compartidas (S2) |
| `code` | `string(40)` | Como el SKU. Único por empresa (`unique(company_id, code)`) **y** no puede coincidir con un `sku` ni un `barcode` de artículo de la empresa: el escáner de caja no puede dudar |
| `barcode` | `string(60)` nullable | Único por empresa entre servicios y sin chocar con artículos. **Siempre string** (trampa del EAN) |
| `name` | `string(160)` | — |
| `description` | text nullable | — |
| `image_path`, `thumb_path` | string nullable | Por `ImageService`, como artículos |
| `price` | `bigInteger` | Precio al público **con IVA**. En `itemized` es solo el valor del servicio |
| `online_price` | `bigInteger` nullable | Si es null, `price` |
| `cost` | `bigInteger` default 0 | Costo propio del servicio (mano de obra, insumos no inventariados) |
| `tax_rate` | `decimal(5,2)` | IVA del servicio |
| `pricing_mode` | `string(12)` | `Service::PRICING_BUNDLED` (`bundled`, precio cerrado) o `Service::PRICING_ITEMIZED` (`itemized`, servicio + artículos) |
| `open_price` | bool default false | Precio variable en caja (S3) |
| `is_active` | bool | — |
| `is_online_visible` | bool default false | — |
| `is_featured` | bool default false | — |
| `sort_order` | unsignedSmallInteger | — |
| `deleted_at`, timestamps | — | `softDeletes`, como artículos |

Índices: `(company_id, is_active)`, `(company_id, category_id)`.

### 3.2 `service_components`

| Columna | Tipo | Nota |
|---|---|---|
| `service_id` | FK `cascadeOnDelete` | — |
| `product_id` | FK `restrictOnDelete` | Mismo `company_id` que el servicio |
| `quantity` | `decimal(12,3)` > 0 | Por **una** unidad de servicio. Entero si el artículo no se vende por peso |
| `returnable_by_default` | bool default true | Opción por defecto al devolver (D4) |
| `sort_order` | unsignedSmallInteger | — |
| timestamps | — | — |

`unique(service_id, product_id)`.

### 3.3 Cambios en tablas existentes

**`sale_items`:**

| Columna | Tipo | Nota |
|---|---|---|
| `service_id` | FK nullable a `services`, `nullOnDelete` | En la línea del servicio |
| `parent_sale_item_id` | FK nullable a `sale_items`, `cascadeOnDelete` | En cada línea de componente: apunta a la línea del servicio |
| `master_quantity` | `decimal(12,3)` nullable | En componentes: lo que decía el maestro × cantidad del servicio. `null` si el cajero lo **agregó** |
| `component_change` | `string(10)` nullable | Constantes `SaleItem::CHANGE_REDUCED`, `CHANGE_INCREASED`, `CHANGE_ADDED`. `null` = igual al maestro |
| `returnable_by_default` | bool nullable | Copia del maestro en el momento de la venta |
| `service_snapshot` | json nullable | En la línea del servicio: `{pricing_mode, open_price, master: [{product_id, name, quantity, returnable_by_default}], removed: [{product_id, name, master_quantity}], authorized_by_user_id}`. **La venta no depende de cómo quede el maestro después.** |
| `refunded_from_sale_item_id` | FK nullable a `sale_items` | En las líneas de una devolución: la línea original. Ver §7.1 |
| `restocked` | bool nullable | En líneas de devolución: si volvió al inventario |

Índices: `(sale_id, parent_sale_item_id)` y `service_id`.

**`cart_items`:**

- `product_id` pasa a **nullable**; se agrega `service_id` FK nullable.
- `unique(cart_id, service_id)`.
- El único `cart_id+product_id` se conserva: MariaDB permite varios `NULL`.
- La regla «exactamente uno de los dos» la hace cumplir `CartService`.

**`order_items`:**

- `product_id` pasa a nullable, **conservando** `restrictOnDelete`.
- Se agregan `service_id` (FK nullable), `parent_order_item_id` (FK nullable a `order_items`, `cascadeOnDelete`) y `service_snapshot` json nullable.
- Los componentes de un pedido son filas hijas con su `product_id`, `quantity` (maestro × cantidad), `picked_quantity` y `pick_note` de siempre.

**`held_sales.payload`:** no cambia de esquema. El `CartLine` gana campos (§6.3) y **los tiquetes suspendidos viejos, sin esos campos, deben seguir abriendo** (`kind` por defecto `product`).

### 3.4 Modelos

- **`Service`:** `$fillable` completo, casts (enteros, float, bool). Relaciones `components()` (ordenados) y `category()`. Métodos `isBundled()` e `isItemized()`. Scope `sellable()`: activo y sin componentes inactivos o eliminados.
- **`ServiceComponent`:** `$fillable`, `product()` **con `withTrashed()`**, para poder advertir un componente eliminado.
- **`SaleItem`:** relaciones `service()`, `parent()`, `components()`; constantes `CHANGE_*`; `$fillable` con **todas** las columnas nuevas; casts.
- **`CartItem` y `OrderItem`:** `service()`; `OrderItem` también `parent()` y `components()`.
- **`Product`:** relación `serviceComponents()`, para saber qué servicios lo usan.

---

## 4. Permisos, menú y reparto

### 4.1 `PermissionHelper`

**Módulo nuevo `services`**, espejo de `products`:

```php
'services' => [
    'display' => 'Servicios',
    'icon' => 'Wrench',
    'order' => 6,
    'pages' => [
        'index' => [
            'display' => 'Listado de servicios',
            'route' => 'services.index',
            'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import'],
            'labels' => [
                'view' => 'Ver los servicios',
                'create' => 'Crear servicios',
                'edit' => 'Editar servicios',
                'delete' => 'Eliminar servicios',
                'export' => 'Exportar servicios',
                'import' => 'Importar servicios desde CSV',
            ],
        ],
        'show' => [
            'display' => 'Ficha del servicio',
            'route' => 'services.show',
            'actions' => ['view', 'view_cost', 'edit_price'],
            'labels' => [
                'view' => 'Ver la ficha del servicio',
                'view_cost' => 'Ver costo y margen',
                'edit_price' => 'Cambiar precios',
            ],
        ],
    ],
],
```

**`pos.sale` gana cuatro acciones** (D2):

| Permiso | Etiqueta |
|---|---|
| `pos.sale.service_remove` | «Quitar artículos de un servicio en la venta» |
| `pos.sale.service_reduce` | «Usar menos cantidad de un artículo del servicio» |
| `pos.sale.service_increase` | «Usar más cantidad de un artículo del servicio» |
| `pos.sale.service_add` | «Agregar a un servicio un artículo que no trae» |

### 4.2 Plantillas de rol (`presetPermissions`)

- **`cajero`:** `services.index.view`, `pos.sale.service_remove`, `pos.sale.service_reduce`, `pos.sale.service_increase`. **No** `service_add`.
- **`supervisor`** (`supervisorPermissions()`): todo el módulo `services` y los cuatro de la caja **menos** `pos.sale.service_add`, que va a `$excludedPermissions`. Es el «por defecto opción 1» que pidió el dueño.
- **`admin`:** todo (ya lo da `assignablePermissions()`).
- **`auxiliar_contable`:** `services.index.view`, `services.show.view`, `services.show.view_cost`.
- **`bodega`:** `services.index.view` (alista pedidos con servicios).

### 4.3 Migración de reparto

En una base en uso, agregar al catálogo no se lo da a nadie. Patrón: `2025_01_01_000800_grant_sales_history_permission.php`, pero **por equivalencia** de permisos que el usuario ya tiene:

| Si el usuario tiene… | …recibe |
|---|---|
| `products.index.view` | `services.index.view` |
| `products.index.create` / `edit` / `delete` / `export` / `import` | el equivalente en `services.index.*` |
| `products.show.view` / `view_cost` / `edit_price` | el equivalente en `services.show.*` |
| `pos.sale.create` | `pos.sale.service_remove`, `service_reduce`, `service_increase` |
| rol `admin` o `super_admin` | todo lo anterior + `pos.sale.service_add` |

Los roles (plantillas) reciben lo mismo según §4.2. Al final, `forgetCachedPermissions()`.

### 4.4 PIN de supervisor

- `AuthorizationController::ACTIONS` suma `'service_remove' => 'pos.sale.service_remove'`, `'service_reduce' => …`, `'service_increase' => …` y `'service_add' => …`.
- `approverFor()` hoy admite **una** acción por venta. Una venta puede necesitar descuento **y** agregar un componente, así que:
  - Se generaliza a varias acciones.
  - La venta guarda en `sales.authorized_by_user_id` a quien autorizó (si fueron dos distintos, el de la acción más restrictiva).
  - Y **por línea**, `service_snapshot.authorized_by_user_id`.
- En el `.tsx`: si el cajero no tiene el permiso de un cambio, el control **no se pinta**. En su lugar aparece «Pedir autorización», que abre el diálogo de PIN existente.

### 4.5 Menú (`navigation.ts`)

En el grupo `catalog`, justo después de Artículos:

```ts
{ label: 'Servicios', routeName: 'services.index', permission: 'services.index.view', icon: WrenchIcon, match: ['services.'] },
```

Importa `WrenchIcon` de `@phosphor-icons/react` **suelto**.

---

## 5. Fase 1 — Maestro de servicios

### 5.1 Rutas (`routes/web.php`, junto a las de artículos)

Rutas fijas **antes** de las que llevan `{service}`, o `{service}` se traga `crear`, `exportar` e `importar`.

| Método | URI | Acción | Permiso |
|---|---|---|---|
| GET | `/servicios` | index | `services.index.view` |
| GET | `/servicios/exportar` | export CSV de lo filtrado | `services.index.export` |
| GET | `/servicios/importar` | form (pantalla compartida de importación) | `services.index.import` |
| POST | `/servicios/importar` | store | `services.index.import` |
| GET | `/servicios/articulos` | JSON: buscador de artículos para armar componentes | `services.index.create` o `services.index.edit` |
| GET | `/servicios/crear` | create | `services.index.create` |
| POST | `/servicios` | store | `services.index.create` |
| GET | `/servicios/{service}` | show | `services.show.view` |
| GET | `/servicios/{service}/editar` | edit | `services.index.edit` |
| PUT | `/servicios/{service}` | update | `services.index.edit` |
| POST | `/servicios/{service}/duplicar` | duplicate (copia inactiva, código con sufijo) | `services.index.create` |
| DELETE | `/servicios/{service}` | destroy (soft delete) | `services.index.delete` |

- **Buscador de componentes:** si ya existe un buscador JSON de artículos reutilizable (revisa compras y pedidos), úsalo en vez de crear otro.
- **`ServiceController`**, con `authorizeCompany()` como el resto.
- **FormRequests:** `StoreServiceRequest` y `UpdateServiceRequest`, con `companyId` desde `TenantContext`.
- **`services.index.edit` no basta para cambiar precios:** `price`, `online_price` y `cost` exigen `services.show.edit_price`, igual que artículos. Revisa cómo lo hace `UpdateProductRequest` y repite el patrón.

### 5.2 Validación del maestro

| Campo | Regla |
|---|---|
| `code` | Requerido, `max:40`, único entre servicios de la empresa (ignorando el propio), sin coincidir con ningún `products.sku` ni `products.barcode` de la empresa |
| `barcode` | Ídem, nullable, string |
| `price` | Requerido. `> 0`, salvo `open_price = true` (entonces se ignora y se guarda 0) |
| `pricing_mode` | `bundled` o `itemized`. Sin componentes, se guarda `bundled` y el selector no se muestra |
| `components.*.product_id` | Artículo de la empresa, activo, sin repetir |
| `components.*.quantity` | `> 0`, máximo 3 decimales. Entero si el artículo no es `sold_by_weight` |
| `open_price` | Con `open_price`, no se puede publicar en línea (`is_online_visible` se fuerza a `false` y la opción no se pinta) |

**Regla recíproca:** `StoreProductRequest` y `UpdateProductRequest` ganan la validación de que `sku` y `barcode` no choquen con `services.code` ni `services.barcode`.

**Guarda al borrar un artículo** (`ProductController@destroy`): si algún servicio activo lo usa, no se borra, con el mensaje «Lo usan 3 servicios: Armado de ancheta, …. Quítalo de ellos primero.». Como `products` tiene `softDeletes`, el FK no salta solo: la guarda es explícita.

### 5.3 Pantallas (espejo de `pages/Products`)

**`pages/Services/Index.tsx`**

- Buscador (nombre, código, código de barras).
- Filtros: categoría, estado, en línea, «con artículos / solo valor», modo de precio.
- Columnas:
  - Servicio: miniatura, nombre, código.
  - Categoría.
  - Precio (con `money()`); en `itemized`, «desde $X».
  - Modo.
  - Artículos (cantidad).
  - Costo y margen (con `view_cost`).
  - En línea (interruptor, si puede editar).
  - Activo.
- Distintivo de alerta si un componente está inactivo o eliminado (S6).
- Acciones: ver, editar, duplicar, eliminar. Exportar e importar.

**`pages/Services/Form.tsx`** (crear/editar), con las mismas secciones y componentes de `ui/` que `Products/Form.tsx`:

1. **Datos:** nombre, código, código de barras, categoría, descripción, imagen.
2. **Precio:**
   - Precio al público con IVA, IVA %.
   - Casilla «Precio variable: se digita en caja».
   - Costo propio (con `view_cost`), precio en línea.
   - Con componentes: selector **«Cómo se cobra»** — «Precio cerrado: los artículos van incluidos» / «Servicio + artículos: cada artículo se cobra aparte».
3. **Artículos que consume:** tabla editable.
   - Buscar artículo (nombre, SKU, código de barras); cantidad por servicio con la unidad del artículo; casilla «Vuelve al inventario si se devuelve»; reordenar; quitar.
   - Con `view_cost`: costo unitario y subtotal.
   - En `itemized`: precio de venta vigente del artículo (el que devuelva `PromotionService` para POS).
   - Pie de la tabla:
     - **Costo total** = costo propio + Σ costo de componentes.
     - **Precio final** en `itemized` = precio del servicio + Σ precios de componentes.
     - **Margen**.
   - Vacía = «Servicio solo por valor».
4. **Tienda en línea:** visible, destacado, precio en línea. No se pinta si hay `open_price`.

**`pages/Services/Show.tsx`** (ficha):

- Resumen y precio (por canal).
- Artículos que consume, con existencia actual en la bodega de caja y en la de la tienda de la sede activa.
- Alertas (componente inactivo, sin existencias).
- Últimas ventas del servicio y cuántas veces se vendió con ajustes (`component_change`).
- Margen (con `view_cost`).

### 5.4 Importar y exportar

- **`app/Imports/ServiceImport.php`** extiende `MasterImport` y se suma a `ImportRegistry::IMPORTS['services']`. Procedimiento de `CLAUDE.md`: acción `import` en la página `index` del módulo, y rutas antes de las que llevan parámetro. La carga masiva de plataforma lo hereda sola si recorre `ImportRegistry`: verifícalo.
- **Columnas:** `codigo`, `nombre`, `categoria`, `precio`, `precio_en_linea`, `iva`, `modo_precio` (`cerrado` | `detallado`), `precio_variable` (si/no), `costo`, `activo`, `en_linea`, `articulos`.
  - `articulos` usa el formato `SKU:cantidad:devuelve;SKU:cantidad:devuelve`, donde `devuelve` es `si`/`no` y opcional (por defecto `si`).
  - Un SKU que no existe → la fila falla con su motivo.
- **Exportar** usa las mismas columnas, para que el archivo exportado se pueda volver a importar.

### 5.5 Aceptación de la Fase 1

- [ ] Creo «Armado de ancheta» (precio cerrado, 4 artículos), «Porcionado al vacío» (solo valor) y «Recarga de celular» (precio variable, IVA 0).
- [ ] El código de un servicio no puede ser el SKU de un artículo, ni al revés.
- [ ] Un cajero ve Servicios en el menú pero no el botón Crear.
- [ ] Borrar un artículo que usa un servicio se bloquea con el mensaje.
- [ ] Exporto, borro y reimporto: queda igual.

---

## 6. Fase 2 — Caja

### 6.1 Búsqueda y catálogo de la caja

- **`Pos\SaleController@products`** devuelve también `services` en la misma respuesta. **Una sola búsqueda:** el cajero no elige «artículo o servicio» antes de buscar.
- Cada servicio trae: `id`, `code`, `barcode`, `name`, `thumb`, `price`, `tax_rate`, `pricing_mode`, `open_price`, `category_id` y `components`.
  - Cada componente: `product_id`, `name`, `unit`, `sold_by_weight`, `quantity` (por unidad), `returnable_by_default`, `unit_price` (precio vigente POS por `PromotionService::descriptors()`, solo promociones de **precio**, S4), `tax_rate`, `stock` (bodega de caja, menos reservado), `allow_negative_stock` e `is_active`.
- **`initialProducts`** de `index()` incluye los servicios activos. En la barra de categorías de la caja se suma el filtro **«Servicios»**.
- **Escáner:** un código o barras que coincida exacto con un servicio lo agrega. La unicidad de §5.2 evita ambigüedad.
- Un servicio no vendible (S6) sale en la búsqueda con distintivo «Revisar» y explica por qué al tocarlo.

### 6.2 El tiquete en la pantalla

La línea del servicio lleva nombre, cantidad, precio y total. Debajo, **indentados**, sus artículos:

- Nombre, cantidad con la unidad y existencia disponible (aviso si no alcanza y el artículo no permite negativo, igual que con artículos sueltos).
- En `bundled`, la leyenda «incluido»; en `itemized`, precio unitario y subtotal.
- Controles **según permiso** (D2); sin permiso no se pintan y aparece «Pedir autorización»:
  - `−` / `+` y campo de cantidad: bajar exige `service_reduce`, subir exige `service_increase`.
  - Quitar (`×`): exige `service_remove`.
  - «Agregar artículo al servicio» (buscador de artículos): exige `service_add`.
- Un componente cambiado muestra distintivo «Ajustado». Los quitados se listan tachados con «Restaurar».
- **«Volver a como está en el maestro»** deshace todos los ajustes de esa línea.
- **Cambiar la cantidad del servicio** recalcula los componentes desde el maestro × cantidad. Si había ajustes, antes pide confirmación: «Se perderán los ajustes de los artículos» (S5).
- **Precio variable:** al agregar el servicio se pide el valor; se puede corregir tocando el precio de la línea.
- **Descuento manual** del cajero: sobre la línea del servicio y, en `itemized`, también sobre sus componentes cobrados. Mismas reglas y tope (`pos.allow_discount_percent`) que hoy.
- **Total de la línea:**
  - `bundled` = precio × cantidad − descuento.
  - `itemized` = (precio − descuento) + Σ (precio del componente × cantidad − descuento del componente).
  - **El servidor recalcula todo** (§6.4); el navegador solo muestra.

### 6.3 `CartLine` (`Sale.tsx:72`) y tiquetes suspendidos

```ts
type LineKind = 'product' | 'service';

interface ServiceComponentLine {
    product_id: number;
    name: string;
    unit: string;
    sold_by_weight: boolean;
    master_quantity: number | null; // null = agregado por el cajero
    quantity: number;               // total de la línea (maestro × cantidad del servicio, ajustado)
    unit_price: number;             // 0 en bundled
    tax_rate: number;
    discount: number;
    stock: number;
    allow_negative_stock: boolean;
    returnable_by_default: boolean;
    is_active: boolean;
}

interface CartLine {
    kind: LineKind;           // ausente en tiquetes viejos → 'product'
    product_id?: number;      // si kind = product
    service_id?: number;      // si kind = service
    pricing_mode?: 'bundled' | 'itemized';
    open_price?: boolean;
    components?: ServiceComponentLine[];
    removed_components?: { product_id: number; name: string; master_quantity: number }[];
    // …campos actuales…
}
```

- La **clave de React** de una línea no puede seguir siendo solo `product_id`.
- Un mismo artículo puede estar suelto **y** dentro de un servicio: son líneas distintas y **no se fusionan**.
- `HeldSaleController` guarda y recupera el payload nuevo. Al recuperar uno viejo sin `kind`, se trata como `product`.

### 6.4 Servidor: petición y venta

**`StorePosSaleRequest`:**

- Cada ítem trae **exactamente uno** de `product_id` o `service_id` (`required_without` / `prohibits`).
- `items.*.service_id`: existe en `services` de la empresa, activo.
- `items.*.unit_price`: solo se acepta en servicios con `open_price` (entero ≥ 1). En los demás se ignora lo que mande el navegador, como hoy.
- `items.*.components`: array. `components.*.product_id` artículo de la empresa; `components.*.quantity` numérico `> 0`; `components.*.discount` entero ≥ 0 (solo `itemized`).
- Un componente **quitado** es simplemente uno del maestro que **no viene** en el array.

**`SaleService::create()`:** expande cada línea de servicio. Por cada servicio:

1. Carga el servicio **fresco de la base**, con componentes y artículos (con `withTrashed`).
2. Compara lo que llega contra `maestro × cantidad` y clasifica cada diferencia: quitado, bajado, subido o agregado.
3. Para cada tipo de cambio presente, exige el permiso al usuario **o** un `approver` autorizado para esa acción (§4.4). Si no, `ValidationException`: «Quitar artículos de un servicio necesita autorización».
4. Rechaza componentes inactivos o eliminados que sigan en la línea (S6).
5. Precio del servicio:
   - `open_price` → `unit_price` de la petición.
   - Si no → `ServicePricing::priceFor($service, $channel)` (§8.1). **Nunca** `services.price` a secas.
6. Precio de los componentes:
   - `bundled` → `unit_price = 0`, `line_total = 0`.
   - `itemized` → precio vigente del canal desde `PromotionService`, **sin** promociones de cantidad (S4).
7. Arma las líneas:
   - Línea del servicio: `service_id`, `description = nombre del servicio`, `tax_rate` del servicio, `unit_cost = services.cost`, `service_snapshot`.
   - Una línea hija por componente: `product_id`, `parent_sale_item_id`, `description = nombre del artículo`, `quantity`, `unit_price`, `tax_rate` del artículo, `discount`, `unit_cost = products.cost`, `master_quantity`, `component_change`, `returnable_by_default`.
8. **Inventario:** solo las líneas hijas (y los artículos sueltos, como hoy) llaman `StockService::move(TYPE_SALE, $warehouse)` con `note = "Servicio {servicio} — venta {número}"`. La línea del servicio **no** mueve inventario, como el domicilio. Los lotes (`track_lots`) se consumen como con cualquier venta.

**Promociones:**

- Solo se pasan a `PromotionService::apply()` los artículos sueltos, como hoy.
- En `itemized` los componentes no entran a `apply()` (S4); su precio sale de la misma consulta de precio vigente que usa `descriptors()`.
- El descuento manual se mide **antes** de las promociones (trampa de `CLAUDE.md`) e incluye los descuentos manuales de servicio y componentes.

**Cupón (`spreadDiscount`):** se reparte entre las líneas **cobradas** (artículos sueltos, líneas de servicio y componentes `itemized`). Quedan fuera los componentes `bundled` ($0) y la línea sin artículo del domicilio.

**Totales, redondeo y cobro combinado:** no cambian. `SaleTotalsCalculator` recibe todas las líneas (las de $0 no suman). El redondeo sigue siendo solo POS + todo efectivo.

**`POST /pos/cotizar`:**

- Hoy exige `items.*.product_id`: amplíalo para aceptar líneas de servicio.
- Devuelve el precio del servicio y de cada componente calculados por el servidor, con la misma lógica de los pasos 5 y 6. Así el tiquete muestra lo que se va a cobrar.
- Las promociones de cantidad de los artículos sueltos siguen saliendo de aquí.
- **No calcules en el `.tsx` nada que el servidor no te haya dado.**

### 6.5 Tiquete impreso, historial y detalle

- `receipt-print.blade.php`, `pdf/receipt.blade.php` y `ReceiptService`:
  - Línea del servicio.
  - Debajo, indentados, sus artículos: `· 2 x Harina 500 g`, con «incluido» en `bundled` o su valor en `itemized`.
  - Los ajustados llevan «(ajustado)». Los quitados **no** se imprimen: el tiquete dice lo que se entregó.
  - Respeta el ancho 58/80 mm de Ajustes > Tiquete.
- En el historial de ventas (`Sales/Show`), el detalle de la caja (`pos.sales.detail`) y las ventas recientes: el servicio con sus artículos anidados y el distintivo de ajustes.
- `Sale::load` y `with('items')` deben traer `items.components` o agrupar por `parent_sale_item_id`. Nunca listar los componentes como si fueran artículos sueltos.

### 6.6 Aceptación de la Fase 2

- [ ] Vendo «Armado de ancheta» (cerrado). Quito un artículo y bajo otro. El total no cambia. El inventario baja solo por lo usado. El tiquete muestra los artículos anidados con «(ajustado)». El maestro sigue intacto.
- [ ] Vendo un servicio `itemized`. Quitar un artículo baja el total. El servidor cobra exactamente lo que mostró la caja.
- [ ] Un cajero sin `service_add` no ve «Agregar artículo al servicio», pero puede pedir autorización. Con el PIN de un supervisor que sí lo tiene, la venta pasa y queda quién autorizó.
- [ ] Cambio la cantidad del servicio a 2: pide confirmar y duplica los artículos desde el maestro.
- [ ] Mando a mano una petición que quita un componente sin permiso ni autorización → 422.
- [ ] Suspendo un tiquete con un servicio ajustado y al recuperarlo sigue igual. Un tiquete suspendido de antes de este cambio abre sin errores.
- [ ] Cupón, redondeo en efectivo, cobro combinado y venta a crédito con servicios cuadran al peso.

---

## 7. Fase 3 — Devoluciones, anulaciones, inventario e informes

### 7.1 Devolución de un servicio (D4)

- **Corrección previa:** `refundedQuantities()` deja de agrupar por `product_id|description` y pasa a sumar por **`refunded_from_sale_item_id`**, que cada línea de devolución guarda desde ahora.
  - Para las devoluciones hechas antes de la migración, que no tienen esa columna, se conserva la regla vieja como respaldo, solo para líneas sin servicio.
  - Esto también arregla un caso que hoy ya puede fallar: el mismo artículo dos veces en una venta.
- **Diálogo de devolución** (`pos.sales.refund` y la pantalla que lo use):
  - La línea del servicio se devuelve por cantidad, como hoy.
  - Debajo, sus artículos con **cantidad proporcional** (componente × fracción devuelta, editable hacia abajo) y la casilla **«Vuelve al inventario»**, marcada según `returnable_by_default` de la venta.
  - En `itemized`, cada artículo cobrado también devuelve **su plata** proporcional. En `bundled` solo devuelve inventario (su plata iba en el servicio).
- **Servidor** (`SaleService::refund`):
  - Las líneas de componente de la devolución llevan `parent_sale_item_id` a la línea de servicio de la devolución, `refunded_from_sale_item_id` y `restocked`.
  - Solo las de `restocked = true` llaman `StockService::move(TYPE_RETURN, returnWarehouse(...))`.
  - Los topes de «cuánto queda por devolver» se validan por línea original, también para componentes.
- Un artículo que el cliente no devuelve físicamente (`restocked = false`) no mueve inventario: ya salió en la venta.

### 7.2 Anulación

- `SaleService::cancel()` regresa **todos** los componentes a su bodega de origen (S7), con la nota «Anulación de la venta …».
- La línea del servicio no mueve nada.

### 7.3 Kardex

- Los movimientos de componentes son `TYPE_SALE` / `TYPE_RETURN` normales, con `reference` a la venta y la nota con el nombre del servicio. Así la bodega de origen, `returnWarehouse()` y los informes de salidas siguen funcionando.
- En la vista del kardex, si la línea origen tiene `parent_sale_item_id`, se muestra «Consumo en servicio: {nombre}».

### 7.4 Informes (`ReportService`)

| Informe | Cambio |
|---|---|
| Ingreso y costo (`:38-44`) | Sin cambio de fórmula: suma todas las líneas. El margen del servicio incluye el costo de sus artículos. **Verifícalo con una prueba.** |
| Por categoría (`:105-114`) | La categoría sale de `COALESCE(categoría del artículo, categoría del servicio)` (`leftJoin services`). Los componentes `bundled` ($0) **no** cuentan cantidad ni ingreso. Los `itemized` sí, porque son ingreso real de esa categoría. |
| Más vendidos (`:181-188`) | Excluye los componentes `bundled` (`parent_sale_item_id is not null and line_total = 0`). Los servicios aparecen por su nombre, marcados «Servicio». |
| **Nuevo:** «Consumo en servicios» | Por artículo: cantidad y costo consumidos a través de servicios en el periodo. Por servicio: veces vendido, veces con ajustes, ingreso y margen. |
| Dashboard | Nada obligatorio. Si hay un «más vendidos», aplica la misma exclusión. |

**Barrido obligatorio:** busca `sale_items` en `app/` (`ReportService`, `DashboardService`, `CreditService`, exportaciones, `SalesHistory`, cierre de caja, informe Z). Decide en cada consulta si los componentes de $0 deben contar. Nada que sume **plata** cambia, porque valen $0. Lo que cuenta **unidades** casi siempre debe excluirlos.

### 7.5 Aceptación de la Fase 3

- [ ] Devuelvo la ancheta: marco que 3 artículos vuelven y 1 no. El kardex muestra 3 entradas. La plata devuelta es la del servicio.
- [ ] Devuelvo un servicio `itemized` y la plata incluye los artículos devueltos.
- [ ] Una venta con el mismo artículo suelto y dentro de un servicio se devuelve por partes sin confundir cantidades.
- [ ] Anular la venta devuelve todo el consumo a su bodega.
- [ ] En «Más vendidos» no aparecen los artículos incluidos en servicios cerrados. En «Consumo en servicios» sí.

---

## 8. Fase 4 — Tienda en línea y pedidos

### 8.1 Precio por canal

- **`App\Services\ServicePricing::priceFor(Service, string $channel): int`** es la única fuente del precio de un servicio.
  - `bundled` → `online_price ?? price` en línea, `price` en caja.
  - `itemized` → valor del servicio + Σ (precio vigente del artículo en ese canal según `PromotionService`, sin promociones de cantidad × cantidad del maestro).
- La usan la tienda (catálogo y detalle), el carrito, `OrderService::create`, la caja y `quote`.
- Regla de `CLAUDE.md`: «si una pantalla muestra y otra cobra, las dos le preguntan al mismo servicio».

### 8.2 Publicar

- **`StorefrontCatalogManagerController` y `pages/Storefront/Manage.tsx`** (Artículos en línea):
  - Pestaña o filtro **«Servicios»**, con los mismos interruptores de publicar y destacar y el precio en línea.
  - Con `field` y `id` validados por tipo: la petición debe decir si es artículo o servicio.
- También el interruptor «En línea» en `Services/Index` y en el formulario.
- Un servicio con `open_price` no se puede publicar (§5.2).
- La función de membresía `online_store` (si existe en `MembershipFeatures`) aplica igual.

### 8.3 Catálogo, detalle y bloques

- **`CatalogController`:** junto a `visibleProducts()`, un `visibleServices(Store $store)`.
- Un servicio es **visible y vendible en línea** si:
  - está activo, publicado y sin `open_price`, y
  - todos sus componentes están activos, y
  - cada componente tiene existencia en la **bodega de la tienda** (`WarehouseService::onlineSourceFor`) ≥ cantidad del maestro, o permite negativo.
- Si le falta existencia se muestra «Agotado», como un artículo.
- En el listado y la búsqueda van mezclados con los artículos, con el distintivo «Servicio». En el filtro por categoría, por la categoría del servicio.
- **Detalle** (`storefront.service`, `GET /tienda/servicio/{service}`):
  - Foto, descripción y precio (de `ServicePricing`).
  - **«Incluye:»** con los artículos del maestro y sus cantidades (D3: no se editan).
  - Botón agregar al carrito.
  - Declara la ruta sin chocar con `producto/{product}`.
- **Bloques de la tienda** (`StorefrontRenderer::resolve`, `:219`, `:239`):
  - El bloque de **destacados** incluye servicios con `is_featured`.
  - Los bloques por **categoría** también.
  - Los bloques que eligen artículos **por id** siguen siendo solo de artículos: déjalo documentado en el editor («Este bloque muestra artículos»).

### 8.4 Carrito

- **`CartController@store`:** acepta `service_id` o `product_id` (exactamente uno).
- **`CartService::add()`:** un servicio en el carrito es una línea con `service_id` y cantidad, sin política de sustitución. Los componentes **no** se guardan en el carrito: se toman del maestro al crear el pedido.
- **`Cart.tsx` y `Checkout.tsx`:**
  - Los servicios con su «Incluye».
  - Precio y total del checkout desde `ServicePricing`.
  - El cupón se reparte como en §6.4.

### 8.5 Pedidos

- **`OrderService::create()`:**
  - Por cada servicio crea el `order_item` del servicio (`service_id`, `unit_price` de `ServicePricing` en línea, `service_snapshot`).
  - Crea las **filas hijas** de sus componentes (maestro × cantidad) y **reserva** esas existencias con `StockService::reserve()` en la bodega del pedido (`orders.warehouse_id`, trampa «El pedido guarda su bodega»).
  - Si alguno no alcanza y no permite negativo, el pedido se rechaza con el nombre del artículo y del servicio.
- **Alistamiento** (`pick()`, `Orders/Show.tsx`):
  - El servicio aparece con sus artículos anidados. Se alistan **los artículos** con `picked_quantity` y `pick_note` como cualquier línea: alistar menos es la forma de registrar un ajuste (D3).
  - La línea del servicio no se alista (no tiene existencia) y queda lista cuando sus artículos lo están.
  - La reserva sobrante se libera como hoy.
- **Entrega** (`collect()` → `SaleService::create()`):
  - La venta nace con la línea del servicio y sus componentes **según lo alistado** (`component_change` si difiere del maestro).
  - Precio del servicio el del pedido (`bundled`), o el del pedido ajustado por lo alistado (`itemized`, cada artículo al `unit_price` del pedido).
  - El precio del pedido **no se vuelve a consultar**: el cliente pagará lo que vio al confirmar (trampa «Precio de un pedido»).
  - El canal no es POS: **no se redondea** (trampa «Redondeo»).
- **Anular o rechazar un pedido** libera las reservas de los componentes (`releaseReservations`).
- **Pedido desde el panel** (`orders.create`, `Orders/Form.tsx`): permite agregar servicios igual que en la tienda, tal cual el maestro.
- **Seguimiento del cliente** (`OrderTracking.tsx`, `Orders.tsx`): el servicio con su «Incluye».

### 8.6 Aceptación de la Fase 4

- [ ] Publico «Arreglo de frutas» (`itemized`). Se ve en la tienda con «Incluye» y precio = servicio + frutas al precio en línea vigente.
- [ ] Si un componente se agota en la bodega de la tienda, el servicio sale «Agotado».
- [ ] Compro 2 en la web y el pedido reserva el doble de cada fruta.
- [ ] Alisto una fruta con menos cantidad y, al entregar, la venta tiene el servicio con esa fruta «(ajustado)». En `itemized`, el total baja por lo no entregado.
- [ ] Oculto el servicio desde «Artículos en línea» y desaparece de la tienda.
- [ ] «Recarga de celular» (precio variable) no ofrece la opción de publicar.

---

## 9. Seeders

Tres a cinco servicios para La Canasta, usando **SKUs que existan** en `CatalogSeeder`: revísalo y elige. Por ejemplo:

| Servicio | Tipo |
|---|---|
| **Armado de ancheta** | `bundled`, 4–5 artículos (vino, galletas, chocolates, etc.), todos `returnable_by_default = true` |
| **Porcionado y empaque al vacío** | Solo valor, $3.000, IVA 19 % |
| **Recarga de celular** | `open_price`, IVA 0, sin artículos, no publicable |
| **Arreglo de frutas** | `itemized`, mano de obra $5.000 + 3 frutas, `returnable_by_default = false`, publicado en línea |

Si existe la segunda empresa de demostración, dale un servicio propio para comprobar el aislamiento por empresa.

Asegúrate de que el cajero de demostración reciba los permisos por defecto de §4.2.

---

## 10. Pruebas automáticas (`tests/Feature`)

| Archivo | Qué prueba |
|---|---|
| `ServiceCrudTest` | Crear, editar, duplicar, eliminar; código único y sin chocar con SKU/barcode (en los dos sentidos); precio exige `edit_price`; `open_price` no publicable; aislamiento por empresa (otra empresa → 403/404) |
| `ServiceComponentsTest` | Componentes de la misma empresa, activos, sin repetir; entero si no es por peso; borrar un artículo usado por un servicio se bloquea |
| `ServiceImportTest` | Columna `articulos` con el formato; SKU inexistente falla la fila; exportar → importar da lo mismo |
| `PosServiceSaleTest` | `bundled`: total fijo, inventario solo de lo usado, maestro intacto, `service_snapshot` completo. `itemized`: total = servicio + componentes. Servicio solo por valor sin movimientos. `open_price` exige valor. Cantidad 2 multiplica |
| `PosServicePermissionsTest` | Cada tipo de cambio sin permiso → 422; con permiso pasa; con PIN de supervisor pasa y queda `authorized_by_user_id`; descuento + agregar en la misma venta con dos autorizaciones |
| `PosServicePricingTest` | Componentes `itemized` con promoción de **precio** la toman; con N×M **no** la toman; el cupón no toca los componentes de $0; redondeo solo POS + efectivo; cobro combinado cuadra |
| `PosQuoteServiceTest` | `quote` acepta servicios y devuelve lo mismo que luego cobra `store` |
| `HeldSaleServiceTest` | Suspender y recuperar con servicio ajustado; payload viejo sin `kind` |
| `ServiceRefundTest` | Por artículo vuelve / no vuelve; plata en `bundled` vs `itemized`; `refunded_from_sale_item_id`; mismo artículo suelto y en servicio; tope de lo que queda por devolver |
| `ServiceCancelTest` | Anular devuelve todo el consumo a la bodega de origen |
| `ServiceReportTest` | Margen incluye el costo de componentes; «más vendidos» sin componentes `bundled`; categoría del servicio; «Consumo en servicios» |
| `StorefrontServiceTest` | Visible solo si publicado, activo y con existencia en la bodega de la tienda; precio por `ServicePricing`; carrito con `service_id`; cupón |
| `OrderServiceItemsTest` | Pedido reserva componentes en su bodega; alistar menos → venta con `component_change`; anular libera reservas; entrega no redondea y respeta el precio del pedido |
| `ServicePermissionMigrationTest` | Reparto por equivalencia; cajero sin `service_add`; admin con todo |
| `RouteSmokeTest` | Suma `service` al mapa de modelos de `panelRoutes()`; todas las GET nuevas abren |

---

## 11. Reglas nuevas para `CLAUDE.md`

Agrégalas en «Trampas conocidas» al terminar:

```markdown
- **Servicios no son articulos**: viven en `services`, sin existencias. Lo que consumen esta en
  `service_components` y sale del inventario como lineas hijas de la venta (`parent_sale_item_id`), por
  `StockService`, desde la bodega de la venta. Nunca metas un servicio en `products` ni le crees
  `product_stocks`.
- **La venta no depende del maestro**: la linea del servicio guarda `service_snapshot` (maestro, quitados,
  quien autorizo) y cada componente su `master_quantity` y `component_change`. Cambiar el servicio manana no
  cambia ninguna venta pasada.
- **Ajustar un servicio en caja se autoriza en el servidor**: `SaleService` compara lo que llega contra el
  maestro y exige `pos.sale.service_remove/reduce/increase/add` o un `approver` para esa accion. El `.tsx`
  solo decide si pinta el control.
- **Precio de un servicio**: solo `ServicePricing::priceFor()`, por canal. En `bundled` los componentes
  valen $0; en `itemized` se cobran al precio vigente de `PromotionService` sin promociones de cantidad.
- **Cupon y componentes**: el cupon se reparte entre lineas cobradas; los componentes de $0 y la linea del
  domicilio no reciben.
- **Contar unidades vendidas**: excluye los componentes de $0 (`parent_sale_item_id` no nulo y
  `line_total = 0`), o un servicio cerrado infla los «mas vendidos». Lo que suma plata no cambia.
- **Devoluciones por linea**: `refundedQuantities()` suma por `refunded_from_sale_item_id`. No vuelvas a
  agrupar por `product_id|description`: un articulo suelto y el mismo dentro de un servicio se confunden.
- **Codigo de servicio**: no puede ser el SKU ni el codigo de barras de un articulo, y al reves. El escaner
  de caja no puede tener dos respuestas.
```

---

## 12. Entrega

Al cerrar cada fase, reporta:

- Archivos creados y modificados.
- Migraciones, incluida la de reparto de permisos.
- Resultado de `npx tsc --noEmit`, `npm run build`, `php artisan test` y `php artisan migrate:fresh --seed`.
- El recorrido manual de aceptación que hiciste.
- Lo que quedó pendiente, dicho sin rodeos.
