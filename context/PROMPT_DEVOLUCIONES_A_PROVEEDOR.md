# PROMPT — Devoluciones a proveedor (con orden de compra o sueltas)

> **Para:** Claude Opus, trabajando en `C:\xampp\htdocs\MiMercadoCol` (La Canasta — Laravel 12 + Inertia 2 + React 19 + TS estricto).
> **Fecha:** 2026-09-26.
> **Alcance:** módulo nuevo **Devoluciones a proveedor** dentro de Compras. Botón **Devolver** en la orden de compra recibida. Salida de inventario **sin dejar nunca existencias negativas**. Seguimiento de lo que responde el proveedor.

---

## 0. Cómo trabajar

1. **Lee primero `CLAUDE.md` y `README.md`.** Sus reglas mandan. Las que más pesan aquí:
   - COP entero en `bigInteger`.
   - `string` + constantes, nunca `enum`.
   - `dateTime`.
   - Un FormRequest por acción.
   - `permission:<nombre>` en toda ruta.
   - `$fillable` completo.
   - **Consecutivos solo por `NumberSequenceService`**, nunca `count() + 1`.
   - **Solo `StockService` toca `product_stocks`**, dentro de una transacción que escribe `stock_movements`.
   - **`StockService` recibe bodega, no sede.**
   - **El costo promedio solo lo recalcula una compra.**
   - **Una orden con mercancía adentro no se anula ni se separa.**
   - Permiso nuevo = migración de reparto.
   - Íconos Phosphor uno por uno.
   - Nada de arrays literales en `.tsx`.
   - Sin permiso, el botón **no se pinta**.
2. **Verifica antes de escribir.** Las referencias de archivo y línea de este prompt salen del código del 2026-09-26. Ábrelas y confírmalas; si algo cambió, adapta y dilo.
3. **Por fases (§6 a §8).** Cada fase cierra con:

   ```bash
   npx tsc --noEmit
   npm run build
   php artisan test
   php artisan migrate:fresh --seed
   ```

   Más el recorrido manual de aceptación. Reporta al cerrar cada una.
4. **No rompas lo que funciona.** Crear, enviar, recibir por partes, cerrar, separar y anular órdenes; lotes; costo promedio; plazos y vencimientos de compras; traslados; entradas y salidas manuales; kardex. Todo debe quedar igual para quien no use devoluciones.
5. **Si algo no está decidido aquí, pregunta** antes de inventarlo.

---

## 1. Qué pidió el dueño del producto

- **Devolver mercancía al proveedor**, de dos maneras:
  - **Relacionada con una orden de compra.** En la orden ya recibida aparece el botón **Devolver**, que lleva al módulo de devoluciones con esa orden referenciada y sus artículos listados, con lo recibido. El usuario decide **qué** devuelve y **cuánto**.
  - **Suelta**, sin orden de compra: se elige el proveedor y se agregan los artículos.
- **Validación dura:** nunca se devuelve más de lo que hay. **Un artículo no puede quedar en existencias negativas por una devolución**, aunque el artículo tenga activado «permitir negativo».

### Decisiones del dueño (cerradas)

| # | Tema | Decisión |
|---|---|---|
| D1 | Flujo | **Borrador y despacho**, como los traslados. El borrador no toca el inventario y se puede corregir o anular. **Despachar** (la mercancía sale hacia el proveedor) es lo que descuenta existencias. |
| D2 | Respuesta del proveedor | **Se registra, con estado.** Una devolución despachada queda **«Pendiente de respuesta»** hasta que se registren una o varias respuestas: **nota crédito** (número y valor), **reembolso** en dinero o **reposición** de mercancía. Se ven las pendientes por proveedor y el valor a favor. |
| D3 | Rechazo | Si el proveedor no acepta toda o parte de la mercancía y esta **regresa**, se registra el rechazo por renglón y la mercancía **vuelve a entrar** a la misma bodega, con su movimiento en el kardex y sus lotes. |

### Supuestos (aplícalos; el dueño puede cambiarlos)

| # | Supuesto |
|---|---|
| S1 | **Tipo de kardex propio:** `StockMovement::TYPE_SUPPLIER_RETURN = 'supplier_return'`, etiqueta «Devolución a proveedor». **No** se usa `return`: ese tipo es la devolución de un **cliente** y nace de una venta (lo dice el comentario de `StockMovement::REASONS`). Tampoco `waste`, que los informes leen como avería. El rechazo que vuelve a entrar usa el mismo tipo en positivo, con la nota «Rechazo de DP-0003». |
| S2 | **Disponible = existencia − apartado** (`product_stocks.quantity − reserved_quantity`) en la bodega de la devolución. Lo apartado para pedidos en línea **no** se puede devolver: quitarlo dejaría un pedido sin mercancía. La regla aplica **siempre**, ignore o no `allow_negative_stock`. |
| S3 | **Costo promedio: no se recalcula** (regla de `CLAUDE.md`). El movimiento de kardex sale al costo promedio vigente (`products.cost`) y se congela en el renglón al despachar, como en los traslados. El **valor que se le reclama al proveedor** es otro dato: el costo de la compra en una devolución con orden, el último costo con ese proveedor en una suelta. |
| S4 | **Una bodega por devolución.** Con orden, por defecto aquella donde entró la mercancía de esa orden (sale de los movimientos `TYPE_PURCHASE` que la referencian). Si entró a varias, se elige una y el formulario muestra lo disponible en ella. Mercancía repartida en dos bodegas = dos devoluciones, o un traslado primero. Suelta: bodega de la sede activa, por defecto la de recepción (`WarehouseService::receivingDefaultFor`). |
| S5 | **Lotes:** los artículos con `track_lots` salen de lotes concretos, que viajan en el renglón (`lots` json), como en los traslados. Con orden, por defecto el lote que entró con esa orden; si no, el que vence antes (FEFO). El usuario puede elegir otro lote. |
| S6 | **La devolución no toca lo recibido de la orden.** `purchase_order_items.received_quantity` y lo pendiente por llegar no cambian. Lo devuelto se lleva aparte, en `returned_quantity`, recalculado desde las devoluciones (§3.3). La orden muestra recibido, devuelto y neto. |
| S7 | **Se puede devolver** de órdenes en `partial` y `received` (las que tienen mercancía adentro). No de `draft`, `sent` ni `cancelled`. Una orden con devoluciones sigue sin poder anularse. |
| S8 | **Reposición** = el proveedor manda mercancía nueva a cambio. No se inventa otra puerta de entrada: «Registrar reposición» crea una **orden de compra de reposición** (`source = replacement`, ligada a la devolución) con los artículos y costos devueltos. Esa orden se **recibe por el flujo normal de compras**, con lotes, vencimientos y costo promedio. No genera plazo de pago: ya estaba pagada. |

---

## 2. Cómo está el código hoy (lo que el diseño respeta)

| Pieza | Dónde | Qué importa |
|---|---|---|
| Orden de compra | `purchase_orders` (`2025_01_01_000260`, `001090`, `001110`, `2026_09_26_000180`) y `app/Models/PurchaseOrder.php` | Estados `draft`, `sent`, `partial`, `received`, `cancelled`; `store_id`, `warehouse_id`, `supplier_id`, `payment_term_id`, `due_at`, `source` (`manual`, `suggested`, `mixed`), `parent_order_id`; `syncDueAt()`, `pendingQuantity()` |
| Renglón | `purchase_order_items` y `PurchaseOrderItem.php` | `quantity`, `received_quantity`, `unit_cost` (**con IVA**: el separador usa `Money::baseFromGross`, `PurchaseOrderSplitter.php:112`), `lot_code`, `expires_at`, `supplier_id` |
| Recibir | `PurchaseOrderController@receive` (`:448-558`) | Por remesa y a una bodega elegible: `StockService::move(TYPE_PURCHASE)` + `receiveLot()` + `recalculateAverageCost()` + `rememberCost()` |
| Remesas de la orden | `PurchaseOrderController@show` (`:395-410`) | Se leen de `stock_movements` `TYPE_PURCHASE` con `reference` a la orden. De ahí se sabe **a qué bodega** entró cada cosa (S4) |
| Cerrar / anular | `PurchaseOrderController@close` (`:582`) y `@cancel` (`:599`) | Una recibida no se anula |
| Rutas de compras | `routes/web.php:240-253` | **`compras/{order}` está en la línea 246**: las rutas de devoluciones van **antes**, o `{order}` se traga «devoluciones» |
| Pantalla de la orden | `resources/js/pages/Purchases/Show.tsx` | Acciones con `can(...)`: Anular (`:141`), Cerrar (`:160`), Recibir (`:163`) |
| Inventario | `app/Services/StockService.php` | `move()` bloquea la fila (`lockForUpdate`), pero **solo rechaza el negativo si `! allow_negative_stock`** (`:105`) y **no mira lo apartado**. `takeFromLots()` devuelve de qué lotes salió; `receiveLot()` crea lote; `touchLots: false` cuando los lotes se mueven a mano |
| Patrón a imitar | `app/Services/StockTransferService.php` (`dispatch` `:96-145`, `guardAvailability` `:380-405`), `StockTransfer` (estados, `lots` json), `pages/Transfers/{Index,Form,Show}.tsx` | Borrador → despacho, lotes a mano, costo congelado al despachar |
| Lotes | `product_lots` (`2025_01_01_000255`) | Sin vínculo con la orden: solo `code`, `expires_at`, `received_at`, `warehouse_id` |
| Tipos de kardex | `app/Models/StockMovement.php` (`TYPE_LABELS`, `REASONS`) | Los informes no leen tipos de kardex; el kardex pinta `typeLabel()` (`Inventory/Index.tsx:463`, `Inventory/Movements.tsx:97`) |
| Costos por proveedor | `product_suppliers.last_cost` | Costo sugerido en una devolución suelta |
| Consecutivos | `NumberSequenceService` (`nextPurchaseOrderCode` → `EM-0001`, `nextTransferNumber` → `TR-0001`) | Se suma `nextSupplierReturnNumber` → `DP-0001` |
| Permisos | `PermissionHelper.php:274` (`purchases.index`: `view`, `create`, `receive`, `cancel`), `presetPermissions()` (`:857`): `bodega` recibe `modulePermissions('purchases')` completo | Cuidado: todo lo que se sume al módulo le llega a bodega (§5.2) |
| Proveedores | `pages/Suppliers/Index.tsx` (no hay ficha) | Se suma una columna de valor pendiente a favor |

---

## 3. Modelo de datos

### 3.1 `supplier_returns`

| Columna | Tipo | Nota |
|---|---|---|
| `company_id` | FK | `BelongsToCompany` + `CompanyScope` |
| `store_id` | FK | La sede de la devolución (la de la orden, o la activa si es suelta) |
| `warehouse_id` | FK | De dónde sale (S4); de la misma sede |
| `supplier_id` | FK `restrictOnDelete` | Obligatorio |
| `purchase_order_id` | FK nullable `restrictOnDelete` | `null` = devolución suelta |
| `number` | `string(20)` | `DP-0001` por `NumberSequenceService`; `unique(company_id, number)` |
| `status` | `string(20)` | `SupplierReturn::STATUS_DRAFT`, `STATUS_DISPATCHED`, `STATUS_CLOSED`, `STATUS_CANCELLED` |
| `subtotal`, `tax_total`, `total` | `bigInteger` | Valor reclamado. Base con `Money::baseFromGross` por la tasa de cada artículo |
| `rejected_total` | `bigInteger` default 0 | Valor de lo rechazado que volvió |
| `settled_total` | `bigInteger` default 0 | Suma de respuestas del proveedor |
| `note` | string nullable | — |
| `shipping_reference` | `string(60)` nullable | Transportadora o guía |
| `created_by_user_id`, `dispatched_by_user_id`, `cancelled_by_user_id` | FK nullable | — |
| `dispatched_at`, `closed_at`, `cancelled_at` | `dateTime` nullable | — |
| `cancel_reason` | string nullable | — |
| timestamps | — | — |

Índices: `(company_id, status)`, `(company_id, supplier_id)`, `purchase_order_id`.

**Estados:**

```
draft ──despachar──▶ dispatched ──(respondido + rechazado ≥ total)──▶ closed
  │
  └──anular──▶ cancelled        (solo desde draft: despachada la mercancía ya salió)
```

`dispatched` sin cerrar es lo que la pantalla llama **«Pendiente de respuesta»**. `closed` lo pone solo `SupplierReturn::syncSettlement()` (§4.1), no un update suelto.

### 3.2 `supplier_return_items`

| Columna | Tipo | Nota |
|---|---|---|
| `supplier_return_id` | FK `cascadeOnDelete` | — |
| `product_id` | FK `restrictOnDelete` | — |
| `purchase_order_item_id` | FK nullable | Solo con orden; el renglón de la orden del que sale |
| `quantity` | `decimal(12,3)` > 0 | Lo que se devuelve |
| `rejected_quantity` | `decimal(12,3)` default 0 | Lo que el proveedor rechazó y volvió (D3) |
| `unit_cost` | `bigInteger` | **Valor reclamado por unidad, con IVA.** Con orden: el `unit_cost` del renglón de la orden, sin editar. Suelta: el sugerido (§4.2), editable |
| `tax_rate` | `decimal(5,2)` | Copia de la tasa del artículo al crear |
| `line_total` | `bigInteger` | `round(quantity × unit_cost)` |
| `inventory_unit_cost` | `bigInteger` nullable | Costo promedio **congelado al despachar**; es el que va al kardex (S3) |
| `reason` | `string(20)` | Constantes en `SupplierReturnItem::REASONS` (abajo) |
| `note` | string nullable | Obligatoria si el motivo es `other` |
| `lot_id` | FK nullable a `product_lots` | Lote elegido en el borrador (S5) |
| `lots` | json nullable | Lotes **realmente tomados** al despachar: `[{lot_id, code, expires_at, unit_cost, quantity}]` |
| timestamps | — | — |

`unique(supplier_return_id, product_id)`: el mismo artículo no se repite; si hay dos motivos, se elige el principal y se explica en la nota.

**Motivos** (`SupplierReturnItem::REASONS`, clave → etiqueta):

| Clave | Etiqueta |
|---|---|
| `expired` | Vencido |
| `near_expiry` | Próximo a vencer |
| `damaged` | Averiado o roto |
| `quality` | Defecto de calidad |
| `wrong_item` | No corresponde al pedido |
| `surplus` | Sobrante (llegó de más) |
| `recall` | Retiro del mercado |
| `agreement` | Acuerdo comercial o rotación |
| `other` | Otro (con nota) |

### 3.3 Cambios en tablas existentes

- **`purchase_order_items.returned_quantity`**: `decimal(12,3)` default 0. **No se suma a mano**: lo recalcula `PurchaseOrder::syncReturns()` desde los renglones de devoluciones `dispatched` y `closed` de esa orden, como `quantity − rejected_quantity`. `SupplierReturnService` lo llama al despachar, al rechazar y al anular. Mismo patrón que `StockTransferRequest::syncProgress()` (trampa de `CLAUDE.md`).
- **`purchase_orders.supplier_return_id`**: FK nullable. Solo en órdenes de reposición (S8).
  - `PurchaseOrder::SOURCE_REPLACEMENT = 'replacement'` se suma a `SOURCE_LABELS`.
  - `syncDueAt()` no pone vencimiento a una orden de reposición.
- **`product_lots.purchase_order_item_id`**: FK nullable. `PurchaseOrderController@receive` lo llena **de aquí en adelante** al llamar `receiveLot()`, con un parámetro nuevo opcional. Así una devolución con orden encuentra el lote exacto. Los lotes viejos no lo tienen: para ellos, respaldo por `lot_code` del renglón y luego FEFO.
- **`StockMovement::TYPE_SUPPLIER_RETURN`** en `TYPE_LABELS`. La columna `type` es `string(20)` y el valor mide 15: cabe.

### 3.4 `supplier_return_settlements` (respuestas del proveedor, D2)

| Columna | Tipo | Nota |
|---|---|---|
| `supplier_return_id` | FK `cascadeOnDelete` | — |
| `type` | `string(20)` | `SupplierReturnSettlement::TYPE_CREDIT_NOTE`, `TYPE_REFUND`, `TYPE_REPLACEMENT` |
| `document_number` | `string(40)` nullable | Obligatorio en nota crédito |
| `amount` | `bigInteger` > 0 | — |
| `received_at` | `dateTime` | Fecha del documento o del pago |
| `payment_method` | `string(20)` nullable | Solo en reembolso: efectivo, transferencia, otro |
| `purchase_order_id` | FK nullable | Solo en reposición: la orden de reposición creada |
| `note` | string nullable | — |
| `user_id` | FK | — |
| timestamps | — | — |

### 3.5 Modelos

- **`SupplierReturn`:**
  - `$fillable` completo, casts, constantes y `STATUS_LABELS`.
  - Relaciones: `items`, `settlements`, `supplier`, `purchaseOrder`, `store`, `warehouse`, `replacementOrders` y usuarios.
  - `isDraft()`, `isPendingResponse()`, `pendingTotal(): int` (= `total − rejected_total − settled_total`, mínimo 0) y `syncSettlement()`.
- **`SupplierReturnItem`:** `REASONS`, relaciones y `netQuantity()` (= `quantity − rejected_quantity`).
- **`SupplierReturnSettlement`.**
- **`PurchaseOrder`:** `returns()`, `syncReturns()`, `canReturn(): bool` (estado `partial`/`received` y algún renglón con `returnableQuantity() > 0`).
- **`PurchaseOrderItem`:** `returnableQuantity()` (= `received_quantity − returned_quantity`, mínimo 0).

---

## 4. Servicio y reglas

### 4.1 `App\Services\SupplierReturnService`

Único camino que crea, despacha, rechaza, responde y anula devoluciones. Métodos:

| Método | Qué hace |
|---|---|
| `create(array $data, User $user): SupplierReturn` | Borrador. Numera con `NumberSequenceService::nextSupplierReturnNumber($companyId)`. Calcula valores. **No toca inventario.** |
| `update(SupplierReturn $return, array $data, User $user)` | Solo en borrador. |
| `dispatch(SupplierReturn $return, User $user)` | Ver abajo. |
| `reject(SupplierReturn $return, array $lines, User $user)` | Solo `dispatched`. Por renglón, cantidad rechazada ≤ `quantity − rejected_quantity`. Ver abajo. |
| `settle(SupplierReturn $return, array $data, User $user)` | Solo `dispatched`. `amount` ≤ `pendingTotal()` (si se pasa, 422: «El proveedor solo debe $X por esta devolución»). Crea la respuesta y llama `syncSettlement()`. |
| `createReplacementOrder(SupplierReturn $return, array $lines, User $user): PurchaseOrder` | Ver abajo. |
| `cancel(SupplierReturn $return, User $user, string $reason)` | **Solo borrador.** Despachada no se anula: si vuelve mercancía, es un rechazo. |

**`dispatch()`**, en **una transacción**:

1. **Vuelve a validar límites y existencias bajo bloqueo** (§4.3). Lo que se validó al guardar el borrador puede haber cambiado.
2. Por renglón:
   - Congela `inventory_unit_cost = products.cost`.
   - Toma los lotes a mano:
     - Si hay `lot_id` elegido, de ese lote; si no alcanza, 422 con el lote y lo que tiene.
     - Si no, con orden: los lotes con `purchase_order_item_id` del renglón, luego los de `lot_code` igual, luego FEFO.
     - Suelta: FEFO con `takeFromLots()`.
     - Guarda `lots`.
   - Llama `StockService::move(TYPE_SUPPLIER_RETURN, −quantity, requireAvailable: true, touchLots: false, reference: $return, note: "Devolución DP-0003 a {proveedor}", unitCost: inventory_unit_cost)`.
3. Estado `dispatched`, `dispatched_at` y `dispatched_by_user_id`.
4. `purchaseOrder?->syncReturns()`.

**`reject()`**, en transacción:

- Suma `rejected_quantity`.
- **Vuelve a meter** la mercancía a la **misma bodega**: `move(TYPE_SUPPLIER_RETURN, +cantidad, touchLots: false, note: "Rechazo de DP-0003")`. Recrea los lotes con código y vencimiento de lo que salió (`receiveLot()`, repartiendo la cantidad sobre `lots` en orden inverso).
- Recalcula `rejected_total` y llama `syncReturns()` y `syncSettlement()`.
- **No** recalcula costo promedio: vuelve lo mismo que salió (S3).

**`createReplacementOrder()`** (S8):

- Crea una orden de compra `source = replacement`, `supplier_return_id`, misma sede y proveedor, estado `sent`, con los renglones elegidos (por defecto lo neto devuelto) y el `unit_cost` de la devolución.
- Registra la respuesta `TYPE_REPLACEMENT` por el valor de esos renglones, con `purchase_order_id`.
- Llama `syncSettlement()`.
- Esa orden se **recibe con el botón de siempre**.

**`syncSettlement()`**:

- `settled_total = Σ settlements.amount`.
- Si `rejected_total + settled_total ≥ total` → `closed` + `closed_at`.
- Si baja (por ejemplo, se borra una respuesta mal digitada, si decides permitirlo), vuelve a `dispatched`.

### 4.2 Validaciones al guardar el borrador (FormRequest + servicio)

**Generales:**

- `supplier_id`: proveedor activo de la empresa.
- `warehouse_id`: bodega activa de la sede de la devolución.
- Al menos un renglón con cantidad > 0. Los renglones en 0 se ignoran: «no lo devuelvo».
- `quantity`: > 0, máximo 3 decimales, **entera** si el artículo no es `sold_by_weight`.
- `reason`: en `REASONS`; `note` obligatoria si es `other`.
- `lot_id`: lote del mismo artículo, en la misma bodega y con cantidad > 0.

**Con orden:**

- La orden es de la empresa, está en `partial`/`received` (S7), el proveedor es el de la orden y la sede la de la orden.
- Cada renglón apunta a un `purchase_order_item_id` de **esa** orden.
- **Tope por renglón de la orden:** `quantity ≤ returnableQuantity() − lo que ya tienen otros borradores de esa misma orden`. Mensaje: «De Arroz 500 g se recibieron 120; ya se devolvieron 20 y hay 10 en otro borrador: puedes devolver hasta 90.»
- `unit_cost` se toma de la orden; lo que mande el navegador se ignora.

**Suelta:**

- Artículos activos de la empresa.
- `unit_cost` sugerido: `product_suppliers.last_cost` de ese artículo con ese proveedor; si no hay, `products.cost`. Editable (≥ 0).
- Si el artículo no figura en `product_suppliers` con ese proveedor, se permite, pero se marca «No figura como proveedor de este artículo». Mismo espíritu que `is_off_supplier` en compras: se resalta, no se bloquea.

**Existencias (aviso temprano, el tope duro es al despachar):** cada renglón muestra el disponible de la bodega (S2). Si pide más, el borrador **no se guarda**, con mensaje por renglón.

### 4.3 La validación dura: nunca negativo

- **`StockService::move()`** gana un parámetro `bool $requireAvailable = false`. Si es `true` y la cantidad es negativa, con la fila ya bloqueada (`lockForUpdate`, que `move()` ya hace):

  ```
  disponible = stock.quantity − stock.reserved_quantity
  si |cantidad| > disponible → ValidationException
  ```

  **Sin importar `allow_negative_stock`.** Mensaje: «No hay suficiente Arroz 500 g en Bodega principal para devolver 30: hay 25 y 5 están apartadas para pedidos (disponibles 20).»
- **Por qué dentro del bloqueo:** dos personas que despachan a la vez, o una venta en caja entre el guardado del borrador y el despacho, no pueden dejar el saldo en negativo. El chequeo previo **fuera** de la transacción (como hoy hace `StockTransferService::guardAvailability`, §10) no basta: es solo un aviso.
- **Lotes:** si el artículo controla lotes, la suma tomada de los lotes debe cubrir la cantidad. Si los lotes no alcanzan pero el saldo sí (existencias viejas de antes de controlar lotes), se permite con lo que haya en lotes + el resto sin lote, igual que hoy `takeFromLots()` devuelve `pending`.
- Un **despacho es todo o nada**: si un renglón falla, no sale ninguno.

---

## 5. Permisos y menú

### 5.1 `PermissionHelper`, módulo `purchases`

Página nueva:

```php
'returns' => [
    'display' => 'Devoluciones a proveedor',
    'route' => 'supplier_returns.index',
    'actions' => ['view', 'create', 'dispatch', 'respond', 'cancel'],
    'labels' => [
        'view' => 'Ver devoluciones a proveedor',
        'create' => 'Crear y editar devoluciones en borrador',
        'dispatch' => 'Despachar devoluciones (saca la mercancia del inventario)',
        'respond' => 'Registrar la respuesta del proveedor: nota credito, reembolso, reposicion o rechazo',
        'cancel' => 'Anular devoluciones en borrador',
    ],
],
```

### 5.2 Plantillas de rol

- **`bodega`** hoy recibe `modulePermissions('purchases')` completo, así que le llegarían las cinco. **Quítale `purchases.returns.respond`**: la plata de la respuesta no es de bodega. Se queda con `view`, `create`, `dispatch` y `cancel`.
- **`supervisor`:** todas (por `supervisorPermissions()`).
- **`auxiliar_contable`:** `purchases.returns.view` y `purchases.returns.respond`.
- **`admin`:** todas.

### 5.3 Migración de reparto

Patrón `2025_01_01_000800_grant_sales_history_permission.php`, por equivalencia:

| Si el usuario tiene… | …recibe |
|---|---|
| `purchases.index.receive` | `purchases.returns.view`, `create`, `dispatch` |
| `purchases.index.cancel` | `purchases.returns.cancel` |
| rol `admin`, `super_admin` o `auxiliar_contable` | `purchases.returns.view` y `purchases.returns.respond` (y el admin, todo) |

Los roles (plantillas) según §5.2. Al final, `forgetCachedPermissions()`.

### 5.4 Menú (`navigation.ts`)

En el grupo `purchasing`, después de «Órdenes de compra»:

```ts
{ label: 'Devoluciones a proveedor', routeName: 'supplier_returns.index', permission: 'purchases.returns.view', icon: ArrowUUpLeftIcon, match: ['supplier_returns.'] },
```

`ArrowUUpLeftIcon` importado suelto de `@phosphor-icons/react`.

---

## 6. Fase 1 — Modelo, servicio y reglas de inventario

- Migraciones de §3.
- Modelos de §3.5.
- `NumberSequenceService::nextSupplierReturnNumber()`.
- `StockService::move(requireAvailable)`.
- `SupplierReturnService` completo.
- `PurchaseOrder::syncReturns()` y el vínculo `product_lots.purchase_order_item_id` en `receive`.
- Permisos (§5.1–5.3).

**Todavía sin pantallas: esta fase se prueba con pruebas automáticas (§9).**

**Aceptación:**

- [ ] Despachar más de lo disponible falla **aunque el artículo permita negativo**, y no mueve nada.
- [ ] Lo apartado para un pedido en línea no se puede devolver.
- [ ] Con orden, no se devuelve más que lo recibido menos lo ya devuelto.
- [ ] El rechazo vuelve a meter la mercancía y sus lotes.
- [ ] Una respuesta mayor a lo pendiente falla.
- [ ] Respondido + rechazado = total cierra la devolución.
- [ ] El costo promedio no cambia en ningún paso.

---

## 7. Fase 2 — Pantallas

### 7.1 Rutas (`routes/web.php`)

**Todas antes de `compras/{order}` (línea 246)**, y las fijas antes de las que llevan `{supplierReturn}`. Nombres `supplier_returns.*`.

| Método | URI | Acción | Permiso |
|---|---|---|---|
| GET | `/compras/devoluciones` | index | `purchases.returns.view` |
| GET | `/compras/devoluciones/exportar` | export CSV de lo filtrado | `purchases.returns.view` |
| GET | `/compras/devoluciones/articulos` | JSON: buscador de artículos con disponible en la bodega elegida, lotes y costo sugerido para el proveedor | `purchases.returns.create` |
| GET | `/compras/devoluciones/crear` | create (`?orden={id}` precarga la orden) | `purchases.returns.create` |
| POST | `/compras/devoluciones` | store (y `dispatch_now` si viene y tiene `dispatch`) | `purchases.returns.create` |
| GET | `/compras/devoluciones/{supplierReturn}` | show | `purchases.returns.view` |
| GET | `/compras/devoluciones/{supplierReturn}/editar` | edit (solo borrador) | `purchases.returns.create` |
| PUT | `/compras/devoluciones/{supplierReturn}` | update (solo borrador) | `purchases.returns.create` |
| POST | `/compras/devoluciones/{supplierReturn}/despachar` | dispatch | `purchases.returns.dispatch` |
| POST | `/compras/devoluciones/{supplierReturn}/rechazo` | reject | `purchases.returns.respond` |
| POST | `/compras/devoluciones/{supplierReturn}/respuestas` | settle (nota crédito o reembolso) | `purchases.returns.respond` |
| POST | `/compras/devoluciones/{supplierReturn}/reposicion` | crea la orden de reposición | `purchases.returns.respond` |
| POST | `/compras/devoluciones/{supplierReturn}/anular` | cancel (solo borrador) | `purchases.returns.cancel` |
| GET | `/compras/devoluciones/{supplierReturn}/documento` | PDF para el proveedor o el transportador (dompdf) | `purchases.returns.view` |

**`SupplierReturnController`**, con `authorizeCompany()` como el resto.

**FormRequests:**

- `StoreSupplierReturnRequest` / `UpdateSupplierReturnRequest`
- `RejectSupplierReturnRequest`
- `StoreSupplierReturnSettlementRequest`
- `StoreReplacementOrderRequest`
- `CancelSupplierReturnRequest` (motivo obligatorio)

Agrega `supplierReturn` al mapa de modelos de `RouteSmokeTest::panelRoutes()`.

### 7.2 Botón en la orden de compra (`Purchases/Show.tsx` + `PurchaseOrderController@show`)

- **Botón «Devolver»** junto a Recibir y Cerrar. Visible si `can('purchases.returns.create') && order.can_return` (S7). Lleva a `route('supplier_returns.create', { orden: order.id })`.
- **Columna «Devuelto»** por renglón (`returned_quantity`). El resumen muestra **Recibido $X · Devuelto $Y · Neto $Z**.
- **Sección «Devoluciones de esta orden»**: número (enlace), fecha, estado, unidades y valor. También la orden de reposición si la hubo, y en esa orden, «Reposición de DP-0003».
- `@show` suma a sus props `can_return`, `returned_quantity` por renglón y la lista de devoluciones.

### 7.3 Crear o editar (`pages/SupplierReturns/Form.tsx`)

**Encabezado:**

- **Proveedor:** fijo si viene de una orden; selector si es suelta.
- **Orden de compra:** chip con enlace si viene de una. Desde el listado, «Nueva devolución» empieza **sin orden** y ofrece «Buscar una orden recibida» (del proveedor elegido) para cambiar a modo con orden.
- **Sede:** la de la orden o la activa, en solo lectura.
- **Bodega:** selector. Con orden, por defecto aquella donde entró (S4) y la lista marca «entró aquí».
- Nota y guía del transporte.

**Renglones con orden** (la lista viene cargada con **todos** los renglones de la orden):

| Columna | Contenido |
|---|---|
| Artículo | Nombre y SKU |
| Recibido | `received_quantity` |
| Ya devuelto | `returned_quantity` + borradores |
| Máximo a devolver | min(devolvible, disponible en bodega) |
| Disponible en bodega | Con aviso si hay apartado |
| **A devolver** | Input, empieza en 0 |
| Motivo | Selector |
| Lote | Si controla lotes: selector con código, vence y cantidad en la bodega, preseleccionado según S5 |
| Costo unitario | De la orden, solo lectura |
| Subtotal | — |

- Casilla «Incluir» por renglón que, al marcarla, pone el máximo; y «Devolver todo lo disponible».
- Los renglones en 0 no se guardan.

**Renglones sueltos:**

- Buscador de artículos (nombre, SKU, código de barras, **escáner**) que agrega el renglón con disponible, costo sugerido (editable), motivo y lote.
- Quitar renglón.
- Marca «No figura como proveedor de este artículo» si aplica.

**En vivo, con los datos que dio el servidor** (el `.tsx` no inventa disponibles):

- Error por renglón si «A devolver» supera el máximo.
- Pie con unidades, base, IVA y **total a favor**.

**Acciones:**

- «Guardar borrador».
- «Guardar y despachar», solo si tiene `dispatch`. Antes pide confirmar: «Saldrán 45 unidades de Bodega principal».

### 7.4 Ficha (`pages/SupplierReturns/Show.tsx`)

**Encabezado:**

- Número y estado con distintivo (Borrador · Pendiente de respuesta · Cerrada · Anulada).
- Proveedor, orden (enlace), sede y bodega, guía, quién creó y quién despachó.

**Valor:** total · rechazado · respondido · **pendiente a favor**, con barra de avance.

**Renglones:**

- Artículo, motivo, cantidad, rechazado, neto, costo y subtotal.
- Lotes que salieron (código y vencimiento).

**Respuestas:** tipo, documento, fecha, valor, quién la registró y, en reposición, la orden con su estado de recepción.

**Historial** (línea de tiempo): creada, editada, despachada, respuestas, rechazos, cerrada.

**Acciones según estado y permiso** (sin permiso no se pintan):

- **Borrador:** Editar · Despachar · Anular (pide motivo).
- **Pendiente de respuesta:**
  - «Registrar nota crédito o reembolso»: modal con tipo, número de documento, fecha, valor (por defecto lo pendiente) y medio si es reembolso.
  - «Registrar reposición»: modal con renglones y cantidades, que crea la orden de reposición y lleva a ella.
  - «Registrar rechazo»: modal con cantidad rechazada por renglón y nota; avisa «Esta mercancía volverá a entrar a Bodega principal».
- **Siempre:** Documento PDF.

### 7.5 Listado (`pages/SupplierReturns/Index.tsx`)

- **Tarjetas arriba:** pendientes de respuesta (cantidad y valor), borradores, devuelto este mes (valor).
- **Filtros:** estado, proveedor, sede, rango de fechas, «con orden / suelta», motivo.
- **Columnas:** número, fecha, proveedor, orden, sede/bodega, unidades, valor, pendiente a favor, estado.
- **Exportar CSV** de lo filtrado.
- **Botón «Nueva devolución»** (con `create`).

### 7.6 Documento PDF

- Encabezado de la empresa: nombre de marca de `brand.display_name` y datos legales.
- Proveedor, número, fecha, orden de compra, guía.
- Renglones: artículo, SKU, lote, vence, cantidad, motivo, costo y subtotal.
- Totales (base, IVA, total).
- Espacio de **firma de recibido** del proveedor o transportador.

### 7.7 Otros lugares

- **Proveedores** (`Suppliers/Index.tsx`): columna **«A favor pendiente»** (Σ `pendingTotal()` de sus devoluciones despachadas), con enlace al listado filtrado.
- **Kardex** (`Inventory/Index.tsx`, `Inventory/Movements.tsx`): muestra «Devolución a proveedor» con el número como enlace a la ficha (el `reference` ya lo trae).
- **Ficha del artículo** (`Products/Show.tsx`), si muestra movimientos: igual.

### 7.8 Aceptación de la Fase 2

- [ ] En una orden recibida veo «Devolver». Me lleva al formulario con la orden, el proveedor y la bodega donde entró, y con todos sus artículos, recibido y máximo. Pongo 5 de uno y 0 del resto, y se guarda un borrador con un solo renglón.
- [ ] Intento devolver más que el disponible: el formulario lo marca y el servidor lo rechaza.
- [ ] Despacho: el kardex muestra la salida «Devolución a proveedor DP-0001». La orden muestra «Devuelto 5» y el neto.
- [ ] Hago una devolución suelta escaneando dos artículos; el costo sugerido es el último con ese proveedor.
- [ ] Registro una nota crédito por la mitad (queda pendiente la otra mitad) y un rechazo por el resto: la devolución se cierra y la mercancía rechazada vuelve a la bodega.
- [ ] Registro una reposición: se crea la orden de reposición, la recibo con el botón normal y entra con lote y vencimiento, sin plazo de pago.
- [ ] Un usuario de bodega despacha pero no ve «Registrar nota crédito».

---

## 8. Fase 3 — Pulido, semillas y reglas

- **`DemoOperationSeeder`** (o un seeder nuevo que lo siga), sobre órdenes `received` que ya siembra (`:149-150`):
  - Una devolución con orden, despachada, con nota crédito parcial: queda pendiente de respuesta.
  - Una devolución suelta en borrador.
  - Movimientos y consecutivos por los servicios, nunca a mano.
- **Informes:** nada obligatorio. Si hay un informe de compras o de proveedores, suma «Devuelto» y «Neto» con la misma fuente (`supplier_returns` despachadas y cerradas). No toques los informes de ventas: sus «devoluciones» son de clientes.
- **Reglas de `CLAUDE.md`** (§11).

---

## 9. Pruebas automáticas (`tests/Feature`)

| Archivo | Qué prueba |
|---|---|
| `SupplierReturnDraftTest` | Borrador con orden: solo renglones de esa orden; tope recibido − devuelto − otros borradores; costo de la orden aunque el navegador mande otro. Suelta: costo sugerido del proveedor; entero si no es por peso; motivo `other` exige nota; aislamiento por empresa y sede |
| `SupplierReturnStockGuardTest` | Despachar más de lo disponible falla con `allow_negative_stock = true`; lo apartado no cuenta como disponible; todo o nada (un renglón corto no deja salir los demás); dos despachos concurrentes no dejan negativo (secuencial con la fila bloqueada) |
| `SupplierReturnDispatchTest` | Kardex `supplier_return` con `reference`; `inventory_unit_cost` congelado; costo promedio intacto; `returned_quantity` de la orden al día; lotes: primero los de la orden (`purchase_order_item_id`), luego `lot_code`, luego FEFO; lote elegido sin cantidad → 422 |
| `SupplierReturnRejectTest` | Rechazo parcial vuelve a entrar a la misma bodega con sus lotes; no pasa de lo neto; recalcula `returned_quantity` y `rejected_total`; puede cerrar la devolución |
| `SupplierReturnSettlementTest` | Nota crédito exige número; no supera lo pendiente; parcial deja `dispatched`; completo → `closed`; reposición crea la orden `replacement` sin `due_at` y registra la respuesta |
| `SupplierReturnCancelTest` | Solo borrador; despachada → 422; anular no mueve inventario |
| `PurchaseOrderReturnFlowTest` | `can_return` solo en `partial`/`received`; la orden recibida con devoluciones sigue sin poder anularse; `received_quantity` y lo pendiente no cambian |
| `SupplierReturnPermissionsTest` | Cada ruta con su permiso; bodega sin `respond`; reparto por equivalencia en la migración |
| `RouteSmokeTest` | Las GET nuevas abren (`supplierReturn` en el mapa) |

---

## 10. Hallazgo colateral (no es parte del alcance, avísalo)

`StockTransferService::guardAvailability()` (`:380`) mide el disponible **antes** de la transacción y sin bloqueo. Luego `dispatch()` llama `move()`, que solo rechaza el negativo si el artículo no lo permite, y sin mirar lo apartado. Con un artículo que permite negativo, o con apartados, un traslado puede dejar la bodega de origen en negativo o comerse lo apartado para un pedido.

Con `requireAvailable` de §4.3 el arreglo es de una línea en `StockTransferService::dispatch()`. **No lo cambies sin preguntar**: cambia el comportamiento de los traslados.

---

## 11. Reglas nuevas para `CLAUDE.md`

Agrégalas en «Trampas conocidas» al terminar:

```markdown
- **Devoluciones a proveedor**: `SupplierReturnService` es el unico camino. El borrador no mueve nada;
  despachar saca la mercancia con `TYPE_SUPPLIER_RETURN`; un rechazo la vuelve a meter con el mismo tipo en
  positivo. No uses `return` (es de clientes y nace de una venta) ni `waste` (sale como averia).
- **Devolver nunca deja negativo**: el despacho llama `StockService::move(requireAvailable: true)`, que mide
  existencia menos apartado con la fila bloqueada, ignore o no `allow_negative_stock`. El aviso del
  formulario es solo un aviso.
- **Lo devuelto de una orden no se suma a mano**: `PurchaseOrder::syncReturns()` recalcula
  `returned_quantity` desde las devoluciones despachadas. Si agregas otro camino que despache, rechace o anule,
  llamalo. `received_quantity` nunca se toca: dice lo que llego, no lo que quedo.
- **Una devolucion no recalcula el costo promedio**: sale al costo vigente, que se congela en el renglon.
  Lo que se le reclama al proveedor es otro dato (`unit_cost`: el de la orden o el ultimo con ese proveedor).
- **Reposicion = orden de compra**: la mercancia que manda el proveedor a cambio entra por una orden
  `source = replacement`, por el flujo normal de recepcion (lotes, vencimiento, costo). No le pongas plazo
  de pago: ya estaba pagada.
- **Rutas de devoluciones antes de `compras/{order}`**, o `{order}` se traga «devoluciones».
```

---

## 12. Entrega

Al cerrar cada fase, reporta:

- Archivos creados y modificados.
- Migraciones, incluida la de reparto de permisos.
- Resultado de `npx tsc --noEmit`, `npm run build`, `php artisan test` y `php artisan migrate:fresh --seed`.
- El recorrido manual de aceptación que hiciste.
- Lo que quedó pendiente, dicho sin rodeos.
