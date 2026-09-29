# Prompt: bodegas por sede y módulo de traslados

> Copia todo lo que está debajo de la línea en una sesión nueva de Claude Code abierta en
> `C:\xampp\htdocs\MiMercadoCol`. Antes de pegarlo, resuelve (o borra) la sección
> **Decisiones abiertas** del final: el prompt trae una recomendación para cada una.

---

## Contexto

Trabajas en "La Canasta" (Laravel 12 + Inertia 2 + React 19 + TypeScript estricto). Lee
`CLAUDE.md` y `README.md` antes de tocar nada: sus reglas mandan sobre este prompt.

Hoy el inventario se lleva **por sede**: `product_stocks` es único por `(product_id, store_id)`,
y `stock_movements` y `product_lots` también cuelgan de `store_id`. Ya existe un traslado
rápido entre sedes (`StockService::transfer`, ruta `inventory.transfer`, permiso
`inventory.index.transfer`) que escribe dos filas de kardex sin documento que lo respalde.

Necesito que cada sede tenga **varias bodegas** y un **módulo de traslados** con documento.

## Objetivo funcional

1. **Bodegas por sede.** Cada sede puede tener N bodegas. Ejemplo: "Bodega principal"
   (la mercancía exhibida, piso de venta) y "Bodega de almacenaje" (el depósito de atrás).
2. **Bodega que vende, parametrizable.** Por sede se decide:
   - qué bodega descuenta las ventas de **caja (POS)**;
   - qué bodega respalda la **tienda en línea** (lo que se muestra como disponible, lo que se
     reserva y lo que se despacha).
   Pueden ser la misma o distintas. Siempre hay exactamente una de cada por sede activa.
   Las demás bodegas no venden: su mercancía no aparece en caja ni en la tienda.
3. **Visibilidad en inventario.** Desde el módulo de Inventario puedo ver, para un artículo,
   cuánto hay en cada bodega de mi sede **y en las otras sedes**, con el nombre de la bodega.
4. **Traslados.** Un módulo para mover mercancía entre bodegas, de la misma sede o de sedes
   distintas, con documento numerado, estados y rastro en el kardex.

## Modelo de datos

Migraciones **nuevas**; no edites las existentes (la base puede estar en uso). Fechas con
`dateTime()`, nada de `enum` de MySQL, moneda en `bigInteger`.

### `warehouses`

| Columna | Tipo | Nota |
|---|---|---|
| `company_id`, `store_id` | FK | `store_id` con `cascadeOnDelete` no: una sede con bodegas se desactiva, no se borra |
| `name` | string | "Bodega principal" |
| `code` | string(20) | único por `(store_id, code)` |
| `type` | string(20) | `Warehouse::TYPE_SALES_FLOOR`, `TYPE_STORAGE` (informativo, no decide nada) |
| `is_pos_source` | boolean | la que descuenta las ventas de caja |
| `is_online_source` | boolean | la que respalda la tienda en línea |
| `is_receiving_default` | boolean | donde entra una compra si no se dice otra cosa |
| `is_active` | boolean | |
| `timestamps` | | |

La regla "una y solo una por sede" para cada bandera **no** se confía a la base: la hace cumplir
un `WarehouseService` que, dentro de una transacción con `lockForUpdate` sobre las bodegas de la
sede, apaga la bandera en las demás al encenderla en una. Una bodega con alguna de esas banderas
no se puede desactivar hasta pasarle la bandera a otra.

### Cambios a tablas existentes

- `product_stocks`: agregar `warehouse_id` (FK), cambiar el único a `(product_id, warehouse_id)`.
  `store_id` se conserva desnormalizado (lo usan joins de medio sistema) y siempre debe coincidir
  con el `store_id` de la bodega. `location` sigue siendo pasillo/estante **dentro** de la bodega.
- `stock_movements`: agregar `warehouse_id`. `balance_after` pasa a ser el saldo **de la bodega**.
- `product_lots`: agregar `warehouse_id`.
- `orders`: agregar `warehouse_id` (la bodega que reservó). Se fija al crear el pedido; si
  después cambian la bodega en línea, el pedido se despacha de donde reservó.
- `purchase_orders`: agregar `warehouse_id` (bodega de recepción, por defecto la
  `is_receiving_default` de la sede). Al recibir se puede cambiar por remesa.

### Migración de datos

Por cada sede existente se crea "Bodega principal" (`code` = `PRINCIPAL`) con las tres banderas
encendidas, y se rellena `warehouse_id` en `product_stocks`, `stock_movements`, `product_lots`,
`orders` y `purchase_orders`. Después de rellenar, `warehouse_id` pasa a `NOT NULL`. Nada debe
cambiar de comportamiento para una empresa que nunca cree una segunda bodega.

Crear una sede nueva (`StoreController::store`, importación de sedes, seeders) debe crearle su
bodega principal en la misma transacción.

## Reglas de negocio

- **Solo `StockService` toca `product_stocks`**, siempre en transacción con su movimiento. Sus
  métodos (`stockFor`, `available`, `move`, `receiveLot`, `consumeLots`, `returnToLots`,
  `reserve`, `releaseReservation`, `expiringLots`) pasan a recibir la **bodega**; la sede se
  deduce de ella. No dejes un camino que reciba `storeId` y adivine la bodega en silencio.
- Agrega a `WarehouseService` los resolutores explícitos: `posSourceFor(storeId)`,
  `onlineSourceFor(storeId)`, `receivingDefaultFor(storeId)`. Cada llamador pide la que le toca.
- **Quién usa qué bodega** (revisa cada llamador; esta lista salió de un `grep` y puede faltar alguno):
  - POS: `Pos/SaleController` (búsqueda y existencia mostrada) y `SaleService` → `posSourceFor`.
  - Tienda: `Storefront/CatalogController`, `CartService`, `StorefrontRenderer`,
    `OrderService::create` (reserva) → `onlineSourceFor`. `OrderService::deliver` descuenta de
    `orders.warehouse_id`, no de la bandera actual.
  - Compras: `PurchaseOrderController` al recibir → la bodega elegida en la remesa.
  - Alta de artículo con existencia inicial (`ProductController`) → `receivingDefaultFor`.
  - Ajustes, entradas y salidas (`InventoryController`, `StockMovementController`,
    `StockBatchService`) → la bodega que el usuario elija en el formulario.
  - Devoluciones → a la bodega del movimiento de venta original.
- **Costo promedio**: es por artículo y de toda la empresa. Un traslado **no** lo recalcula
  (misma regla que entradas y salidas: la única puerta que lo recalcula es una compra).
- **Lotes**: un traslado consume lotes FEFO en el origen (`consumeLots`) y crea en el destino
  lotes con el mismo `expires_at`, `code` y `unit_cost` que salieron. No se pierde el
  vencimiento por mover mercancía.
- **Alertas de mínimo y sugerida de compra** (`DashboardService`, `PurchaseSuggestionService`,
  `InventoryController`): se miden contra el **total de la sede** (suma de sus bodegas). Un
  artículo con 0 en el piso y 200 atrás no es una compra: es una reposición (ver fase 4).

## Módulo de traslados

### Tablas

- `stock_transfers`: `company_id`, `number` (por `NumberSequenceService`, nunca `count()+1`),
  `from_warehouse_id`, `to_warehouse_id`, `status`, `note`, `created_by_user_id`,
  `dispatched_by_user_id`, `dispatched_at`, `received_by_user_id`, `received_at`,
  `cancelled_by_user_id`, `cancelled_at`, `timestamps`.
- `stock_transfer_items`: `stock_transfer_id`, `product_id`, `quantity` (decimal 12,3 como el
  resto), `received_quantity`, `unit_cost` (costo al despachar, para valorizar el tránsito).

Constantes en el modelo: `StockTransfer::STATUS_DRAFT`, `STATUS_IN_TRANSIT`, `STATUS_RECEIVED`,
`STATUS_CANCELLED`. Recuerda meter todas las columnas nuevas en `$fillable`.

### Flujo

| Paso | Qué pasa en el inventario |
|---|---|
| Borrador | Nada. Se edita libremente. |
| Despachar | `transfer_out` en el origen por cada renglón; se valida existencia disponible (`quantity - reserved_quantity`). Estado `in_transit`. |
| Recibir | `transfer_in` en el destino por `received_quantity`. Estado `received`. |
| Anular | Solo en borrador. Un traslado en tránsito no se anula: se recibe (aunque sea en cero, ver decisiones). |

- **Entre bodegas de la misma sede** se puede despachar y recibir en un solo paso (botón
  "Trasladar ahora"), con los dos movimientos en la misma transacción.
- **Entre sedes** son dos pasos, y la recepción la hace alguien con acceso a la sede destino.
- Los movimientos de kardex apuntan al traslado por `reference` (morph) y la nota lleva el
  número del documento.
- Mientras está en tránsito la mercancía no cuenta en ninguna bodega; el listado de traslados
  muestra el valor en tránsito.

### Pantallas (todo por props de Inertia, nada de arrays literales en el `.tsx`)

- `Transfers/Index`: listado con filtros por estado, bodega origen/destino y fecha.
- `Transfers/Form`: elegir origen y destino, agregar artículos (reutiliza el buscador de
  `inventory.products` si sirve), mostrar la existencia disponible en el origen.
- `Transfers/Show`: detalle, botones de despachar / recibir / anular según estado **y** permiso;
  en recepción, un campo de cantidad recibida por renglón prellenado con lo despachado.

### Traslado rápido existente

`inventory.transfer` / `StockService::transfer` desaparecen como camino propio: el botón
"Trasladar" de Existencias abre el formulario de traslado con el artículo precargado, o crea un
traslado de un renglón y lo resuelve en el acto si es dentro de la misma sede. No quiero dos
formas de mover mercancía con reglas distintas.

## Inventario: ver otras bodegas y sedes

- `Inventory/Index`: selector de bodega (por defecto "Todas las de la sede", que suma) y, en la
  vista sumada, el desglose por bodega en la fila o en un desplegable.
- Acción "Ver en otras sedes" por artículo: un panel con cada sede y bodega de la empresa,
  existencia, reservado y disponible, y un atajo "Pedir traslado" hacia esa bodega.
- `Inventory/Movements` (kardex): filtro por bodega; el saldo mostrado es el de la bodega.

## Sedes: administrar bodegas

En `Stores/Index` (o una página hija `sedes/{store}/bodegas`, lo que quede más limpio): crear,
editar, desactivar bodegas y marcar cuál es la de caja, la de tienda en línea y la de recepción.
No se puede borrar una bodega con existencias o movimientos; se desactiva.

## Permisos

Todo con `permission:<nombre>` en la ruta. El front solo decide si pinta el botón; sin permiso
el botón no se pinta (no se deshabilita).

- `stores.warehouses`: `view`, `create`, `edit`, `deactivate`.
- `inventory.transfers`: `view`, `create`, `dispatch`, `receive`, `cancel`.
- `inventory.index.view_other_stores`: ver existencias de sedes a las que el usuario no está
  asignado (ver decisiones).
- Retira `inventory.index.transfer` del catálogo solo si ninguna ruta lo exige ya.

Agregarlos a `PermissionHelper` no se los da a nadie: escribe una migración de datos (como
`..._grant_sales_history_permission`) que dé los de traslados a quien hoy tiene
`inventory.index.transfer`, y los de bodegas a quien tiene `stores.index.edit`.

Revisa también que `EmployeeAccessService` / `store_user` sigan mandando: un usuario solo
despacha desde bodegas de sedes a las que tiene acceso y solo recibe en las suyas.

## Fases (entrega y verifica cada una antes de seguir)

1. **Bodegas sin cambio visible**: tabla, migración de datos, `WarehouseService`,
   `StockService` por bodega, todos los llamadores ajustados. Al terminar, el sistema se comporta
   exactamente igual con una bodega por sede. Todas las pruebas existentes en verde.
2. **Administración de bodegas y banderas** en Sedes; POS y tienda leyendo su bodega.
3. **Traslados** con documento, estados, lotes y permisos; retiro del traslado rápido.
4. **Visibilidad y reposición**: desglose en Existencias, panel "otras sedes", filtro en kardex
   y (opcional) una sugerencia de reposición almacenaje → piso para lo que esté bajo el mínimo en
   la bodega de venta pero tenga existencia atrás, que genere un traslado en borrador.

## Pruebas mínimas

- Migración de datos: una base con existencias queda con una bodega principal por sede y los
  mismos saldos.
- Venta POS descuenta de la bodega de caja; pedido en línea reserva y despacha de su bodega
  aunque la bandera cambie entre la reserva y la entrega.
- La tienda no muestra como disponible lo que está en almacenaje.
- Encender `is_pos_source` en una bodega la apaga en la otra; no se puede desactivar la bodega
  con bandera.
- Traslado misma sede en un paso; traslado entre sedes en dos pasos; recepción parcial;
  despachar más de lo disponible falla; los lotes llegan con su vencimiento; el costo promedio
  no cambia.
- Permisos: sin `dispatch` no se despacha (403), sin acceso a la sede destino no se recibe.
- `RouteSmokeTest` en verde con las rutas nuevas (si alguna necesita estado que el barrido no
  monta, exímela por nombre de parámetro en `panelRoutes()`).

## Al terminar

```bash
npx tsc --noEmit
npm run build
php artisan test
php artisan migrate:fresh --seed
```

Actualiza `CLAUDE.md` con las trampas nuevas (que `StockService` recibe bodega y no sede, qué
bodega usa cada canal, que el pedido guarda su bodega, que el traslado no recalcula costo y
conserva lotes) y el `README.md` con el módulo.

## Decisiones abiertas

Resuélvelas antes de pegar el prompt; entre paréntesis va lo que asumí:

1. **Diferencia al recibir un traslado entre sedes** (lo que no llegó se registra como
   `waste` en el origen con la nota "Faltante en traslado #N", y queda visible en el documento).
2. **¿Una sede puede ver existencias de otras sedes a las que el usuario no está asignado?**
   (Sí, solo lectura, con el permiso `inventory.index.view_other_stores`.)
3. **¿La tienda en línea puede vender de más de una bodega?** (No: una sola bodega por sede
   respalda la tienda. Sumar varias complica la reserva y el alistamiento.)
4. **¿El cajero ve en el POS que hay más en almacenaje?** (Sí, como dato informativo "Hay N en
   Bodega de almacenaje", sin poder venderlo.)
5. **¿Se permite traslado en borrador sin existencia suficiente?** (Sí; la validación se hace
   al despachar.)
6. **¿Quién recibe un traslado entre sedes?** (Cualquier usuario con `inventory.transfers.receive`
   y acceso a la sede destino.)
