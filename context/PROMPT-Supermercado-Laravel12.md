# Prompt maestro — Sistema de supermercado "La Canasta" (Laravel 12 + Inertia + React)

> **Para quién es este documento:** es el prompt de ejecución para un agente de código (Claude Opus / Claude Code).
> Se ejecuta por fases, en orden, sin saltarse ninguna. Cada fase tiene criterios de aceptación verificables.
>
> **Base de referencia:** el proyecto `ERP_CONFECCION` (Laravel 12 + Inertia 2 + React 19 + spatie/laravel-permission).
> De ese proyecto se copia **la mecánica** de autenticación, usuarios, roles, permisos y empleados —no sus campos,
> no sus tablas de nómina ni de producción, no su dominio de confección—. Los campos y el dominio son los que se
> describen aquí.

---

## 0. Reglas de ejecución (leer antes de escribir una sola línea)

**Regla de oro: no se entrega nada que no funcione.**

Está prohibido:

1. Botones, enlaces, menús o pestañas sin acción real (sin `onClick`, sin `router.visit`, sin ruta destino).
2. Rutas declaradas sin controlador implementado, o controladores que devuelvan datos inventados en el código.
3. Pantallas alimentadas con arrays literales en el `.tsx`. **Todos** los datos llegan por props de Inertia desde la base de datos.
4. Comentarios `// TODO`, `// pendiente`, pantallas "Próximamente", módulos a medias.
5. Migraciones sin modelo, modelos sin factory/seeder cuando el módulo los necesita para demostrarse.
6. Dejar una fase incompleta para empezar la siguiente.

Si una funcionalidad no se puede terminar en su fase, **no se muestra en la interfaz**: no se pinta el menú, no se
pinta el botón. Mejor un sistema pequeño completo que uno grande lleno de piezas muertas.

**Convenciones obligatorias**

| Tema | Regla |
|---|---|
| Idioma del código | Inglés: tablas, columnas, modelos, rutas, componentes, variables |
| Idioma de la interfaz | Español de Colombia. Sin tildes faltantes, sin "Spanglish" |
| Comentarios | Español, y solo donde expliquen una decisión no obvia. Nada de comentarios que repitan el código |
| Moneda | COP, entero, sin decimales. Se guarda en `bigInteger`. Se formatea `$ 1.240.500` con `Intl.NumberFormat('es-CO')` |
| Fechas | `America/Bogota`. En BD `datetime` UTC; se formatea con `date-fns` y locale `es` |
| Impuestos | IVA por producto (0, 5, 19). Los precios al público **incluyen IVA**; la base se calcula hacia atrás |
| Migraciones | Una migración por tabla, nombres en plural, timestamps siempre, FKs explícitas con `constrained()` |
| Enums | Nunca `enum` de MySQL: `string` + constantes públicas en el modelo (`Sale::STATUS_PAID`) |
| Validación | Siempre `FormRequest` propio por acción (`StoreProductRequest`, `UpdateProductRequest`) |
| Autorización | `permission:<nombre>` en la ruta **y** `Policy` cuando la regla depende del registro |
| Respuestas | Inertia en todo el panel. JSON solo para autocompletar/buscador y para el POS |
| TypeScript | `strict: true`. Tipos de cada payload en `resources/js/types/`. Prohibido `any` |

**Fuera de alcance de la versión 1** (no implementar, no dejar restos): autenticación en dos pasos, pasarelas de pago
en línea, facturación electrónica DIAN, app móvil nativa, multi-idioma.

---

## 1. Stack y arranque del proyecto

```bash
composer create-project laravel/laravel canasta "12.*"
cd canasta

composer require inertiajs/inertia-laravel:^2.0 tightenco/ziggy:^2.4 \
  spatie/laravel-permission:^6.16 barryvdh/laravel-dompdf:^3.1

php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan inertia:middleware

npm install react@^19 react-dom@^19 @inertiajs/react@^3 ziggy-js@^2 \
  @headlessui/react @phosphor-icons/react clsx tailwind-merge \
  react-hook-form @hookform/resolvers zod recharts sonner date-fns

npm install -D typescript @types/react @types/react-dom @types/node \
  @vitejs/plugin-react laravel-vite-plugin vite tailwindcss@^4 @tailwindcss/vite
```

`.env` de desarrollo:

```
APP_NAME="MiMercadoCol"
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
APP_TIMEZONE=America/Bogota
DB_CONNECTION=mysql
DB_DATABASE=canasta_pos
```

Estructura de front:

```
resources/js/
  app.tsx                  # createInertiaApp + ZiggyVite + Toaster de sonner
  types/index.d.ts         # PageProps compartidos (auth, flash, ziggy, activeCompany, activeStore)
  lib/                     # format.ts (moneda, fecha), cn.ts, permissions.ts (can())
  components/ui/           # Button, Input, Select, Table, Card, Badge, Modal, Tabs, Toggle, Pagination
  layouts/
    AuthLayout.tsx         # login y recuperación
    AppLayout.tsx          # back office: sidebar + topbar (tema claro)
    PosLayout.tsx          # punto de venta: riel de iconos, sin sidebar (tema oscuro)
    StoreLayout.tsx        # tienda online del cliente (móvil primero)
  pages/                   # una carpeta por módulo, ver §7
```

---

## 2. Mecánica que se copia de ERP_CONFECCION

Esto es lo único que se toma del proyecto de referencia. Se replica el **cómo**, con los nombres y campos de este
proyecto.

### 2.1 Multiempresa + sedes

- Tabla `companies`. **Toda** tabla de negocio lleva `company_id`.
- Global scope `CompanyScope` (equivalente a `App\Models\Scopes\CompanyScope` del ERP): filtra por la empresa activa
  salvo que el usuario sea `super_admin` en vista consolidada.
- `App\Support\TenantContext`: resuelve la empresa activa (la del usuario, o la elegida en sesión por el super admin)
  y expone `activeCompanyId()`.
- **Novedad de este proyecto:** `App\Support\StoreContext`. Las operaciones (caja, stock, ventas, pedidos) ocurren en
  una **sede** (`stores`). La sede activa se guarda en sesión al iniciar turno y se comparte por Inertia.
  Un usuario puede tener varias sedes permitidas (`store_user`); si solo tiene una, se selecciona sola.

### 2.2 Permisos (copiar el modelo exacto del ERP)

- Paquete `spatie/laravel-permission`, guard `web`.
- `App\Helpers\PermissionHelper`: **catálogo estático** de todos los permisos con formato `modulo.pagina.accion`
  y sus etiquetas legibles. Métodos: `getPermissionMatrix()`, `flatPermissions()`, `assignablePermissions()`,
  `permissionExists()`, `presetPermissions(string $preset)`, `actionLabel()`.
- **El rol es una plantilla, no la fuente de la verdad.** Los permisos efectivos de una persona son los de
  `model_has_permissions` (permisos directos del usuario). Igual que en el ERP:
  - `EffectivePermissionService::getEffectivePermissionNames(User)` → permisos directos; super admin = todos.
  - `User::hasPermissionTo()` sobreescrito: super admin siempre `true`; si no, se consulta el catálogo.
  - `UserPermissionService`: `sync()`, `applyRoleTemplate()`, `applyDelta()`. Único punto que escribe permisos.
  - `RoleTemplateService`: al editar un rol calcula `diff(before, after)`, guarda el cambio pendiente en sesión,
    lista los usuarios con ese rol indicando **qué gana y qué pierde cada uno**, y propaga solo a los elegidos.
- Middleware `permission:<nombre>` (alias de `App\Http\Middleware\CheckPermission`): valida sesión, cuenta activa,
  super admin, y si falla registra en `access_logs` y devuelve `Errors/403` de Inertia con 403.
- `HandleInertiaRequests::share()` publica: `auth.user` (con `permissions`, `accessible_pages`, `role`, `company`,
  `store`), `flash`, `ziggy`, `activeCompany`, `activeStore`, `storesForSelector`, y `permissionMatrix` **solo**
  en rutas `roles.*`.
- En React: `usePermission()` → `can('products.index.create')`. **Regla:** si no tiene el permiso, el botón no se
  renderiza (no se renderiza deshabilitado), y además la ruta lo rechaza en el servidor.

### 2.3 Autenticación

- Login con **correo o documento** (`login_mode`), igual que `LoginRequest` del ERP: `prepareForValidation()`
  deduce el modo, `credentialField()` decide dónde cuelga el error, `ensureIsNotRateLimited()` con
  `RateLimiter` (5 intentos por minuto por credencial+IP).
- Se rechaza el ingreso si: credenciales inválidas, `users.is_active = false`, empresa inactiva.
- `password_change_required` → middleware `ForcePasswordChange` que redirige a cambiar contraseña.
- `access_logs` registra `login`, `logout`, `permission_denied`, `login_failed`.
- **Al entrar siempre se redirige a `dashboard`** (`redirect()->intended(route('dashboard'))`).

### 2.4 Empleados (mecánica)

Del ERP se copia la idea de **empleado ≠ usuario**:

- `employees` es la ficha laboral. `users` es la cuenta de acceso.
- Un empleado puede tener o no cuenta: `users.employee_id` nullable.
- Desde la ficha del empleado se crea el acceso (`employees.access.store`), se activa/desactiva
  (`employees.access.toggle`), se cambia el rol (`employees.access.change_role`) y se restablece la contraseña
  (`employees.access.reset_password`), cada acción con su permiso propio.
- Baja lógica: `deactivate` / `reactivate` (no se borra historia). `destroy` solo si no tiene movimientos.

---

## 3. Modelo de datos (migraciones exactas)

Todas las tablas llevan `id`, `timestamps` y, salvo `companies`, `company_id` con
`foreignId(...)->constrained()->cascadeOnDelete()`.

### 3.1 Base y organización

**`companies`** — `name`, `legal_name`, `tax_id` (NIT, unique), `email`, `phone`, `address`, `logo` (nullable),
`currency` (default `COP`), `is_active` (bool, default true).

**`stores`** (sedes) — `company_id`, `name` ("Centro", "Norte", "Sur"), `code` (unique por empresa), `address`,
`phone`, `opens_at` (time), `closes_at` (time), `suggested_cash_base` (bigInteger, default 200000),
`is_active`. Índice `['company_id','code']` unique.

**`store_user`** — `store_id`, `user_id`, unique compuesto. Sedes en las que puede operar un usuario.

### 3.2 Personas y accesos

**`users`** — `company_id` (nullable solo para super admin), `employee_id` (nullable, `nullOnDelete`), `name`,
`last_name`, `email` (unique), `document` (nullable, unique por empresa — sirve para el login por documento),
`password`, `avatar` (nullable), `phone`, `job_title`, `pos_pin` (string, hasheado, nullable — PIN de 4 dígitos
para abrir caja), `is_active` (default true), `password_change_required` (default false), `password_changed_at`,
`last_login_at`, `softDeletes`.

**`employees`** — `company_id`, `store_id`, `first_name`, `last_name`, `document_type` (CC/CE/PA),
`document_number` (unique por empresa), `birth_date`, `phone`, `email`, `address`, `emergency_contact_name`,
`emergency_contact_phone`, `position` (cargo), `contract_type` (indefinido/fijo/obra/aprendiz),
`hired_at` (date), `terminated_at` (nullable), `work_schedule` (json: días y horas),
`status` (`active`|`inactive`), `photo` (nullable), `softDeletes`.

> Nota: **no** se replican los campos de nómina del ERP (salario, banco, modo de pago, parámetros legales).
> Este sistema no liquida nómina.

**Tablas de spatie** (`permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`)
con la misma extensión que el ERP: `roles` gana `company_id` (nullable), `display_name`, `description`,
`color` (default `#0E6E4E`), `is_system` (bool) y el unique pasa a `['name','guard_name','company_id']`.

**`access_logs`** — `company_id` (nullable), `user_id` (nullable), `action`, `ip`, `user_agent`, `context` (json).

### 3.3 Catálogo

**`categories`** — `company_id`, `name`, `slug`, `parent_id` (nullable, self), `icon`, `sort_order`, `is_active`.

**`suppliers`** — `company_id`, `name`, `tax_id`, `contact_name`, `phone`, `email`, `address`,
`lead_time_days` (int), `delivery_days` (json: ["lun","jue"]), `is_active`.

**`products`** — `company_id`, `category_id`, `supplier_id` (nullable),
`name`, `sku` (unique por empresa), `barcode` (nullable, unique por empresa), `brand`,
`unit` (`unit`|`kg`|`g`|`l`|`pack`), `sold_by_weight` (bool),
`cost` (bigInteger), `price` (bigInteger, PVP con IVA), `wholesale_price` (bigInteger, nullable),
`online_price` (bigInteger, nullable), `tax_rate` (decimal 5,2 — 0, 5 o 19),
`min_stock` (int), `track_lots` (bool), `allow_negative_stock` (bool),
`is_active`, `is_online_visible`, `is_featured`, `description` (text, nullable),
`image_path` (nullable), `softDeletes`.
Índices: `sku`, `barcode`, `['company_id','category_id']`, `['company_id','is_active']`.

**`product_images`** — `product_id`, `path`, `sort_order`.

**`product_stocks`** — `company_id`, `product_id`, `store_id`, `quantity` (decimal 12,3),
`reserved_quantity` (decimal 12,3, default 0 — pedidos en línea sin despachar), `location` (string, nullable).
Unique `['product_id','store_id']`.

**`stock_movements`** — `company_id`, `product_id`, `store_id`, `type`
(`purchase`|`sale`|`return`|`adjustment`|`transfer_in`|`transfer_out`|`waste`),
`quantity` (decimal 12,3, positiva o negativa), `balance_after` (decimal 12,3),
`unit_cost` (bigInteger, nullable), `reference_type`/`reference_id` (morph nullable: venta, compra, pedido),
`user_id`, `note`, `occurred_at`.

**`purchase_orders`** — `company_id`, `store_id`, `supplier_id`, `code` (EM-0001), `status`
(`draft`|`sent`|`received`|`cancelled`), `expected_at`, `received_at`, `subtotal`, `tax_total`, `total`,
`user_id`, `note`.
**`purchase_order_items`** — `purchase_order_id`, `product_id`, `quantity`, `received_quantity`, `unit_cost`,
`expires_at` (nullable), `lot_code` (nullable).

### 3.4 Clientes y crédito

**`customers`** — `company_id`, `document_type`, `document_number` (unique por empresa), `name`,
`business_name` (nullable), `email`, `phone`, `address`, `neighborhood`, `delivery_notes`,
`credit_limit` (bigInteger, default 0), `credit_term_days` (int, default 0),
`credit_enabled` (bool), `credit_blocked` (bool, default false), `blocked_reason` (nullable),
`is_online_user` (bool), `password` (nullable — acceso a la tienda online), `is_active`.

**`customer_balances`** (una fila por cliente, mantenida por el sistema) — `customer_id`, `balance` (bigInteger),
`overdue_balance`, `oldest_due_date` (nullable), `updated_at`.
**Nunca** se calcula el cupo disponible a mano en una vista: siempre `credit_limit - balance`.

**`customer_payments`** (abonos) — `company_id`, `store_id`, `customer_id`, `amount`, `method`
(`cash`|`card`|`transfer`), `received_by_user_id`, `register_session_id` (nullable), `note`, `paid_at`.
**`customer_payment_allocations`** — `customer_payment_id`, `sale_id`, `amount` (a qué factura se aplicó).

### 3.5 Caja y ventas

**`registers`** (cajas físicas) — `company_id`, `store_id`, `name` ("Caja 1"), `code`, `is_active`.

**`register_sessions`** (turnos) — `company_id`, `store_id`, `register_id`, `user_id`,
`opened_at`, `opening_amount` (bigInteger), `opening_breakdown` (json: denominación → cantidad),
`opening_note`, `closed_at` (nullable), `counted_amount` (nullable), `closing_breakdown` (json, nullable),
`expected_amount` (nullable), `difference` (nullable), `closing_note`,
`status` (`open`|`closed`). Índice único parcial lógico: **no puede haber dos sesiones `open` para el mismo
`register_id`** (validar en el servicio y con `unique(['register_id','status'])` solo si se usa `status` nullable;
si no, validación en `RegisterSessionService` + transacción con bloqueo).

**`cash_movements`** — `register_session_id`, `type` (`in`|`out`), `amount`, `reason`, `user_id`, `occurred_at`.

**`sales`** — `company_id`, `store_id`, `register_session_id` (nullable para ventas de la tienda online),
`customer_id` (nullable), `user_id`, `number` (consecutivo por sede, ej. `C1-000482`),
`channel` (`pos`|`online`), `status` (`paid`|`credit`|`cancelled`|`refunded`),
`subtotal` (base sin IVA), `tax_total`, `discount_total`, `total`,
`paid_total`, `due_total`, `due_date` (nullable, para crédito),
`note`, `sold_at`, `cancelled_at`, `cancelled_by_user_id`, `cancel_reason`.

**`sale_items`** — `sale_id`, `product_id`, `description` (copia del nombre al momento de vender),
`quantity` (decimal 12,3), `unit_price`, `tax_rate`, `discount`, `line_total`, `unit_cost` (para el margen).

**`sale_payments`** — `sale_id`, `method` (`cash`|`card`|`transfer`|`credit`), `amount`,
`received_amount` (nullable, efectivo entregado), `change_amount` (nullable), `reference` (nullable).

### 3.6 Pedidos y domicilios

**`orders`** — `company_id`, `store_id`, `customer_id`, `number` (#1842), `channel`
(`app`|`whatsapp`|`counter`), `status` (`new`|`picking`|`ready`|`on_route`|`delivered`|`cancelled`|`returned`),
`payment_method` (`cash_on_delivery`|`card`|`transfer`|`credit`), `payment_status` (`pending`|`paid`),
`delivery_address`, `delivery_notes`, `delivery_slot_start`, `delivery_slot_end`,
`courier_user_id` (nullable), `subtotal`, `delivery_fee`, `discount_total`, `tax_total`, `total`,
`sale_id` (nullable — la venta que se genera al entregar), `placed_at`, `delivered_at`, `cancel_reason`.

**`order_items`** — `order_id`, `product_id`, `quantity`, `unit_price`, `line_total`,
`substitution_policy` (`replace`|`call`|`remove`), `picked_quantity` (nullable), `pick_note`.

**`order_status_histories`** — `order_id`, `from_status`, `to_status`, `user_id`, `note`, `changed_at`.

### 3.7 Tienda online

**`carts`** — `company_id`, `store_id`, `customer_id` (nullable), `session_token`, `expires_at`.
**`cart_items`** — `cart_id`, `product_id`, `quantity`, `substitution_policy`.

### 3.8 Ajustes

**`settings`** — `company_id`, `key`, `value` (json), unique `['company_id','key']`.
Claves iniciales: `pos.receipt_footer`, `pos.allow_discount_percent`, `store.delivery_fee`,
`store.free_delivery_from`, `store.slots` (json), `credit.block_on_overdue_days`.

---

## 4. Catálogo de permisos (literal, va en `PermissionHelper`)

Formato `modulo.pagina.accion`. Esta lista es la fuente de la verdad: si un permiso no está aquí, no existe;
si existe aquí, alguna ruta lo exige y aparece en la matriz de roles.

```
dashboard.index.view            Ver el panel de inicio
dashboard.index.sales_totals    Ver totales de ventas de la empresa

pos.sale.view                   Abrir el punto de venta
pos.sale.create                 Registrar ventas
pos.sale.discount               Aplicar descuentos
pos.sale.credit                 Vender a crédito
pos.sale.cancel                 Anular una venta cobrada
pos.sale.suspend                Suspender y recuperar tickets
pos.sale.refund                 Registrar devoluciones

registers.open.view             Ver la pantalla de apertura de caja
registers.open.create           Abrir caja con base inicial
registers.close.view            Ver el cierre de caja
registers.close.create          Cerrar turno y generar el informe Z
registers.close.view_others     Ver y cerrar turnos de otros cajeros
registers.movements.create      Registrar sangrías e ingresos de efectivo
registers.index.view            Ver el listado de cajas
registers.index.manage          Crear, editar y desactivar cajas

products.index.view             Ver el catálogo
products.index.create           Crear artículos
products.index.edit             Editar artículos
products.index.delete           Eliminar artículos
products.index.export           Exportar el catálogo
products.index.import           Importar artículos desde CSV
products.index.print_labels     Imprimir etiquetas de precio
products.show.view              Ver la ficha del artículo
products.show.view_cost         Ver costo y margen
products.show.edit_price        Cambiar precios

categories.index.view / create / edit / delete
suppliers.index.view / create / edit / delete

inventory.index.view            Ver existencias
inventory.index.adjust          Registrar ajustes y averías
inventory.index.transfer        Trasladar entre sedes
inventory.movements.view        Ver el kárdex de movimientos
purchases.index.view            Ver órdenes de compra
purchases.index.create          Crear órdenes de compra
purchases.index.receive         Recibir mercancía
purchases.index.cancel          Anular órdenes

customers.index.view / create / edit / delete
customers.show.view             Ver la ficha del cliente
credit.index.view               Ver la cartera
credit.index.set_limit          Asignar o cambiar el cupo
credit.index.block              Bloquear o desbloquear el crédito
credit.payments.create          Registrar abonos
credit.payments.view            Ver el histórico de abonos
credit.index.export             Exportar el estado de cartera

orders.index.view               Ver pedidos
orders.index.create             Crear pedidos desde el mostrador
orders.show.view                Ver el detalle del pedido
orders.show.pick                Alistar y marcar reemplazos
orders.show.assign_courier      Asignar domiciliario
orders.show.status              Cambiar el estado del pedido
orders.show.cancel              Cancelar pedidos
orders.show.collect             Registrar el cobro contraentrega

employees.index.view / create / edit / delete / export
employees.index.deactivate      Desactivar empleado
employees.index.reactivate      Reactivar empleado
employees.show.view             Ver la ficha del empleado
employees.access.create         Crear la cuenta de acceso
employees.access.toggle         Activar o desactivar el acceso
employees.access.change_role    Cambiar el rol de la cuenta
employees.access.reset_password Restablecer la contraseña
employees.access.reset_pin      Restablecer el PIN de caja

users.index.view / create / edit / delete
users.index.toggle              Activar o desactivar usuarios
users.edit.permissions          Ajustar los permisos de un usuario
users.show.view                 Ver la ficha del usuario

roles.index.view / create / edit / delete
roles.edit.propagate            Aplicar el cambio del rol a sus usuarios

reports.sales.view / export
reports.inventory.view / export
reports.credit.view / export
reports.employees.view / export

storefront.catalog.manage       Decidir qué se publica en la tienda online
settings.index.view / edit
stores.index.view / create / edit / delete
```

Permisos de super administrador (cubiertos por el middleware `super.admin`, se listan para completar el catálogo):
`companies.index.view|create|edit|delete`, `companies.index.switch`.

---

## 5. Roles por defecto (`StoreDefaultRolesService`)

Se crean por empresa al crearla y al ejecutar el seeder. `admin` es `is_system = true` (no se edita ni se borra).
`presetPermissions()` en `PermissionHelper` devuelve estas listas.

| Rol (`name`) | `display_name` | Color | Permisos |
|---|---|---|---|
| `super_admin` | Super Administrador | `#B3261E` | Todos, `company_id = null`, `is_system` |
| `admin` | Administrador | `#0E6E4E` | Todos los de la empresa (todo el catálogo menos `companies.*`) |
| `supervisor` | Supervisor de tienda | `#1D6FA5` | Todo excepto: `users.*`, `roles.*`, `settings.index.edit`, `stores.*`, `products.index.delete`, `credit.index.set_limit` |
| `cajero` | Cajero | `#0E6E4E` | `dashboard.index.view`, `pos.sale.view`, `pos.sale.create`, `pos.sale.discount`, `pos.sale.suspend`, `registers.open.view`, `registers.open.create`, `registers.close.view`, `registers.close.create`, `registers.movements.create`, `products.index.view`, `customers.index.view`, `credit.payments.create`, `orders.index.view` |
| `bodega` | Bodega e inventario | `#8F4705` | `dashboard.index.view`, `products.index.view`, `products.show.view`, `inventory.*`, `purchases.*`, `suppliers.index.view`, `orders.index.view`, `orders.show.view`, `orders.show.pick` |
| `domiciliario` | Domiciliario | `#1D6FA5` | `orders.index.view`, `orders.show.view`, `orders.show.status`, `orders.show.collect` |
| `auxiliar_contable` | Auxiliar contable | `#565E64` | `dashboard.index.view`, `dashboard.index.sales_totals`, `reports.*`, `credit.index.view`, `credit.payments.view`, `credit.index.export`, `registers.close.view`, `registers.close.view_others`, `products.show.view_cost` |

Reglas:

- `cajero` **no** lleva `pos.sale.credit` ni `pos.sale.cancel`: los enciende el administrador por usuario.
- Cambiar un rol **no** cambia a nadie automáticamente: se muestra el diálogo de propagación (§7.7).

---

## 6. Rutas (`routes/web.php`)

Grupo público (`guest`): `/` redirige a `login` cuando no hay sesión.
Grupo `auth` + `verified.company` + `force.password.change` para todo el panel.

```php
// Autenticación (routes/auth.php)
GET  login                         Auth\AuthenticatedSessionController@create      -> name login
POST login                         Auth\AuthenticatedSessionController@store
POST logout                        Auth\AuthenticatedSessionController@destroy     -> name logout
GET  forgot-password  / POST       Auth\PasswordResetController
GET  reset-password/{token} / POST Auth\PasswordResetController

// Panel
GET  dashboard                     DashboardController@index            permission:dashboard.index.view

// Punto de venta
GET    pos                         Pos\SaleController@index             permission:pos.sale.view
GET    pos/products                Pos\SaleController@products          permission:pos.sale.view      (JSON, búsqueda y código de barras)
POST   pos/sales                   Pos\SaleController@store             permission:pos.sale.create
POST   pos/sales/{sale}/cancel     Pos\SaleController@cancel            permission:pos.sale.cancel
GET    pos/sales/{sale}/receipt    Pos\SaleController@receipt           permission:pos.sale.view       (PDF térmico 80mm)
POST   pos/hold / GET pos/hold     Pos\HeldSaleController                permission:pos.sale.suspend

GET    caja/apertura               Registers\OpeningController@create   permission:registers.open.view
POST   caja/apertura               Registers\OpeningController@store    permission:registers.open.create
GET    caja/cierre                 Registers\ClosingController@create   permission:registers.close.view
POST   caja/cierre                 Registers\ClosingController@store    permission:registers.close.create
POST   caja/movimientos            Registers\CashMovementController@store permission:registers.movements.create

// Catálogo
resource productos                 ProductController                    (view/create/edit/delete por acción)
GET    productos/{product}         ProductController@show               permission:products.show.view
POST   productos/{product}/precio  ProductController@updatePrice        permission:products.show.edit_price
resource categorias                CategoryController
resource proveedores               SupplierController

// Inventario
GET    inventario                  InventoryController@index            permission:inventory.index.view
POST   inventario/ajustes          InventoryController@adjust           permission:inventory.index.adjust
POST   inventario/traslados        InventoryController@transfer         permission:inventory.index.transfer
GET    inventario/kardex/{product} InventoryController@movements        permission:inventory.movements.view
resource compras                   PurchaseOrderController
POST   compras/{order}/recibir     PurchaseOrderController@receive      permission:purchases.index.receive

// Personas
resource empleados                 EmployeeController
GET    empleados/{employee}        EmployeeController@show              permission:employees.show.view
POST   empleados/{employee}/acceso EmployeeAccessController@store       permission:employees.access.create
PATCH  empleados/{employee}/acceso/estado  EmployeeAccessController@toggle       permission:employees.access.toggle
PATCH  empleados/{employee}/acceso/rol     EmployeeAccessController@changeRole   permission:employees.access.change_role
POST   empleados/{employee}/acceso/clave   EmployeeAccessController@resetPassword permission:employees.access.reset_password
POST   empleados/{employee}/acceso/pin     EmployeeAccessController@resetPin      permission:employees.access.reset_pin
PATCH  empleados/{employee}/desactivar     EmployeeController@deactivate  permission:employees.index.deactivate
PATCH  empleados/{employee}/reactivar      EmployeeController@reactivate  permission:employees.index.reactivate

resource usuarios                  UserController
GET    usuarios/{user}/permisos    UserController@permissions           permission:users.edit.permissions
PUT    usuarios/{user}/permisos    UserController@updatePermissions     permission:users.edit.permissions
PATCH  usuarios/{user}/estado      UserController@toggle                permission:users.index.toggle

resource roles                     RoleController
POST   roles/{role}/propagar       RoleController@propagate             permission:roles.edit.propagate

// Clientes y cartera
resource clientes                  CustomerController
GET    cartera                     CreditController@index               permission:credit.index.view
GET    cartera/{customer}          CreditController@show                permission:credit.index.view
POST   cartera/{customer}/cupo     CreditController@setLimit            permission:credit.index.set_limit
POST   cartera/{customer}/bloqueo  CreditController@toggleBlock         permission:credit.index.block
POST   cartera/{customer}/abonos   CustomerPaymentController@store      permission:credit.payments.create

// Pedidos
GET    pedidos                     OrderController@index                permission:orders.index.view
GET    pedidos/{order}             OrderController@show                 permission:orders.show.view
POST   pedidos                     OrderController@store                permission:orders.index.create
PATCH  pedidos/{order}/estado      OrderController@updateStatus         permission:orders.show.status
POST   pedidos/{order}/alistar     OrderController@pick                 permission:orders.show.pick
POST   pedidos/{order}/domiciliario OrderController@assignCourier       permission:orders.show.assign_courier
POST   pedidos/{order}/cobro       OrderController@collect              permission:orders.show.collect

// Informes
GET    informes                    ReportController@index               permission:reports.sales.view
GET    informes/ventas/export      ReportController@exportSales         permission:reports.sales.export

// Ajustes
GET/PUT ajustes                    SettingController                    permission:settings.index.view|edit
resource sedes                     StoreController                      permission:stores.*

// Tienda online del cliente (prefijo /tienda, guard `customer`)
GET  tienda                        Storefront\CatalogController@index
GET  tienda/producto/{product}     Storefront\CatalogController@show
GET  tienda/carrito                Storefront\CartController@index
POST tienda/carrito                Storefront\CartController@store
PATCH tienda/carrito/{item}        Storefront\CartController@update
GET  tienda/pago                   Storefront\CheckoutController@create   middleware customer.auth
POST tienda/pago                   Storefront\CheckoutController@store    middleware customer.auth
GET  tienda/pedido/{order}         Storefront\OrderTrackingController@show middleware customer.auth
GET/POST tienda/ingresar           Storefront\CustomerAuthController
```

---

## 7. Pantallas (Inertia) y comportamiento exigido

Cada pantalla replica el diseño del canvas descrito en §8. **Cada pantalla se entrega funcionando de punta a punta.**

### 7.1 `Auth/Login` — primera página de la aplicación

- `/` sin sesión → `login`. Con sesión → `dashboard`.
- Tarjeta centrada sobre fondo `--bg`, logo, título "Entrar a La Canasta".
- Pestañas **Correo / Documento** (`login_mode`), campo contraseña con botón mostrar/ocultar, casilla "Recordarme",
  enlace "Olvidé mi contraseña".
- Errores bajo el campo correspondiente. Bloqueo por intentos con mensaje del tiempo restante.
- Al entrar: redirige a `dashboard`. Si `password_change_required`, a la pantalla de cambio de contraseña.
- **Aceptación:** con las credenciales del seeder se entra y se ve el dashboard con datos reales; con una cuenta
  `is_active = false` aparece "Tu cuenta está desactivada"; al sexto intento fallido aparece el bloqueo.

### 7.2 `Dashboard/Index`

Datos del día, de la sede activa, calculados en `DashboardService` con consultas agregadas (nada en memoria):

- 4 indicadores: ventas de hoy (suma `sales.total` de hoy), tickets, ticket promedio, cartera por cobrar
  (suma `customer_balances.balance`). Cada uno con su comparación contra ayer.
- Gráfica de barras de ventas por hora (`recharts`, una sola serie, color `--accent`, tooltip con hora y valor).
- Estado de cajas: sesiones abiertas con cajero, hora de apertura y efectivo esperado; las cajas libres muestran
  el botón **Abrir** que lleva a `caja/apertura`.
- Últimos pedidos (5) con estado y enlace al detalle; más vendidos del día; alertas de inventario
  (`product_stocks.quantity <= products.min_stock`) con enlace a inventario.
- `dashboard.index.sales_totals` filtra los tres indicadores de dinero: sin el permiso se muestran tickets y alertas.
- **Aceptación:** registrar una venta en el POS y recargar el dashboard cambia los indicadores y la gráfica.

### 7.3 Artículos

- `Products/Index`: buscador (nombre, SKU, código de barras), chips de categoría, filtros de estado/proveedor/stock,
  tabla paginada del servidor (20 por página) con thumbnail, nombre+SKU, categoría, precio, costo, margen calculado,
  barra de stock, estado y acciones. Selección múltiple con acciones en lote (cambiar precio, cambiar categoría,
  imprimir etiquetas, desactivar) **implementadas**.
- `Products/Show`: ficha con galería, identificación, proveedor, precios y tributos, existencias por sede,
  ventas de los últimos 14 días y kárdex reciente. Costo y margen ocultos sin `products.show.view_cost`.
- `Products/Form`: crear y editar con el mismo componente (`mode: 'create' | 'edit'`).
  Secciones: identificación, precios (el margen se calcula en vivo al escribir costo y precio), inventario
  (stock inicial por sede al crear), tienda online. Validación `zod` en cliente + `FormRequest` en servidor.
- **Aceptación:** crear un artículo lo deja disponible de inmediato en el POS y en la tienda online si se marcó visible.

### 7.4 Empleados

- `Employees/Index`: indicadores (activos, en turno, contratos por vencer, sin PIN), filtros por cargo y sede,
  tabla con empleado, cargo, documento, sede, rol de la cuenta, estado y ventas del mes (solo si tiene usuario cajero).
- `Employees/Show`: datos personales, vinculación, turnos de la semana, **bloque "Acceso al sistema"** con las
  cuatro acciones del §2.4 y el rendimiento del mes.
- `Employees/Form`: datos personales, vinculación, turnos, y —solo al crear— la casilla "Crear cuenta de acceso"
  que despliega usuario, rol y PIN. La contraseña temporal se muestra **una sola vez** por flash
  (`flash.temporary_password`) y se obliga a cambiarla al primer ingreso.
- **Aceptación:** crear un empleado con acceso, cerrar sesión, entrar con esa cuenta, ser forzado a cambiar la
  contraseña y ver el dashboard limitado por su rol.

### 7.5 Usuarios

- `Users/Index`: filtros por rol, sede y estado; badge del rol con su color; última conexión.
- `Users/Form`: datos, rol (plantilla) y sedes permitidas.
- `Users/Permissions`: la matriz del catálogo agrupada por módulo → página → acciones, con casillas por acción,
  "seleccionar todo el módulo", buscador de permisos y un resumen "Gana X / Pierde Y" antes de guardar.
  Guarda con `UserPermissionService::sync()`.
- **Aceptación:** quitarle `products.index.create` a un usuario hace desaparecer el botón "Nuevo artículo" y
  devuelve 403 al forzar la URL.

### 7.6 Roles

- `Roles/Index`: tarjetas o tabla con nombre, color, descripción, número de permisos y número de usuarios.
- `Roles/Form`: nombre, descripción, color y la matriz de permisos (misma que usuarios, desde `permissionMatrix`).
- `Roles/Show`: permisos del rol y usuarios que lo tienen.
- **Propagación:** al guardar cambios, si hay diferencia, se abre el diálogo con la lista de usuarios del rol y,
  por cada uno, cuántos permisos gana y cuántos pierde; se eligen los destinatarios y se aplica **solo la diferencia**
  (`RoleTemplateService::propagate`). Si no se elige a nadie, el rol queda cambiado y nadie se altera.
- Los roles `is_system` no se editan ni se borran; un rol con usuarios no se puede eliminar.

### 7.7 Punto de venta

**`Pos/Opening`** (apertura de caja, tema oscuro):
selección del cajero entre los usuarios con `registers.open.create` de la sede, PIN con teclado en pantalla,
selección de caja (las ocupadas se muestran bloqueadas con su cajero), conteo de la base por denominaciones con
total en vivo, base sugerida de la sede, observaciones y verificación previa (impresora, datáfono, diferencia del
cierre anterior). El botón abre la sesión y **redirige al POS**.

**`Pos/Sale`** (pantalla principal, tema oscuro, sin sidebar):
- Banner superior: caja y cajero, campo de código de barras con foco automático (Enter agrega el artículo), y
  botonera F2 Cliente, F3 Descuento, F4 Suspender, F5 Recuperar, F6 Devolución. Los atajos de teclado funcionan.
- Botonera de categorías + cuadrícula de artículos (botones grandes con nombre, unidad, precio y stock).
  Al tocar uno se agrega al ticket; los agotados no se agregan salvo `allow_negative_stock`.
- Panel derecho: ticket con líneas, cantidades con botones de 44px, totales (base, IVA, descuento, TOTAL),
  cliente seleccionado y **franja de crédito** (cupo, deuda, disponible) cuando el cliente tiene crédito.
- Cobro: métodos efectivo / tarjeta / transferencia / crédito, teclado numérico, montos rápidos, cambio en vivo.
  Con crédito se muestra deuda actual, esta venta y nuevo saldo; si supera el cupo o el cliente está bloqueado,
  el cobro se rechaza con el motivo (y solo se permite con `pos.sale.credit` y autorización de supervisor).
- Al confirmar: transacción que crea `sale` + `sale_items` + `sale_payments`, descuenta stock, escribe
  `stock_movements`, actualiza `customer_balances` si es crédito, y devuelve el número de ticket.
- **Aceptación:** una venta en efectivo descuenta el stock del artículo y aparece en el dashboard y en informes;
  una venta a crédito sube el saldo del cliente y baja su cupo disponible en la misma pantalla del POS.

**`Pos/Closing`** (cierre): conteo por denominaciones, retiros, esperado = base + ventas en efectivo + abonos − retiros,
contado, **diferencia** resaltada, ventas por medio de pago, y el botón que cierra el turno e imprime el informe Z (PDF).
Cerrar bloquea la caja hasta una nueva apertura.

### 7.8 Clientes y crédito

- `Credit/Index`: indicadores (cartera total, al día, en mora, abonos de hoy), filtros, tabla con cupo, saldo,
  disponible, días de mora y estado; panel lateral con el estado de cuenta del cliente seleccionado, sus facturas
  pendientes y el botón **Registrar abono**.
- El abono se aplica a las facturas más antiguas primero (`customer_payment_allocations`) y recalcula
  `customer_balances` en la misma transacción.
- Un cliente con factura vencida más de `credit.block_on_overdue_days` se marca `credit_blocked` por tarea programada
  y por verificación al vender.
- **Aceptación:** registrar un abono baja el saldo, libera cupo y eso se ve de inmediato en el POS.

### 7.9 Pedidos y domicilios

- `Orders/Index`: pestañas por estado con conteos reales, tabla de pedidos, panel de domiciliarios en turno y
  cumplimiento de entrega.
- `Orders/Show`: artículos con alistamiento (cantidad recogida, reemplazo, agotado), totales recalculados,
  cliente, entrega (franja, domiciliario, dirección), pago y línea de tiempo con `order_status_histories`.
- Al marcar **entregado** se genera la venta (`sales` con `channel = online`), se descuenta el stock reservado y,
  si es contraentrega, se registra el cobro.
- **Aceptación:** mover un pedido por los cinco estados deja trazabilidad en la línea de tiempo y, al entregar,
  crea la venta y el movimiento de inventario.

### 7.10 Inventario e informes

- `Inventory/Index`: pestañas existencias / entradas / ajustes / órdenes de compra, filtros (bajo mínimo,
  por vencer, sin movimiento), columnas por sede y cantidad sugerida a pedir; botón que genera la orden de compra
  con los faltantes.
- `Reports/Index`: rango de fechas, sede y canal; ventas por día (barras, una serie, línea de promedio),
  ventas por categoría y por medio de pago (barras horizontales), ventas por empleado; exportación a CSV/PDF real.

### 7.11 Tienda online (móvil, 390 px)

`Storefront/Home`, `Storefront/Product`, `Storefront/Cart`, `Storefront/Checkout`, `Storefront/OrderTracking`,
`Storefront/Login`.

- Catálogo: solo productos con `is_online_visible` y stock disponible de la sede elegida por dirección.
- Carrito persistente (`carts` por `session_token` o cliente).
- Checkout: dirección, franja horaria de `settings.store.slots`, medio de pago. Con **crédito en tienda** se muestran
  cupo, deuda actual, este pedido, nuevo saldo y cupo restante; si no alcanza, la opción se deshabilita con el motivo.
- Confirmar crea el `order` en estado `new`, reserva stock (`reserved_quantity`) y lleva al seguimiento.
- **Aceptación:** un pedido hecho en la tienda aparece en `Orders/Index` del panel en menos de un segundo (recarga).

---

## 8. Sistema visual (tokens exactos)

Dos temas sobre los mismos tokens. Back office y tienda en **claro**; POS (apertura, venta, cierre) en **oscuro**.
Se aplica con `data-theme="light|dark"` en `<html>` y variables CSS en `resources/css/app.css`.

```css
:root, [data-theme="light"] {
  --bg:#F4F4F1; --surface:#FFFFFF; --surface-2:#ECECE7; --surface-3:#E3E3DC;
  --line:#D8D8D0; --line-2:#C3C3B9; --text:#14171A; --muted:#565E64;
  --accent:#0E6E4E; --accent-ink:#FFFFFF; --accent-soft:#DCEDE4;
  --warn:#8F4705; --warn-soft:#F7E8D8; --danger:#A81F16; --danger-soft:#F7E0DE;
}
[data-theme="dark"] {
  --bg:#0C1012; --surface:#151A1D; --surface-2:#1D2428; --surface-3:#273035;
  --line:#2B3439; --line-2:#3C484E; --text:#EDF0EF; --muted:#A3AEB3;
  --accent:#2FBE7E; --accent-ink:#052018; --accent-soft:#123029;
  --warn:#E9A55E; --warn-soft:#33260F; --danger:#F27A70; --danger-soft:#3A1C19;
}
```

- Tipografías (Google Fonts): **Bricolage Grotesque** para títulos (700), **IBM Plex Sans** para interfaz (400/500/600),
  **IBM Plex Mono** con `font-variant-numeric: tabular-nums` para **todas** las cifras, precios y totales.
- Radios: 8 px chips · 9–11 px controles · 14 px tarjetas · 16–20 px en móvil. Escala de espaciado de 4 px.
- Altura mínima de controles: 38–40 px en el back office, **44 px en el POS y en la tienda móvil**.
- El verde es el único color de marca; ámbar y rojo **solo** comunican estado (stock, mora, diferencias de caja).
- Contraste mínimo 4.5:1. Iconos: `@phosphor-icons/react` con trazo, nunca emoji.
- Medidas de referencia: back office 1440 px con sidebar de 238 px y barra superior de 64 px; POS 1440×900 con riel
  de 72 px y panel de ticket de 432 px; tienda 390 px con barra inferior de 70 px.

---

## 9. Reglas de negocio (implementar en servicios, no en controladores)

**Totales de una venta** (`SaleTotalsCalculator`), en enteros:

```
line_total      = round(quantity * unit_price) - discount
total           = suma de line_total
base_linea      = round(line_total / (1 + tax_rate/100))
tax_total       = total - suma(base_linea)
subtotal        = total - tax_total
due_total       = total - paid_total
```

**Stock** (`StockService`) — único punto que toca `product_stocks`:

- Toda variación escribe `stock_movements` con `balance_after`, dentro de la misma transacción.
- Venta POS: descuenta al confirmar. Pedido online: `reserved_quantity` al confirmar, descuento real al entregar.
- No se permite dejar negativo salvo `products.allow_negative_stock`; si se permite, se marca alerta.
- Recepción de compra: suma stock y **recalcula el costo promedio ponderado** del producto.

**Crédito** (`CreditService`):

```
disponible = credit_limit - balance
se puede vender a crédito si:
  customer.credit_enabled
  && !customer.credit_blocked
  && total <= disponible
  && usuario tiene pos.sale.credit
```
Toda venta a crédito crea `sales.status = credit`, `due_date = hoy + credit_term_days` y actualiza
`customer_balances`. Un abono recorre las facturas de la más antigua a la más nueva.

**Caja** (`RegisterSessionService`):

```
esperado = opening_amount
         + ventas en efectivo del turno
         + abonos en efectivo del turno
         + cash_movements(in)
         - cash_movements(out)
diferencia = counted_amount - esperado
```
No se puede abrir una caja con sesión abierta, ni vender sin sesión abierta (el POS redirige a la apertura).
Cerrar exige conteo; la diferencia distinta de cero exige observación.

**Numeración**: `sales.number` = prefijo de caja + consecutivo por sede, generado dentro de la transacción con
bloqueo (`lockForUpdate`) sobre un contador por sede. Nunca con `count() + 1`.

**Pedidos**: los cambios de estado válidos son
`new → picking → ready → on_route → delivered`, con `cancelled` desde cualquiera antes de `delivered` y
`returned` solo desde `delivered`. Cualquier otro salto se rechaza en el servicio.

---

## 10. Seeders (datos con los que se demuestra el sistema)

`DatabaseSeeder` ejecuta en este orden:

1. `PermissionSeeder` — crea todos los permisos del catálogo.
2. `CompanySeeder` — empresa "La Canasta S.A.S." (NIT de ejemplo) + 3 sedes: Centro, Norte, Sur.
3. `RoleSeeder` — roles del §5 por empresa + `super_admin` global.
4. `UserSeeder` — cuentas de prueba (contraseña `Password123*` en todas):

   | Correo | Rol | Sede |
   |---|---|---|
   | `super@lacanasta.co` | super_admin | — |
   | `admin@lacanasta.co` | admin | Centro |
   | `supervisor@lacanasta.co` | supervisor | Centro |
   | `cajera@lacanasta.co` | cajero (PIN 1234) | Centro |
   | `bodega@lacanasta.co` | bodega | Centro |
   | `domicilios@lacanasta.co` | domiciliario | Centro |
   | `contable@lacanasta.co` | auxiliar_contable | Centro |

5. `CatalogSeeder` — 8 categorías, 6 proveedores y **60 productos** reales de supermercado (arroz, leche, huevos,
   papa, tomate, pollo, aceite, café, papel higiénico, detergente…) con costo, precio, IVA y stock por sede.
6. `CustomerSeeder` — 15 clientes, 6 con crédito (uno al día, uno por vencer, uno en mora, uno bloqueado).
7. `DemoOperationSeeder` — 30 días de ventas (unas 200 ventas con horas realistas), 2 turnos de caja cerrados,
   1 turno abierto, 12 pedidos repartidos por los estados, 4 órdenes de compra, abonos de cartera.

Después del seeder, **el dashboard, los informes y la cartera muestran cifras coherentes entre sí** (la suma de
ventas del informe debe coincidir con la del dashboard para el mismo día).

---

## 11. Fases de ejecución

Cada fase termina con `php artisan migrate:fresh --seed`, `npm run build` y `php artisan test` en verde.
No se pasa a la siguiente hasta cumplir los criterios.

| Fase | Contenido | Criterio de aceptación |
|---|---|---|
| **F0** | Proyecto, Inertia, React, TS, Tailwind, tokens, layouts vacíos, Ziggy | `/` responde y compila sin errores de tipos |
| **F1** | `companies`, `stores`, `users`, spatie, `PermissionHelper`, `CheckPermission`, `TenantContext`, `StoreContext`, `HandleInertiaRequests`, login, logout, recuperación, `Errors/403` | Entra con `admin@lacanasta.co` y aterriza en un dashboard con su nombre, empresa y sede. Una ruta protegida sin permiso devuelve 403 |
| **F2** | Roles, usuarios, matriz de permisos, propagación, `UserPermissionService`, `RoleTemplateService` | Se crea un rol, se asigna, se edita y se propaga eligiendo usuarios; el cambio se refleja en el menú y en el servidor |
| **F3** | Empleados + cuentas de acceso (crear, activar, rol, contraseña, PIN) | Se crea empleado con acceso, se entra con esa cuenta y se le fuerza el cambio de contraseña |
| **F4** | Categorías, proveedores, productos (lista, ficha, formulario), imágenes, importación CSV | Se crea, edita y desactiva un artículo; los filtros y la paginación consultan el servidor |
| **F5** | Stock por sede, `StockService`, kárdex, ajustes, traslados, órdenes de compra y recepción | Recibir una compra sube el stock, escribe el kárdex y recalcula el costo promedio |
| **F6** | Cajas, apertura, `RegisterSessionService`, cierre con arqueo e informe Z en PDF | Se abre caja, se registra una sangría, se cierra y la diferencia calculada coincide con el conteo |
| **F7** | POS: catálogo táctil, ticket, atajos, cobro por los cuatro medios, recibo PDF 80 mm, anulación, suspender/recuperar | Una venta completa descuenta stock, deja movimiento y aparece en el dashboard |
| **F8** | Clientes, cupo, venta a crédito desde el POS, cartera, abonos, bloqueo por mora | Una venta a crédito sube el saldo; un abono lo baja y libera cupo; superar el cupo se rechaza con motivo |
| **F9** | Pedidos y domicilios: listado, detalle, alistamiento, domiciliario, estados, cobro contraentrega | Un pedido recorre los cinco estados y al entregar genera venta y movimiento de stock |
| **F10** | Tienda online: catálogo, producto, carrito, checkout (incluido pago a crédito), seguimiento, acceso del cliente | Un pedido creado en la tienda aparece en el panel y descuenta al entregarse |
| **F11** | Informes con exportación, ajustes, sedes, tarea programada de bloqueo por mora | El total del informe de ventas del día coincide con el del dashboard |

---

## 12. Pruebas mínimas (`php artisan test`)

Feature tests obligatorios, uno por regla crítica:

1. `LoginTest`: entra con correo, entra con documento, rechaza cuenta inactiva, bloquea tras 5 intentos.
2. `PermissionMiddlewareTest`: usuario sin permiso recibe 403 y queda el registro en `access_logs`.
3. `RolePropagationTest`: editar un rol no cambia a nadie hasta propagar; al propagar aplica solo la diferencia.
4. `EmployeeAccessTest`: crear acceso genera usuario con `password_change_required`.
5. `SaleTest`: venta en efectivo crea venta, pagos, descuenta stock y genera movimiento; los totales cuadran.
6. `CreditSaleTest`: venta a crédito respeta el cupo, actualiza el saldo y rechaza al cliente bloqueado.
7. `RegisterSessionTest`: no permite dos sesiones abiertas en la misma caja; el esperado y la diferencia cuadran.
8. `OrderFlowTest`: los saltos de estado inválidos se rechazan; entregar genera la venta.
9. `StorefrontCheckoutTest`: el checkout a crédito respeta el cupo disponible.

---

## 13. Definición de terminado

Una fase está terminada solo si:

- [ ] Migraciones, modelos, factories y seeders corren desde cero con `migrate:fresh --seed`.
- [ ] Cada ruta nueva tiene controlador, `FormRequest` y permiso, y responde sin errores.
- [ ] Cada pantalla recibe **todos** sus datos por props de Inertia; no queda ningún dato escrito a mano en el `.tsx`.
- [ ] Todo botón visible ejecuta algo real; lo que no está implementado no se pinta.
- [ ] Los permisos ocultan la acción en la interfaz **y** la bloquean en el servidor.
- [ ] `npm run build` y `tsc --noEmit` sin errores; `php artisan test` en verde.
- [ ] La pantalla se ve correcta en su ancho de referencia (1440 px panel, 1440×900 POS, 390 px tienda) y con
      el tema que le corresponde.

**Primera entrega mínima viable (F0–F7):** login → dashboard → catálogo → empleados y accesos → apertura de caja →
venta en el POS → cierre de caja. Con eso el sistema ya sirve en un mostrador real; el resto se suma encima.
