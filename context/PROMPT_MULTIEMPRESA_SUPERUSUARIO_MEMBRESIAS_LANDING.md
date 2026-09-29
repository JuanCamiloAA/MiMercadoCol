# PROMPT — Super usuario, multiempresa, membresías, pasarela, plazos de pago, carga masiva y landing de plataforma

> **Para:** Claude Opus, trabajando en `C:\xampp\htdocs\MiMercadoCol` (La Canasta — Laravel 12 + Inertia 2 + React 19 + TS estricto).
> **Proyecto de referencia (solo lectura):** `C:\xampp\htdocs\ERP_CONFECCION` (MiTallerCol). Ya resolvió casi todo esto; se lee para entender el cómo, **no se copia al pie de la letra** (ver §10).
> **Fecha:** 2026-09-25.

---

## 0. Cómo trabajar

1. **Lee primero** `MiMercadoCol/CLAUDE.md` y `MiMercadoCol/README.md`. Sus convenciones **mandan sobre** cualquier cosa que veas en ERP_CONFECCION:
   - COP entero en `bigInteger`.
   - `string` + constantes, nunca `enum` de MySQL.
   - `dateTime`, no `timestamp`.
   - Un FormRequest por acción.
   - `permission:<nombre>` en **toda** ruta.
   - Guard `web` explícito.
   - `$fillable` completo.
   - Migración de reparto para cada permiso nuevo.
   - Nada de arrays literales en `.tsx`.
   - Nada de botones sin acción.
2. **Verifica antes de afirmar.** Cada diagnóstico de §2 trae archivo y línea: ábrelos y confírmalos. Si algo ya cambió, adapta el plan y dilo.
3. **Trabaja por fases (§4 a §8).** No empieces una fase sin cerrar la anterior. Cada fase cierra con:

   ```bash
   npx tsc --noEmit
   npm run build
   php artisan test
   php artisan migrate:fresh --seed
   ```

   Además, una prueba manual del recorrido de aceptación de esa fase. Al final de cada fase reporta qué hiciste, qué probaste y qué quedó pendiente.
4. **No rompas lo que funciona.** Un usuario de empresa (no super usuario) debe ver y hacer exactamente lo mismo que hoy, salvo lo nuevo que se le suma: la pestaña Membresía, Plazos de pago y el registro de clientes en la tienda.
5. **Si una decisión no está cubierta aquí, pregunta** antes de inventarla.

---

## 1. Qué pidió el dueño del producto

1. **Super usuario completo.** Ve y gestiona la plataforma entera: empresas, planes de membresía, ciclos de cobro, pasarela de pagos, landing pública, solicitudes de contratación y carga masiva CSV.
2. **Selector de empresa arriba** («Todas las empresas» + la lista), como en ERP_CONFECCION. Con una empresa elegida, el super usuario puede hacer **todo** lo que hace el administrador de esa empresa, incluida su parametrización completa.
3. **Cada empresa sigue haciendo todo lo que hace hoy** y parametriza su organización.
4. **En Ajustes de la empresa, una sección Membresía:** plan, ciclo, fechas de inicio y vencimiento, días restantes, tarjeta guardada (marca, últimos 4, vencimiento, titular), renovación automática, pago y cobros anteriores.
5. **Landing pública en la tienda web.** El comprador elige la tienda más cercana, se registra como cliente de esa tienda y compra.
   - Autónoma respecto a las empresas: se alimenta sola de las que están activas.
   - Botón **«Ingresar como empresa»** que lleva al login actual.
   - Totalmente parametrizable, como el editor de landing de ERP_CONFECCION.

### Decisiones ya tomadas por el dueño

| # | Tema | Decisión |
|---|---|---|
| D1 | «Periodicidad de pagos» | **Las dos cosas.** (a) **Ciclos de cobro de la membresía**: catálogo de plataforma (mensual, trimestral, semestral, anual, con meses y % de descuento). (b) **Plazos de pago**: catálogo por empresa (contado, 8, 15, 30 días) para crédito a clientes y compras a proveedores. |
| D2 | Dueño de la landing | **Una sola landing de plataforma**, editada por el super usuario. Lista sola las sedes de todas las empresas activas y con tienda en línea. |
| D3 | Contratar la plataforma desde la landing | **Las dos vías, a elección de quien llena el formulario:** «Pagar ahora y activar» (checkout Wompi; la empresa se crea sola al aprobarse el pago) o «Quiero que me contacten» (queda una solicitud que el super usuario gestiona). |
| D4 | Membresía vencida | **Días de gracia configurables con aviso.** Después se bloquea el panel (solo queda Ajustes > Membresía para pagar) y la tienda en línea de esa empresa deja de vender. |

### Supuestos (aplícalos; el dueño puede cambiarlos)

| # | Supuesto |
|---|---|
| S1 | **«Todas las empresas»** muestra un **resumen de la plataforma** y los módulos de plataforma. Los módulos de empresa (caja, inventario, compras, ventas, ajustes…) piden elegir empresa con una pantalla intermedia. No hay listados consolidados en esta versión. |
| S2 | Con una empresa elegida, el super usuario es **administrador total** de esa empresa, **incluida la caja**. |
| S3 | **Clientes por empresa:** el mismo documento puede tener cuenta en dos empresas. El índice único ya es `company_id + document_number`. |
| S4 | Pasarela: **Wompi**, como en ERP. Credenciales por entorno (pruebas/producción), cifradas. |
| S5 | **El número de tarjeta y el CVC nunca tocan nuestro servidor.** Se tokenizan en el navegador contra Wompi. ERP_CONFECCION los recibe en el servidor; aquí **no** (§10). |
| S6 | Los cambios de plan que pide la empresa aplican en la **próxima renovación**. El super usuario sí puede aplicarlos de inmediato desde Empresas. |

---

## 2. Diagnóstico: por qué hoy no se ve nada

Confírmalo tú mismo. Rutas relativas a `MiMercadoCol/`.

| # | Hallazgo | Dónde | Consecuencia |
|---|---|---|---|
| 1 | No existe **ninguna** ruta `companies.*`. El enlace «Empresas» está en `resources/js/lib/navigation.ts` (grupo `company`), pero `AppLayout` filtra con `route().has(link.routeName)`. | `routes/web.php`, `navigation.ts:190` | El super usuario no ve Empresas. |
| 2 | `TenantContext` **ya** soporta empresa elegida por sesión (`SESSION_KEY = 'super_admin_active_company_id'`), pero **nada la escribe**: no hay endpoint ni selector, y `HandleInertiaRequests` no comparte `companiesForSelector`. | `app/Support/TenantContext.php`, `HandleInertiaRequests.php` | El super usuario vive en «consolidado» para siempre. |
| 3 | En consolidado: `DashboardController` devuelve `data: null`; `SettingController::index` llama `requireCompanyId()` y revienta con 422. | `DashboardController.php:18-23`, `SettingController.php` | Inicio vacío y Ajustes inservible para el super usuario. |
| 4 | `StoreContext::availableStores()` para super usuario en consolidado devuelve **las sedes de todas las empresas mezcladas**. | `StoreContext.php` (rama `isSuperAdmin`) | El selector de sede cruza empresas. |
| 5 | Se lee la empresa desde el usuario (`$request->user()->company`), que para el super usuario es `null`. | `Pos/SaleController.php:355,367`, `ProductController.php:367`, `Registers/ClosingController.php:112`, `ReportController.php:99`, `SaleController.php:191` | Tiquetes, cierres, etiquetas e informes sin empresa. |
| 6 | La política de importación compara `user->company_id`. | `Policies/CatalogImportPolicy.php:53` | El super usuario no puede ver importaciones de la empresa elegida. |
| 7 | La tienda en línea es de **una sola empresa**: `StorefrontContext::company()` toma **la primera empresa activa**, y el ingreso de clientes filtra por esa empresa. | `Support/StorefrontContext.php`, `Storefront/CustomerAuthController.php` | Una segunda empresa nunca vende en línea. |
| 8 | **El carrito cruza empresas.** `CartService::current()` mueve el carrito a la sede nueva (`$cart->update(['store_id' => ...])`) sin mirar la empresa. | `app/Services/CartService.php` (final de `current`) | Con varias empresas, un carrito con artículos de A termina en una sede de B y el pedido se crea en B con artículos de A. **Hay que corregirlo.** |
| 9 | No hay **registro** de clientes en la tienda: solo ingreso. | `routes/storefront.php` | El comprador no puede crear su cuenta. |
| 10 | Las sedes no tienen ciudad ni coordenadas, y las empresas no tienen `slug`. | migraciones de `stores` y `companies` | No se puede buscar «la más cercana» ni armar URL de tienda. |
| 11 | No existe nada de membresías, ciclos, pasarela, solicitudes, landing de plataforma, plazos de pago ni carga masiva de plataforma. | — | Todo se construye. |
| 12 | Lo que **sí** existe y se reutiliza: `EnsureSuperAdmin` (alias `super.admin`), `ChannelAccess` (ya contempla al super usuario), `PermissionHelper` con módulos `super_admin_only` (`companies` con `view/create/edit/delete/switch`), `StoreDefaultRolesService::createFor()`, el gancho `Store::created` (crea la «Bodega principal»), `StorefrontManager::for($store)`, `ImportRegistry` + `MasterImport` (reciben `companyId` explícito), `BrandThemeService`, `HomeResolver`, `ImageService`. | — | No los dupliques. |

---

## 3. Mapa de lo que se construye

```
Plataforma (solo super usuario)                     Empresa (su admin, o el super usuario con la empresa elegida)
────────────────────────────────                    ─────────────────────────────────────────────
Selector de empresa (barra superior)                Todo lo de hoy, igual
Resumen de plataforma (Inicio en consolidado)       Ajustes > Empresa (datos legales, editable)
Empresas (CRUD + aprovisionamiento + «Entrar»)      Ajustes > Membresía (plan, fechas, tarjeta, pagos)
Solicitudes de contratación (leads)                 Plazos de pago (catálogo)
Planes de membresía                                 Sedes: ciudad + coordenadas
Ciclos de cobro
Pasarela de pagos (Wompi)                           Público (sin sesión)
Landing pública (editor por bloques + versiones)    ─────────────────────
Carga masiva (CSV)                                  /  Landing de plataforma + buscador de sede cercana
                                                    /contratar  (pagar ahora | que me contacten)
Procesos                                            /tienda/{empresa}/{tienda}  → tienda de esa sede
────────                                            /tienda/registro  (cliente de esa empresa)
membership:process (diario)                         /webhooks/wompi
```

Menú (`navigation.ts`): sección nueva **`platform` — «Plataforma»**, visible solo para super usuario, con:

- Empresas (`companies.index`, **se mueve aquí** desde Administración > Empresa)
- Solicitudes
- Planes de membresía
- Ciclos de cobro
- Pasarela de pagos
- Landing pública
- Carga masiva (CSV)

En Administración > Empresa se suma **Plazos de pago**.

---

## 4. Fase 1 — Super usuario y multiempresa

### 4.1 Selector de empresa

- **Ruta:** `POST /plataforma/empresa-activa` → `Platform\ActiveCompanyController@store`, nombre `platform.active-company`.
  - Middleware: `super.admin`, `permission:companies.index.switch` (ya existe) y `throttle:60,1`.
- **Petición:** `company_id` nullable, `exists:companies,id`.
  - Se permite elegir empresas **inactivas o suspendidas**: el super usuario tiene que poder entrar a arreglarlas. La lista las marca con un distintivo.
- **Al cambiar:**
  - Guarda o borra `TenantContext::SESSION_KEY`.
  - **Olvida `StoreContext::SESSION_KEY`**, porque la sede de la otra empresa ya no vale.
  - Redirige a la misma pantalla si es un listado (ruta sin parámetros de modelo). Si la ruta tenía un modelo de la empresa anterior (`/productos/15/editar`), redirige al listado del módulo o a `HomeResolver::for($user)`. Nunca debe quedar un 404 por `CompanyScope`.
- **Props compartidas** en `HandleInertiaRequests`, solo si es super usuario (lazy):
  - `companiesForSelector`: `[{id, name, is_active, membership_status}]`
  - `activeCompanyId`
  - `isConsolidatedView`
- **Componente** `resources/js/components/layout/CompanySwitcher.tsx` en la barra superior de `AppLayout`, a la izquierda del selector de sede, y en el menú móvil.
  - Opción «Todas las empresas» (id `null`) + lista.
  - Buscador cuando hay más de 8 empresas.
  - Íconos Phosphor importados uno por uno (`BuildingsIcon`, `CaretUpDownIcon`); regla de `CLAUDE.md` sobre `import *`.
  - Solo tokens `var(--…)`; nada de colores fijos.
  - Referencia visual: `ERP_CONFECCION/resources/js/Components/SuperAdminCompanySwitcher.tsx`.
- **En la caja (`PosLayout`)** solo se **muestra** la empresa, sin selector: cambiar de empresa a mitad de una venta es un error esperando pasar.
- **La marca sigue a la empresa elegida.** `BrandThemeService` ya recibe `activeCompanyId`; en consolidado usa la **marca de plataforma** (§8.4).

### 4.2 Modo «Todas las empresas» (S1)

- **Middleware nuevo** `EnsureCompanySelected` (alias `company.selected`). Si el usuario es super usuario y `TenantContext::activeCompanyId() === null`, responde `Inertia::render('Platform/ChooseCompany', …)`: lista de empresas para elegir, conservando la URL de destino para volver después. Al resto de usuarios los deja pasar.
  - Va en **todos** los grupos de rutas de empresa.
  - **No** va en: rutas de plataforma, `dashboard`, cambio de contraseña, salida, `platform.active-company`.
- **`dashboard` en consolidado** → `Dashboard/PlatformOverview` con datos reales:
  - Empresas por estado de membresía (activa, en prueba, en gracia, suspendida).
  - Vencen en 7 días.
  - Ingreso recurrente mensual (suma del precio mensual equivalente de las activas).
  - Cobros del mes (aprobados y rechazados).
  - Solicitudes nuevas.
  - Ventas de hoy por empresa (`sales` sin `CompanyScope`, agrupado por `company_id`, con `SalePayment::NET_AMOUNT_SQL` si tocas pagos).
  - Con empresa elegida → el dashboard de hoy.
- `StoreContext::availableStores()`: en consolidado devuelve **vacío** y el selector de sede no se pinta.
- `HomeResolver`: el super usuario siempre cae en `dashboard`.

### 4.3 Correcciones de «¿de qué empresa hablamos?»

- Nuevo `TenantContext::company(): ?Company`, el modelo de la empresa activa, en caché por petición.
- Reemplaza `$request->user()->company` / `auth()->user()->company` por `TenantContext::company()` en los 6 lugares de §2.5.
- `CatalogImportPolicy:53` → compara con `TenantContext::activeCompanyId()`.
- **Barrido obligatorio.** Busca y revisa uno por uno: `user()->company`, `->company_id` leído del usuario autenticado, `Company::query()->first()`, `StorefrontContext::company()` y `withoutGlobalScopes()` en controladores de empresa. Todo lo que resuelva la empresa desde el usuario o «la primera empresa» se cambia.
- **El super usuario en la caja (S2):** verifica que abrir caja, vender (incluido cobro combinado y crédito), suspender, retomar, devolver y cerrar funcionen con un usuario **sin ficha de empleado y sin sedes asignadas**. Si algo exige `employee`, que el vendedor por defecto sea el usuario. Queda como prueba de aceptación.
- `requireCompanyId()` ya bloquea escrituras en consolidado; con el middleware no se llega ahí, pero se deja como red.

### 4.4 Empresas (CRUD + aprovisionamiento)

**Rutas** (prefijo `/plataforma/empresas`). Se conservan los **nombres** `companies.*` para que el enlace del menú funcione. Middleware `super.admin` + permiso del catálogo existente:

| Método | URI | Acción | Permiso |
|---|---|---|---|
| GET | `/plataforma/empresas` | index | `companies.index.view` |
| GET | `/plataforma/empresas/exportar` | export CSV de lo filtrado | `companies.index.view` |
| GET | `/plataforma/empresas/crear` | create | `companies.index.create` |
| POST | `/plataforma/empresas` | store | `companies.index.create` |
| GET | `/plataforma/empresas/{company}/editar` | edit | `companies.index.edit` |
| PUT | `/plataforma/empresas/{company}` | update | `companies.index.edit` |
| POST | `/plataforma/empresas/{company}/estado` | activar o desactivar (con motivo) | `companies.index.delete` |
| POST | `/plataforma/empresas/{company}/entrar` | elige la empresa y va a su inicio | `companies.index.switch` |

Rutas fijas (`exportar`, `crear`) **antes** de las que llevan `{company}`.

**Migración `companies`:** agrega las columnas y súmalas a `$fillable`.

| Columna | Tipo | Nota |
|---|---|---|
| `slug` | `string(80)` único | URL de la tienda (`/tienda/{slug}/…`); se genera del nombre |
| `city` | `string(80)` nullable | — |
| `membership_plan_id` | FK nullable | — |
| `billing_cycle_id` | FK nullable | — |
| `membership_status` | `string(20)` | Constantes `Company::MEMBERSHIP_TRIAL/ACTIVE/GRACE/SUSPENDED/CANCELLED` |
| `membership_started_at`, `membership_ends_at`, `grace_ends_at`, `next_charge_at` | `dateTime` nullable | — |
| `auto_renew` | bool, defecto `false` | — |
| `deactivated_at` | `dateTime` nullable | — |
| `deactivation_reason` | `string` nullable | — |
| `signup_id` | FK nullable a `company_signups` | Rastro de la contratación en línea |

Rellena `slug` para las empresas existentes dentro de la migración y pon `membership_status = active` sin fecha de fin a las que ya existen, para no bloquear a nadie al desplegar.

**Listado** (referencia: `ERP_CONFECCION/app/Http/Controllers/CompanyController.php` y `Pages/Companies/Index.tsx`):

- Buscador; filtros por estado de membresía y plan; chips con conteos; orden por nombre o vencimiento.
- Columnas: empresa (nombre + NIT), plan y ciclo, estado (distintivo), vence, sedes usadas/tope, usuarios usados/tope.
- Acciones: **Entrar**, Editar, Activar/Desactivar.
- Exportar CSV de lo filtrado.

**Crear = aprovisionamiento completo**: `App\Services\Platform\CompanyProvisioner::provision(array $data): Company`, **en una transacción**. Lo usan tres caminos: el formulario de Empresas, la contratación pagada (§8.5) y la carga masiva de empresas (§7).

1. Empresa (datos legales + `slug` único).
2. Roles por defecto: `StoreDefaultRolesService::createFor($company)`.
3. Primera sede (nombre, código, dirección, ciudad, coordenadas). El gancho `Store::created` crea la «Bodega principal»: **no la crees a mano** (trampa de `CLAUDE.md`).
4. Una caja «Caja 1».
5. Tienda en línea de la sede con `StorefrontManager::for($store)`, **apagada** hasta que la empresa ponga precios y publique.
6. Ajustes: las mismas filas de reglas que siembra `CompanySeeder` (el resto vive en `SettingsService::DEFAULTS`).
7. Plazos de pago por defecto (§6).
8. Usuario administrador:
   - Nombre, correo, documento.
   - Contraseña temporal con cambio obligatorio; muéstrala con el flash `temporary_password`, como hace hoy Usuarios.
   - Rol `admin` y **sus permisos directos** vía `UserPermissionService`: el rol es plantilla, mandan los directos.
9. Membresía:
   - Plan y ciclo; `membership_started_at = ahora`.
   - Si el plan tiene prueba: `membership_status = trial` y `membership_ends_at = ahora + trial_days`.
   - Si no: `active` y `ends_at = ahora + meses del ciclo`.
   - Evento en la bitácora (§5.1).

**Editar:** datos legales, plan, ciclo, fechas y estado a mano. Todo cambio de membresía **exige motivo** y deja evento en la bitácora. El logo **no** se edita aquí: la marca vive en Ajustes > Marca (una sola fuente).

**Desactivar** es `is_active = false` + `deactivated_at` + motivo; no se borra nada. `EnsureUserBelongsToCompany` y `LoginRequest` ya expulsan a los usuarios de una empresa inactiva.

### 4.5 Ajustes de la empresa

- **Pestaña nueva «Empresa»**, editable con `settings.index.edit`: nombre, razón social, NIT, correo, teléfono, dirección, ciudad. Hoy es de solo lectura.
- La pestaña «Membresía» se hace en la Fase 2.
- Con la empresa elegida, el super usuario ve y edita **todas** las pestañas. En consolidado, Ajustes pasa por `company.selected`.

### 4.6 Menú

- `NavLink` gana `superAdminOnly?: true`. `AppLayout` lo oculta si `!user.is_super_admin`, además del filtro por permiso.
- Sección `platform` al final del menú.
- **Las personalizaciones de menú de una empresa (`panel.navigation`) no aplican a `platform`:** excluye esa sección de `applyNavigationOverrides`.

### 4.7 Aceptación de la Fase 1

- [ ] Como super usuario veo el selector con «Todas las empresas» + las empresas, y la sección Plataforma.
- [ ] En «Todas», Inicio muestra el resumen; abrir Artículos me lleva a «Elige una empresa» y, al elegir, llego a Artículos de esa empresa.
- [ ] Con la empresa B elegida, creo un artículo y queda con `company_id = B`. Cambio ajustes y quedan en B. Abro caja, vendo, imprimo el tiquete con los datos de B y cierro caja.
- [ ] Al cambiar de B a A, el selector de sede muestra solo sedes de A y la pantalla no queda en 404.
- [ ] Creo una empresa nueva desde Empresas: tiene roles, sede con bodega principal, caja, tienda (apagada), admin con contraseña temporal y membresía. Ese admin entra, cambia la contraseña y ve solo su empresa.
- [ ] Un usuario de La Canasta no ve el selector ni la sección Plataforma, y su operación es idéntica a hoy.

---

## 5. Fase 2 — Membresías, ciclos, pasarela y cobro

### 5.1 Modelo de datos

Convenciones: COP `bigInteger`, estados en `string` + constantes, fechas `dateTime`.

**`billing_cycles`** (plataforma)

| Columna | Tipo |
|---|---|
| `name` | string (Mensual) |
| `code` | string único (`monthly`) |
| `months` | unsignedTinyInteger (1, 3, 6, 12) |
| `discount_percent` | unsignedTinyInteger (0–90) |
| `is_active` | bool |
| `sort_order` | unsignedSmallInteger |

**`membership_plans`** (plataforma)

| Columna | Tipo | Nota |
|---|---|---|
| `name`, `slug` único, `description` | string/text | — |
| `monthly_price` | bigInteger | COP |
| `trial_days` | unsignedSmallInteger | 0 = sin prueba |
| `max_stores`, `max_users`, `max_registers` | unsignedInteger nullable | null = sin tope |
| `features` | json | Claves de `MembershipFeatures::CATALOGUE` |
| `is_public` | bool | Sale en la landing |
| `is_featured` | bool | «Más elegido» |
| `is_active`, `sort_order` | — | — |

**Precio de un plan en un ciclo**: una sola fuente, `MembershipPricing::priceFor(Plan, Cycle): int`:

```
monthly_price × months × (100 − discount_percent) / 100
```

redondeado **hacia arriba a mil pesos**. Nadie más calcula precios: ni el `.tsx`, ni la landing, ni el cobro.

**`MembershipFeatures::CATALOGUE`** — solo lo que **de verdad se hace cumplir**:

| Clave | Qué habilita | Dónde se hace cumplir |
|---|---|---|
| `online_store` | Tienda en línea y editor | Rutas `storefront.editor.*`, `storefront.catalog.*` y visibilidad en la landing |
| `promotions` | Promociones y cupones | Rutas `promotions.*`, `coupons.*` y `PromotionService` |
| `multi_warehouse` | Más de una bodega por sede | `WarehouseController@store` |
| `catalog_import` | Importar de la fuente externa | Rutas `catalog.import.*` |

Una función del catálogo que ninguna ruta revisa **no se crea** (misma regla que los permisos).

**`company_payment_methods`**: una activa por empresa.

| Columna | Nota |
|---|---|
| `company_id` único | — |
| `provider` | `wompi` |
| `payment_source_id` | La fuente de pago de Wompi |
| `brand`, `last4`, `exp_month`, `exp_year`, `holder_name`, `customer_email` | — |
| `status` | string + constantes: `active`, `expired`, `failed` |

**Jamás** número completo, CVC ni token de tarjeta de un solo uso.

**`membership_charges`**

| Columna | Nota |
|---|---|
| `company_id`, `membership_plan_id`, `billing_cycle_id` | — |
| `amount` | bigInteger |
| `currency` | `COP` |
| `concept` | — |
| `period_starts_at`, `period_ends_at` | dateTime |
| `status` | string + constantes: `pending`, `approved`, `declined`, `error`, `voided` |
| `method` | `saved_card`, `checkout`, `manual` |
| `reference` | único, prefijo `MEM-` |
| `gateway_transaction_id`, `attempts`, `failure_reason` | — |
| `charged_at`, `paid_at` | dateTime |
| `created_by_user_id` | null = automático |

Índice `(company_id, charged_at)`.

**`company_membership_events`** (bitácora)

- Columnas: `company_id`, `type` (string + constantes), `data` json, `user_id` nullable, `created_at`.
- Tipos: `plan_changed`, `cycle_changed`, `renewed`, `grace_started`, `suspended`, `reactivated`, `manual_extension`, `card_updated`, `auto_renew_toggled`, `deactivated`.

**`payment_gateway_settings` y `payment_gateway_credentials`**

- Porta la estructura de ERP (`2026_09_03_110000_*` y `2026_09_03_120000_*`): credenciales **por entorno** (`sandbox`, `production`) con cast `encrypted`, `is_enabled` apagado por defecto.

**`platform_settings`** (clave-valor sin empresa) + `App\Services\PlatformSettingsService` con `DEFAULTS`, espejo de `SettingsService`:

| Clave | Defecto |
|---|---|
| `membership.grace_days` | 5 |
| `membership.reminder_days` | [7, 3, 1] |
| `platform.name` | — |
| `platform.support_email` | — |
| `platform.leads_notify_email` | — |
| Marca de plataforma (§8.4) | — |

### 5.2 Ciclo de vida (`App\Services\Membership\MembershipService`)

```
trial ──(pago aprobado)──▶ active ──(llega ends_at sin renovar)──▶ grace ──(llega grace_ends_at)──▶ suspended
  │                          ▲                                        │                               │
  └──(vence la prueba)──▶ grace                                       └─────(pago aprobado)────────────┴──▶ active
cualquiera ──(super usuario)──▶ cancelled
```

- **Renovar** (pago aprobado): si la empresa está al día, el nuevo periodo arranca en el `ends_at` actual; si venía de gracia o suspendida, arranca hoy. Luego `ends_at = inicio + meses del ciclo`, `next_charge_at = ends_at` si `auto_renew`, se limpia `grace_ends_at` y se registran evento y cobro. **Idempotente por `reference`.**
- **Comando** `membership:process`, programado a diario a las 03:00 con `withoutOverlapping()` (referencia: `ERP_CONFECCION/routes/console.php` y `ProcessMembershipAutoDebits.php`):
  1. Cobra a quienes tienen `auto_renew` y `next_charge_at <= ahora`. **Primero crea la fila `pending` y después llama a la pasarela.** Al revés, un cobro exitoso seguido de una caída dejaría a la empresa cobrada sin rastro.
  2. `active`/`trial` con `ends_at` pasado → `grace`, con `grace_ends_at = ends_at + grace_days`.
  3. `grace` con `grace_ends_at` pasado → `suspended`.
  4. Recordatorios por correo según `reminder_days`, solo si el correo está configurado; no falla si no lo está.
  5. Tarjeta vencida antes del próximo cobro → aviso.
  6. Opción `--dry-run`.

### 5.3 Hacer cumplir la membresía

- **Middleware** `EnsureMembershipActive` (alias `membership.active`), después de `verified.company`, en todas las rutas de empresa. Con la empresa en `suspended`:
  - Permite solo `settings.index` (pestaña Membresía), las rutas de pago de la membresía, `logout` y cambio de contraseña.
  - Todo lo demás redirige a `settings.index` con `?pestana=membresia` y un flash que explica por qué.
  - Peticiones JSON → 402 con mensaje.
  - El super usuario lo salta, pero ve el aviso.
- **Aviso compartido:** prop `membershipNotice` en `HandleInertiaRequests` para `grace`, `trial` a menos de 7 días y `suspended`. `AppLayout` pinta una franja con el enlace «Pagar ahora».
- **Tienda en línea:** una empresa `suspended` desaparece de la landing. Sus rutas `storefront.*` responden una página «Esta tienda no está disponible por ahora» y el checkout se bloquea **en el servidor**. En `grace` sigue vendiendo.
- **Topes del plan:** `App\Services\Membership\MembershipLimiter::assertCanAdd(Company, 'stores'|'users'|'registers')`, con excepción de validación en español: «Tu plan permite 3 sedes. Cámbialo en Ajustes > Membresía.». Se llama en:
  - `StoreController@store`, `UserController@store`, `RegisterController@store`.
  - `StoreImport`, `RegisterImport` y cualquier importación que cree usuarios.
  - `CompanyProvisioner`.
  - Referencia: `ERP_CONFECCION/app/Services/Membership/*Limiter.php`.

### 5.4 Ajustes > Membresía (empresa)

Pestaña nueva en `pages/Settings/`. Permisos nuevos en `PermissionHelper`, página `settings.membership`:

- `view`: ver la pestaña.
- `manage_payment`: tarjeta, renovación automática, pagar, cambiar plan.

Van **aparte** de `settings.index.edit`, como en ERP: quien cambia el redondeo no tiene por qué cambiar la tarjeta con la que se cobra la empresa. Migración de reparto a los roles `super_admin`/`admin` y a quien hoy tenga `settings.index.edit` (patrón `2025_01_01_000800_grant_sales_history_permission.php`).

**Contenido** (referencia: `ERP_CONFECCION/app/Http/Controllers/SettingController.php::membershipPayload` y `context/PROMPT_REDISENO_MI_EMPRESA_MEMBRESIA_PAGOS.md`):

1. **Tu plan:**
   - Nombre, ciclo, precio del ciclo (de `MembershipPricing`) y funciones incluidas.
   - Estado con distintivo.
   - Inicio, vencimiento, **días restantes** (negativo = vencida), en gracia hasta, próxima renovación.
2. **Uso:** sedes, usuarios y cajas contra el tope, con barra.
3. **Tarjeta:**
   - Marca, `•••• 1234`, vence MM/AA, titular.
   - Aviso si vence antes de la próxima renovación.
   - Botón **Cambiar tarjeta** (flujo en §5.5).
4. **Renovación automática:** interruptor. No se activa sin tarjeta: el error se dice al activarlo, no el día del cobro.
5. **Pagar ahora:** visible en prueba, gracia o suspendida, o para adelantar un periodo.
   - Opción A: «Con mi tarjeta guardada», cobro inmediato a la fuente de pago.
   - Opción B: «Con otro medio (PSE, Nequi, tarjeta)», que abre el checkout web de Wompi. El webhook liquida.
6. **Cambiar plan o ciclo:** aplica en la próxima renovación (S6); queda anotado y visible como «Cambiará a Plan X el 12/10/2026».
7. **Historial de cobros:** fecha, concepto, periodo, monto (`money()`), estado, referencia y **comprobante PDF** (dompdf, que ya está instalado).

**Sin pasarela habilitada:** no se pintan los botones que la usan. Se muestra el estado y el texto «El pago en línea no está disponible; comunícate con soporte (correo de plataforma)». Nada de botones deshabilitados (regla del proyecto).

### 5.5 Tarjeta sin tocar el número (S5)

Flujo con Wompi. **Verifica cada endpoint, parámetro y campo contra la documentación vigente de Wompi antes de escribir código**, y usa sus tarjetas de prueba en sandbox:

1. El servidor entrega al formulario la **llave pública** del entorno activo y los **tokens de aceptación** (términos y tratamiento de datos) que Wompi expone para el comercio. Muestra los enlaces a esos textos y pide la aceptación explícita.
2. **El navegador** tokeniza la tarjeta directamente contra Wompi con la llave pública (número, CVC, mes, año, titular). **Nuestro servidor nunca recibe ni el número ni el CVC.**
3. El navegador envía a `PUT /ajustes/membresia/tarjeta` **solo**: el token de un solo uso y los tokens de aceptación aceptados.
4. El servidor, con la llave privada, crea la **fuente de pago** (tarjeta, token, correo, aceptación). Guarda `payment_source_id` y los datos visibles **que devuelve Wompi** (marca, últimos 4, vencimiento). No confíes en marca ni últimos 4 que mande el navegador.
5. Cobros recurrentes: una transacción contra `payment_source_id` con firma de integridad y `reference` `MEM-…`. El resultado final siempre se confirma **releyendo la transacción** y por webhook.
6. El FormRequest de la tarjeta **rechaza** la petición si trae `card_number`, `number` o `cvc`: prueba automática incluida.

### 5.6 Pasarela (super usuario)

`/plataforma/pasarela`. Porta `ERP_CONFECCION/app/Http/Controllers/SuperAdmin/PaymentGatewayController.php` y `Pages/SuperAdmin/PaymentGateway/Index.tsx`:

- Entorno activo, interruptor «Cobros habilitados» y credenciales **por entorno**:
  - Llave pública.
  - Llave privada, secreto de eventos y secreto de integridad: se muestran enmascarados (`••••1a2b`); un campo vacío al guardar **conserva** el valor.
- No se habilita sin las cuatro credenciales del entorno elegido.
- **Probar conexión:** consulta real a Wompi con la llave pública y muestra el resultado.
- Muestra la URL del webhook para copiarla.
- `App\Services\Payments\WompiService`: porta `integritySignature`, `checkoutUrl`, `verifyEventSignature` y `fetchTransaction` de ERP. Suma `acceptanceTokens()`, `createPaymentSource()` y `chargePaymentSource()`. En pruebas, `Http::fake()`.

### 5.7 Webhook

- Ruta: `POST /webhooks/wompi`, pública, **fuera de CSRF** (en `bootstrap/app.php`), `throttle:120,1`. Referencia: `ERP_CONFECCION/app/Http/Controllers/WompiWebhookController.php`.
- Pasos:
  1. Firma inválida → 401 y log.
  2. Solo `transaction.updated`.
  3. **Relee la transacción en la API** (la firma prueba quién envía, no que el cuerpo sea la última verdad).
  4. Enruta por prefijo de `reference`: `SIG-` → contratación (§8.5); `MEM-` → cobro de membresía.
  5. **Idempotente:** un evento repetido no renueva dos veces ni crea dos empresas.
  6. Monto y moneda deben coincidir con lo registrado; si no, se marca `error` y se avisa.

### 5.8 Planes y ciclos (super usuario)

- `/plataforma/planes`: CRUD. Muestra precio por cada ciclo activo (calculado), topes, funciones y cuántas empresas lo usan. Desactivar en vez de borrar si tiene empresas. Referencia: `Pages/SuperAdmin/MembershipPlans/*`.
- `/plataforma/ciclos`: CRUD de ciclos (nombre, meses, % descuento, orden, activo). No se desactiva el último ciclo activo.

### 5.9 Aceptación de la Fase 2

- [ ] Con `Carbon::setTestNow`, una empresa pasa `active → grace → suspended`. Suspendida, su admin solo llega a Membresía; la tienda deja de vender; el super usuario sigue entrando.
- [ ] En sandbox guardo una tarjeta de prueba y en la base solo quedan `payment_source_id`, marca, últimos 4, vencimiento y titular. Ningún log contiene el número.
- [ ] «Pagar ahora» con la tarjeta guardada renueva el periodo, crea el cobro `approved` y el comprobante PDF. El webhook repetido no renueva dos veces.
- [ ] El tope de sedes del plan impide crear la cuarta con el mensaje indicado.
- [ ] Sin pasarela habilitada no aparece ningún botón de pago.

---

## 6. Fase 3 — Plazos de pago (empresa)

- **Tabla `payment_terms`** con `BelongsToCompany` + `CompanyScope`:

  | Columna | Tipo |
  |---|---|
  | `name` | string |
  | `days` | unsignedSmallInteger |
  | `is_default_customers` | bool |
  | `is_default_suppliers` | bool |
  | `is_active` | bool |
  | `sort_order` | unsignedSmallInteger |

  Solo uno por defecto de cada tipo por empresa; lo hace cumplir el servicio en una transacción.
- **Semilla por empresa**, en `CompanyProvisioner` y en migración para las existentes: Contado (0), 8, 15 y 30 días.
- **Pantalla:** Administración > Empresa > **Plazos de pago**, `payment_terms.index` con permisos `view/create/edit/deactivate` + migración de reparto.
- **Uso real** (si no se usa en estos tres lugares, no se hace):
  1. **Crédito de clientes** (`pages/Credit/Index.tsx`): el número suelto de días se vuelve un selector de plazos.
     - Se guarda `customers.payment_term_id`.
     - `credit_term_days` se conserva, desnormalizado e igual a `days`, porque lo leen el cálculo de mora y la tarea de bloqueo. Revisa quién lo usa antes de tocarlo.
  2. **Proveedores:** plazo por defecto (`suppliers.payment_term_id`).
  3. **Órdenes de compra:**
     - Plazo tomado del proveedor y editable.
     - `due_at` (`dateTime`) = fecha de recepción total + días.
     - Se ve «Vence» en el listado y el detalle de compras.
- Aceptación: crear «45 días», asignarlo a un proveedor, recibir una orden y ver su vencimiento; el cliente con plazo de 15 días se bloquea según la regla de mora de siempre.

---

## 7. Fase 4 — Carga masiva (CSV) de plataforma

- **Ruta:** `/plataforma/carga-masiva`, solo super usuario. Referencia funcional: `ERP_CONFECCION/app/Http/Controllers/SuperAdmin/DataImportController.php`, `Pages/SuperAdmin/DataImports/*` y `context/PROMPT_AJUSTE_SUPERADMIN_IMPORTACION_CSV.md`.
- **Regla de `CLAUDE.md`: la pantalla y el lector de CSV ya son compartidos; no escribas otro.** Reutiliza `ImportRegistry` / `MasterImport`, que ya reciben `companyId` explícito (`ImportController@store` → `$this->imports->run($import, $path, $companyId)`).
- **Pasos:**
  1. Tipo:
     - `companies` (nuevo), que crea empresas **vía `CompanyProvisioner`** fila por fila: nombre, NIT, correo, ciudad, plan, ciclo, primera sede y correo del admin.
     - O cualquiera de `ImportRegistry::IMPORTS` (artículos, categorías, proveedores, clientes, empleados, sedes, cajas).
  2. **Empresa destino:** selector propio del formulario. **No** depende del selector de la barra superior. Obligatorio salvo para `companies`.
  3. Archivo → vista previa (la de `MasterImport`) → procesar.
- **Bitácora `platform_import_batches`:**

  | Columna | Nota |
  |---|---|
  | `user_id`, `company_id` nullable, `type` | — |
  | `original_filename`, `stored_path` | — |
  | `status` | string + constantes |
  | `rows_total`, `rows_success`, `rows_failed` | — |
  | `error_report_path` | — |
  | `started_at`, `finished_at` | dateTime |

  Listado con historial, descarga de errores en CSV y del archivo original.
- **Plantillas:** CSV por tipo (encabezados + fila de ejemplo) y ZIP con todas.
- **Topes del plan:** se respetan (`MembershipLimiter`); las filas que los excedan fallan con motivo.
- Aceptación: con la empresa A elegida arriba, importo artículos **para B** y quedan en B. Importo 3 empresas y cada una queda aprovisionada completa.

---

## 8. Fase 5 — Landing de plataforma, tienda multiempresa y contratación

### 8.1 Rutas públicas

| Método | URI | Qué hace |
|---|---|---|
| GET | `/` | Landing publicada. Hoy `/` redirige al login: **cambia**. Con sesión de personal, el encabezado muestra «Ir al panel» en vez de «Ingresar como empresa». |
| POST | `/sedes-cercanas` | `{lat, lng}` → sedes ordenadas por distancia (Haversine en SQL o PHP). **No se guarda la ubicación.** `throttle:30,1`. |
| GET | `/tienda/{companySlug}/{storefrontSlug}` | Fija la sede en `StorefrontContext` y redirige a `storefront.home`. Es el enlace compartible de cada tienda. |
| GET/POST | `/tienda/registro` | Registro de cliente (§8.3). |
| GET/POST | `/contratar` | Contratación (§8.5). |
| GET | `/contratar/estado/{reference}` | Vuelta del checkout. **Fuera** de `guest`, como en ERP (`routes/auth.php`). |
| POST | `/contratar/solicitud` | «Quiero que me contacten». `throttle:10,1` + campo trampa anti‑spam. |

Declara estas rutas **antes** de `require __DIR__.'/storefront.php'` si alguna choca con el prefijo `tienda`, y comprueba con `php artisan route:list` que `{companySlug}` no se trague `registro`, `ingresar`, `carrito`, `pago`…

**Opción recomendada:** declara `/tienda/registro` y demás rutas fijas **antes** de `/tienda/{companySlug}/{storefrontSlug}`. Con dos segmentos ya no choca con `producto/{product}` ni `pedido/{order}`, pero verifícalo.

### 8.2 Tienda multiempresa

- **Sedes:** migración con `city` (`string(80)`), `latitude` y `longitude` (`decimal(10,7)` nullable).
  - Se editan en Sedes y bodegas: campos de coordenadas + botón «Usar mi ubicación actual» (`navigator.geolocation`, solo al hacer clic) + enlace «Ver en el mapa».
  - `StoreImport` suma las columnas.
- **`StorefrontContext` se reescribe:**
  - La empresa se **deriva de la sede elegida**; nunca más «la primera empresa activa».
  - Sin sede en sesión, `storefront.home` redirige a `/` (el buscador) en lugar de elegir una al azar.
  - `stores()` (el selector interno de la tienda) devuelve solo sedes **de la misma empresa**.
  - `setStore()` acepta cualquier sede vendible de cualquier empresa. Vendible = empresa activa, no `suspended`, con `online_store` en el plan, `storefronts.is_online` y versión publicada.
- **Al cambiar a una sede de otra empresa:**
  - Se cierra la sesión del guard `customer` (el cliente es de la empresa anterior).
  - Se olvidan cupón y zona.
  - Se usa **otro carrito**.
- **`CartService::current()` — corregir el hallazgo 8:**
  - Si `$cart->company_id !== $store->company_id`, no se mueve: se crea o recupera un carrito de esa empresa.
  - Los tokens por empresa se guardan en la sesión (`storefront.cart_tokens[company_id]`), para que al volver a la empresa A el comprador encuentre su carrito.
  - Mover entre sedes de la **misma** empresa sigue como hoy.
- **`customer.auth`:** además de sesión iniciada, exige `customer.company_id === StorefrontContext::company()->id`.
- **`CustomerAuthController@store`:** la empresa sale de la sede elegida.
- **`StoreLayout`:**
  - Enlace «Cambiar de tienda» → `/#tiendas`.
  - Nombre de la empresa desde su tema publicado (`storeTheme.display_name`) o `companies.name`. **Quita el literal «La Canasta»** de `StoreLayout.tsx:58`.
- **Empresa `suspended` o sin la función `online_store`:** página «Tienda no disponible» en todas sus rutas `storefront.*` y checkout bloqueado en el servidor.

### 8.3 Registro de clientes

- **`Storefront\CustomerRegisterController`:**
  - Campos: tipo y número de documento, nombre, correo, celular, dirección, barrio, contraseña + confirmación.
  - Casilla obligatoria de **términos y tratamiento de datos personales** (Ley 1581 de 2012). Enlaces configurables desde la landing (§8.4). Se guarda `accepted_terms_at` (`dateTime`).
  - Crea el cliente **en la empresa de la sede elegida**, con `is_online_user = true` y `preferred_store_id` (columna nueva, FK a `stores`, nullable).
- **Si el documento ya existe en esa empresa:**
  - Con cuenta en línea → «Ya tienes cuenta. Ingresa.» con enlace.
  - Creado en caja, sin cuenta en línea → **no se le pone contraseña así nomás**: sería tomarse la cuenta (y el crédito) de otra persona con solo saber su cédula.
    - Se envía un **código de verificación al correo registrado en su ficha** y se activa al confirmarlo.
    - Sin correo en la ficha: «Acércate a la sede para activar tu cuenta».
- Tras registrarse inicia sesión y vuelve a donde iba (`intended`: carrito o checkout).
- `throttle`, contraseñas con `Password::defaults()`, y el correo en minúsculas.

### 8.4 Editor de la landing (super usuario)

- **Ruta:** `/plataforma/landing`. Porta el modelo de ERP:
  - `landing_blocks`: `type`, `position`, `is_visible`, `data` json (borrador), `published_data` json.
  - `landing_versions`: `snapshot` json, `published_by`, `note`, `published_at` (`dateTime`).
  - Controlador de referencia: `ERP_CONFECCION/app/Http/Controllers/SuperAdmin/LandingAdminController.php`.
  - Acciones: agregar, duplicar, reordenar, ocultar, borrar, subir imagen, publicar, historial y restaurar versión.
- **Bloques como en la tienda de MiMercadoCol:**
  - Una clase por tipo en `app/Landing/Blocks/` que extiende una base con `fields()` y `defaults()`.
  - Un `LandingBlockCatalogue::TYPES`.
  - El editor arma el formulario **solo** con `fields()`.
  - Se pinta en `resources/js/components/landing/LandingBlocks.tsx`.
  - Íconos Phosphor **uno por uno** (regla de `CLAUDE.md`).
  - Datos del servidor resueltos en un único `LandingRenderer::resolve()`, que alimenta la página pública **y** la vista previa.
- **Vista previa en `iframe`**, con conmutador celular/computador (trampa de `CLAUDE.md`: `md:`/`lg:` miden la ventana).

**Tipos de bloque.** Todo texto, imagen, botón (etiqueta y destino), color, orden y visibilidad es editable.

| Tipo | Contenido | Notas |
|---|---|---|
| `header` | Logo, enlaces, **«Ingresar como empresa»** (→ `route('login')`, etiqueta editable), «Contratar» | Obligatorio, uno solo |
| `hero` | Título, subtítulo, imagen, CTA «Buscar mi tienda» (ancla al buscador) | — |
| `store_finder` | Título, texto, ciudad por defecto, mostrar/ocultar horario y distancia | **Obligatorio, uno solo, no se puede borrar**, solo ocultar con advertencia. Datos: sedes vendibles de todas las empresas (§8.2) con nombre, empresa, color y logo de marca, dirección, ciudad, coordenadas, horario, «abierta ahora» |
| `how_it_works` | Pasos (elige tienda → regístrate → compra → recibe) | — |
| `companies_showcase` | Logos de empresas presentes | Datos tipados: empresas activas con tienda vendible; cada empresa puede ocultarse con `companies.show_on_landing` |
| `benefits` | Tarjetas con ícono, título y texto | — |
| `plans` | Planes públicos con conmutador de ciclo | Precios **de `MembershipPricing`**; botón «Contratar» → `/contratar?plan=…&ciclo=…` |
| `testimonials`, `faq`, `cta_band` | Libres | — |
| `contact` | Formulario de contacto | Crea una solicitud (§8.5) |
| `footer` | Columnas de enlaces, redes, textos legales, enlaces a términos y política de datos | Obligatorio |

- **Ajustes globales de la landing:** SEO (título, descripción, imagen para compartir), favicon, **marca de plataforma** (nombre, logo, color, fuentes de la lista cerrada de `config/brand_fonts.php`), WhatsApp de contacto, correo que recibe las solicitudes.
  - La marca de plataforma es la que usa `BrandThemeService` cuando no hay empresa: login y consolidado.
- **Buscador de sede cercana (`store_finder`):**
  - «Usar mi ubicación»: pide permiso **al hacer clic**, nunca al cargar.
  - Con ubicación → `POST /sedes-cercanas` → lista ordenada con distancia («a 1,2 km»).
  - Sin permiso o sin coordenadas → filtro por ciudad + buscador por nombre o barrio.
  - Cada tarjeta: empresa, sede, dirección, horario y «Abierta» / «Cerrada», con botón **«Comprar aquí»** → `/tienda/{empresa}/{tienda}`.
  - Recuerda la última sede elegida (`localStorage`, con try/catch) y ofrece «Seguir comprando en Norte — La Canasta».
  - Sin mapa en la v1. Si se agrega, Leaflet por npm con teselas de OpenStreetMap y atribución.
- **No portes** los bloques de consulta SQL de ERP (`SafeQueryRunner`, `LandingDataSources` con SQL libre). La landing es pública: solo fuentes de datos **tipadas** (sedes, empresas, planes).
- **Semilla:** una landing por defecto completa y publicada, para que `/` nunca quede vacía tras `migrate:fresh --seed`.

### 8.5 Contratar (D3)

**`GET /contratar`:**

- Plan preseleccionado desde la URL + conmutador de ciclo con el precio de `MembershipPricing`.
- Formulario:
  - **Empresa:** nombre, NIT, ciudad, teléfono, correo.
  - **Administrador:** nombre, apellido, correo, documento, contraseña.
  - Número estimado de sedes y mensaje opcional.
- Casilla de términos y datos.
- **Elección visible de quien llena el formulario** (control segmentado):
  - **«Pagar ahora y activar mi cuenta»** — solo se pinta si la pasarela está habilitada.
  - **«Prefiero que me contacten»**.
- Validaciones:
  - NIT único entre empresas y contrataciones pendientes.
  - Correo del admin único en `users`.
  - Si el plan tiene prueba gratis, «Pagar ahora» cambia a «Empezar prueba gratis» y **no pasa por Wompi**: aprovisiona directo en `trial`.

**Pagar ahora:**

1. `POST /contratar` crea `company_signups`:

   | Columna | Nota |
   |---|---|
   | `reference` | único, `SIG-…` |
   | `membership_plan_id`, `billing_cycle_id` | — |
   | `amount` | bigInteger |
   | `currency` | — |
   | Datos de empresa y administrador | — |
   | `admin_password_hash` | **Guardada ya con `Hash::make`, jamás en claro** |
   | `status` | string + constantes: `pending`, `paid`, `failed`, `expired` |
   | `transaction_id` | — |
   | `paid_at`, `expires_at` | dateTime (2 h) |
   | `company_id` | nullable |

2. Redirige al checkout web de Wompi con firma de integridad y `redirect-url` = `/contratar/estado/{reference}`.
3. El webhook (§5.7) liquida: `CompanyProvisioner` crea todo (§4.4) con membresía `active` por los meses del ciclo, cobro `approved` y correo de bienvenida.
4. La página de estado consulta cada pocos segundos; al quedar pagada inicia sesión del admin y lo lleva a cambiar contraseña / inicio. Referencia: `ERP_CONFECCION/app/Http/Controllers/Auth/CompanySignupController.php`, `Services/Payments/SignupSettlementService.php` y `CompanyProvisioner.php`.
5. Un comando o el mismo `membership:process` marca `expired` las pendientes vencidas.

**Que me contacten:**

- `POST /contratar/solicitud` (y el bloque `contact`) crea `platform_leads`:

  | Columna | Nota |
  |---|---|
  | Datos de empresa y contacto | — |
  | `membership_plan_id`, `billing_cycle_id` | nullable |
  | `message` | — |
  | `status` | string + constantes: `new`, `contacted`, `won`, `lost` |
  | `notes` | — |
  | `handled_by_user_id` | — |
  | `created_at`, `updated_at` | — |

- Avisa por correo a `platform.leads_notify_email`. Referencia: `ERP_CONFECCION/app/Http/Controllers/LandingPlanInquiryController.php`.
- **Solicitudes** (`/plataforma/solicitudes`): listado con filtros por estado, detalle, cambio de estado, notas y **«Crear empresa desde esta solicitud»** (abre Empresas > Crear con los datos prellenados; al guardar, la solicitud queda `won` con `company_id`).

### 8.6 Aceptación de la Fase 5

- [ ] `/` sin sesión muestra la landing publicada; el borrador no se ve hasta publicar.
- [ ] Con dos empresas y cinco sedes con coordenadas, «Usar mi ubicación» ordena por distancia. Una empresa suspendida o una tienda apagada no aparece.
- [ ] «Comprar aquí» en una sede de El Vecino muestra el catálogo de El Vecino. Me registro y quedo como cliente de El Vecino con esa sede preferida. Compro y el pedido cae en esa sede.
- [ ] Vuelvo a la landing, elijo una sede de La Canasta: mi sesión de cliente se cierra, el carrito es otro, y al volver a El Vecino mi carrito sigue ahí.
- [ ] Un documento creado en caja no se puede «registrar» sin el código enviado a su correo.
- [ ] «Ingresar como empresa» lleva a `/login`.
- [ ] El editor permite cambiar cada texto, imagen, botón y orden; publicar crea versión; restaurar funciona; la vista previa cambia entre celular y computador.
- [ ] En `/contratar`, con pasarela en sandbox, pago con tarjeta de prueba y la empresa queda creada y con sesión iniciada. Con «Que me contacten» queda la solicitud y llega el correo. Con la pasarela apagada solo aparece la segunda opción.

---

## 9. Permisos, menú y seeders

**Módulo nuevo `platform` en `PermissionHelper`** con `super_admin_only: true` (no asignable a usuarios de empresa). Las rutas llevan `super.admin` **y** `permission:`:

| Página | Acciones |
|---|---|
| `platform.leads` | `view`, `manage` |
| `platform.plans` | `view`, `create`, `edit`, `deactivate` |
| `platform.billing_cycles` | `view`, `create`, `edit`, `deactivate` |
| `platform.gateway` | `view`, `edit` |
| `platform.landing` | `view`, `edit`, `publish` |
| `platform.imports` | `view`, `run` |

**Permisos de empresa nuevos** (cada uno con migración de reparto; en una base en uso agregarlos al catálogo no se los da a nadie):

- `settings.membership.view`, `settings.membership.manage_payment`
- `payment_terms.index.view/create/edit/deactivate`

**Seeders:**

- Ciclos: Mensual (1, 0 %), Trimestral (3, 5 %), Semestral (6, 10 %), Anual (12, 15 %).
- Planes:
  - **Básico:** 1 sede, 5 usuarios, 2 cajas, sin tienda.
  - **Pro:** 3 sedes, 20 usuarios, 8 cajas, tienda + promociones.
  - **Cadena:** sin topes, todas las funciones; prueba de 14 días.
- Pasarela: apagada, sin llaves.
- Landing por defecto publicada. `platform_settings` con sus defectos.
- **Coordenadas a las sedes de La Canasta** (Medellín: Centro, Norte, Sur) y a La Canasta plan Pro, activa, ciclo mensual, vence en 25 días.
- **Segunda empresa de demostración «Mercado El Vecino»** (Envigado o Bello), con 2 sedes con coordenadas, un admin, catálogo corto con precios y tienda publicada y encendida.
- El super usuario sigue siendo `super@lacanasta.co`.

---

## 10. Lo que **no** se copia de ERP_CONFECCION

| En ERP | Aquí |
|---|---|
| Columnas `enum` (`company_billing_charges.status`, `company_signups.status`) | `string` + constantes en el modelo |
| Precios `decimal(12,2)` (`price_monthly`, `amount`) | COP entero en `bigInteger` |
| Fechas de dominio en `timestamp` | `dateTime` (trampa de MariaDB) |
| `SettingController@updatePaymentMethod` recibe **número de tarjeta y CVC** en el servidor | Tokenización en el navegador (§5.5); el servidor rechaza esos campos |
| `ProcessMembershipAutoDebits` es un esqueleto con TODO que no cobra | Cobro real contra la fuente de pago, con fila `pending` primero |
| `company_signups.admin_password` | `admin_password_hash`, con `Hash::make` al guardar |
| Bloques de landing con SQL libre (`SafeQueryRunner`) | Solo fuentes de datos tipadas |
| Mi empresa lee `$request->user()->company` | `TenantContext::company()`, para que el super usuario con empresa elegida la vea |
| Heroicons y Headless UI | Phosphor (uno por uno) y los componentes de `resources/js/components/ui` |
| `softDeletes` en empresas | `is_active` + `deactivated_at` + motivo |
| «Periodicidad de pagos» = nómina | Ciclos de membresía (plataforma) + plazos de pago (empresa) |

**Archivos de ERP para leer como referencia:**

- `app/Http/Controllers/CompanyController.php`, `Http/Requests/Company/*`, `Pages/Companies/*`
- `app/Http/Controllers/SuperAdmin/{ActiveCompanyController,MembershipPlanController,PaymentGatewayController,LandingAdminController,DataImportController}.php`
- `app/Http/Controllers/{SettingController,WompiWebhookController,LandingController,LandingPlanInquiryController}.php`, `Auth/CompanySignupController.php`
- `app/Services/Payments/*`, `app/Services/Membership/*`, `app/Console/Commands/ProcessMembershipAutoDebits.php`, `routes/console.php`
- `app/Models/{Company,MembershipPlan,CompanyPaymentMethod,CompanyBillingCharge,CompanySignup,PaymentGatewaySetting,PaymentGatewayCredential,LandingBlock,LandingVersion}.php`
- `resources/js/Components/SuperAdminCompanySwitcher.tsx`, `lib/navigation.ts`, `Pages/SuperAdmin/**`, `Pages/Landing/Public.tsx`, `Pages/Settings/**`
- `context/PROMPT_SUPERADMIN_SELECTOR_EMPRESA_VISTA_GLOBAL.md`, `PROMPT_REDISENO_MI_EMPRESA_MEMBRESIA_PAGOS.md`, `CLOUD_OPUS_PROMPT_LANDING_CMS_MEMBRESIAS.md`, `PROMPT_EDITOR_LANDING_SUPER_USUARIO.md`, `PROMPT_LANDING_EDITABLE_EDITOR_Y_ACCESO.md`, `PROMPT_AJUSTE_SUPERADMIN_IMPORTACION_CSV.md`

---

## 11. Pruebas automáticas

Todas en `tests/Feature`. Wompi siempre con `Http::fake()`.

| Archivo | Qué prueba |
|---|---|
| `ActiveCompanySwitchTest` | Solo super usuario; `null` = consolidado; cambia y olvida la sede; redirección sin 404 |
| `ConsolidatedModeTest` | Rutas de empresa → «Elige una empresa»; `dashboard` → resumen de plataforma; selector de sede vacío |
| `SuperAdminOperatesCompanyTest` | Con B elegida: artículo, ajuste, venta en caja y cierre quedan en B; tiquete con datos de B |
| `CompanyProvisioningTest` | Roles, sede + bodega principal, caja, tienda apagada, admin con permisos directos, membresía, plazos; todo o nada (transacción) |
| `MembershipLifecycleTest` | `active → grace → suspended → active` con `setTestNow`; comando idempotente; `--dry-run` no escribe |
| `MembershipEnforcementTest` | Suspendida: solo Membresía; 402 en JSON; super usuario pasa; tienda no vende |
| `MembershipLimitsTest` | Topes de sedes, usuarios y cajas en controladores e importaciones |
| `PaymentMethodTest` | Rechaza `card_number`/`cvc`; guarda solo lo que devuelve Wompi; renovación automática exige tarjeta |
| `WompiWebhookTest` | Firma inválida 401; `SIG-` aprovisiona una sola vez; `MEM-` renueva una sola vez; monto distinto → `error` |
| `GatewaySettingsTest` | Credenciales cifradas en reposo; enmascaradas en props; vacío conserva; no habilita incompleta |
| `PaymentTermsTest` | Un defecto por tipo; uso en crédito, proveedor y orden (`due_at`) |
| `PlatformImportTest` | Importa a B con A elegida arriba; `companies` aprovisiona; respeta topes; reporte de errores |
| `LandingTest` | Borrador vs. publicado; bloque `store_finder` no se borra; restaurar versión; sedes vendibles solamente |
| `StorefrontMultiCompanyTest` | Sede de B → catálogo de B; cliente de A sale al cambiar; carrito por empresa; `customer.auth` exige la empresa |
| `CustomerRegistrationTest` | Alta nueva; documento con cuenta → error; documento de caja → exige código; único por empresa |
| `SignupTest` | Pagar ahora crea `SIG-` pendiente; contacto crea solicitud + correo; prueba gratis aprovisiona en `trial`; pasarela apagada oculta la vía de pago |
| `RouteSmokeTest` | Súmale las rutas nuevas. Barrido de rutas GET del panel con super usuario **en consolidado y con empresa elegida**. Exime por nombre de parámetro solo lo que el barrido no pueda montar |

---

## 12. Reglas nuevas para `MiMercadoCol/CLAUDE.md`

Agrégalas al terminar, en «Trampas conocidas»:

```markdown
- **Empresa activa**: se lee con `TenantContext::activeCompanyId()` / `TenantContext::company()`, nunca con
  `$user->company`. El super usuario no tiene empresa propia: la elige en el selector y vive en sesion.
  Sin elegir esta en consolidado, y las rutas de empresa pasan por `company.selected`.
- **Aprovisionar una empresa**: siempre por `CompanyProvisioner`, que usan Empresas, la contratacion en linea
  y la carga masiva. Una empresa creada por otro camino queda sin roles, sin caja o sin tienda.
- **Membresia**: el estado lo mueve `MembershipService` (y el comando `membership:process`), nunca un
  update suelto. `EnsureMembershipActive` bloquea a las suspendidas; los topes del plan los revisa
  `MembershipLimiter` en cada alta. Una funcion en `MembershipFeatures::CATALOGUE` que ninguna ruta revise
  no se crea.
- **Precio de una membresia**: solo `MembershipPricing::priceFor()`. Ni la landing ni el `.tsx` lo calculan.
- **Tarjetas**: el numero y el CVC nunca llegan al servidor; se tokenizan en el navegador contra Wompi y se
  guarda la fuente de pago. Un FormRequest que acepte `card_number` es un error.
- **Webhook de Wompi**: firma, relectura de la transaccion e idempotencia por `reference`, en ese orden.
  Cobro nuevo: primero la fila `pending`, despues la pasarela.
- **Tienda multiempresa**: la empresa de la tienda sale de la sede elegida (`StorefrontContext`), nunca de
  «la primera empresa». Cambiar de empresa cierra la sesion del cliente y usa otro carrito;
  `CartService` nunca mueve un carrito entre empresas.
- **Landing de plataforma**: bloques en `app/Landing/Blocks/`, datos por `LandingRenderer::resolve()`, sin
  SQL libre. `store_finder`, `header` y `footer` no se borran.
- **Ajustes de plataforma**: por `PlatformSettingsService`, con su defecto en `DEFAULTS`; los de empresa
  siguen en `SettingsService`.
```

---

## 13. Entrega

Al cerrar cada fase:

- Resumen de archivos creados y modificados.
- Migraciones nuevas y seeders tocados.
- Permisos nuevos con su migración de reparto.
- Resultado de `npx tsc --noEmit`, `npm run build`, `php artisan test` y `php artisan migrate:fresh --seed`.
- El recorrido manual de aceptación que hiciste.
- Lo que quedó pendiente o a medias, **dicho claramente**: si algo quedó a medias, se reporta como a medias.
