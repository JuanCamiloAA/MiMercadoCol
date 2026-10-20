# Prompt: planes con funciones parametrizables y medios de pago por empresa

> Para Claude Opus, trabajando en el repositorio de la plataforma (Laravel 12 + Inertia 2 + React 19 +
> TypeScript estricto). Son dos cambios grandes. Se hacen **por fases**: cada fase deja el sistema
> funcionando y con las pruebas en verde. No empieces una fase sin cerrar la anterior.

---

## 0. Antes de escribir una línea

1. Lee completos `CLAUDE.md` y `README.md`. Todas sus reglas aplican aquí, en especial:
   - COP entero (`bigInteger`);
   - `dateTime` y no `timestamp`;
   - nada de `enum` de MySQL;
   - un `FormRequest` por acción;
   - `permission:` en cada ruta;
   - «Permiso nuevo en el catálogo» necesita migración de datos;
   - «Tarjetas»;
   - «Webhook de Wompi»;
   - «Cobro repartido»;
   - «Redondeo y cobro combinado»;
   - «Plata que entró por un medio»;
   - «Empresa activa»;
   - «Aprovisionar una empresa».
2. Lee el código que vas a tocar, no lo supongas:
   - Planes y membresía:
     - `app/Helpers/PermissionHelper.php` (el árbol módulo → página → acción);
     - `app/Services/EffectivePermissionService.php`;
     - `app/Http/Middleware/CheckPermission.php`;
     - `app/Http/Middleware/EnsurePlanFeatures.php`;
     - `app/Services/Membership/*` (`MembershipFeatures`, `MembershipPricing`, `MembershipLimiter`, `MembershipService`, `MembershipBillingService`, `MembershipOverviewService`);
     - `app/Models/MembershipPlan.php`;
     - `app/Models/Company.php` (`hasFeature`, `features`);
     - `app/Services/Platform/CompanyProvisioner.php`;
     - `database/seeders/PlatformSeeder.php`;
     - `resources/js/lib/navigation.ts`;
     - las pantallas de Plataforma > Planes, Empresas y Ajustes > Membresía.
   - Pagos:
     - `app/Models/SalePayment.php` (`METHOD_*`, `POS_METHODS`, `NET_AMOUNT_SQL`);
     - `app/Models/Order.php` (`PAYMENT_*`, estados);
     - `app/Services/SaleService.php`;
     - `app/Services/OrderService.php` (`create`, `deliver`, `paymentMethodFor`);
     - `app/Services/Storefront/StorefrontConfig.php` (`paymentMethods`);
     - `StorefrontManager::defaultSettings()['payments']`;
     - `app/Http/Controllers/Storefront/CheckoutController.php`;
     - `resources/js/pages/Pos/components/PaymentDialog.tsx`;
     - `app/Services/Payments/WompiService.php`;
     - `PaymentGatewayResolver`, `PaymentGatewaySetting`, `PaymentGatewayCredential`;
     - `WompiWebhookController`;
     - `config/wompi.php`.
3. Ojo con un nombre que ya está tomado: **`CompanyPaymentMethod` es la tarjeta con que la empresa
   paga su membresía**, no un medio de pago de ventas. No la toques ni la reutilices. Y
   `PaymentGatewayCredential` / `PaymentGatewaySetting` son la pasarela **de la plataforma** para cobrar
   membresías. Las cuentas de pasarela de cada empresa son otra cosa y van en tablas propias.

---

## 1. Decisiones ya tomadas (no las vuelvas a preguntar)

| Tema | Decisión |
|---|---|
| Granularidad del plan | **Módulo, página y acción**: el mismo árbol de `PermissionHelper`. El plan es un **techo**: ningún usuario puede ejercer un permiso que el plan de su empresa no incluye. |
| Ajustes por empresa | Sumar o quitar funciones del plan, con **fecha de vencimiento** opcional, y **precio especial**. Los topes (sedes, usuarios, cajas) siguen siendo **solo del plan**. |
| Medios de pago | Un módulo por empresa donde se configuran todos: manuales (efectivo, datáfono, transferencias tipo Nequi/Daviplata, crédito…) y pasarelas en línea. La empresa decide **cuáles** aparecen, **cómo** se ven y **cuándo** (canal, sede, caja, horario, montos…). |
| Pasarelas de la primera versión | **Wompi, Mercado Pago, PayU, ePayco y Bold**, todas detrás de una misma interfaz de conector. |
| Pasarelas en la caja | **QR / link de pago** y **datáfono integrado**, cuando la pasarela lo ofrezca. |

---

## 2. Parte 1: planes con funciones parametrizables

### 2.1 Qué hay hoy y qué cambia

Hoy un plan tiene tres topes (`max_stores`, `max_users`, `max_registers`) y cuatro funciones sueltas
(`MembershipFeatures::CATALOGUE`: `online_store`, `promotions`, `multi_warehouse`, `catalog_import`).
`EnsurePlanFeatures` las hace cumplir por prefijo de ruta, y algunas se revisan donde pasan
(`PromotionService`, `CouponService`, `StorefrontContext::sellable`, `WarehouseController`).

Con este cambio, **lo que incluye un plan se describe con nodos del árbol de permisos**. Un plan ya no
dice «tiene tienda en línea»: dice «incluye `storefront.*`» o «incluye `inventory.transfers.*` pero no
`inventory.transfers.direct`».

### 2.2 Nodos del árbol (la llave de todo)

Un **nodo** es una cadena con la misma forma que un permiso:

| Nodo | Significa |
|---|---|
| `inventory.*` | Todo el módulo, **incluidas las páginas y acciones que se agreguen después**. |
| `inventory.transfers.*` | Toda la página, incluidas las acciones futuras. |
| `inventory.transfers.direct` | Una sola acción. |

Reglas:

- Los nodos válidos salen de `PermissionHelper::getPermissionMatrix()`. Un nodo que no corresponde a
  nada del árbol se rechaza al guardar y se ignora al leer (con un aviso en el log).
- **Solo de plataforma:** `platform.*` y `companies.*` nunca forman parte de un plan ni de una excepción.
  Ponlo en una constante (`Entitlements::PLATFORM_ONLY`).
- **Siempre incluidos:** lo que una empresa necesita para no quedar encerrada y poder pagar:
  `dashboard.index.view`, `settings.index.view`, `settings.membership.*`. Ponlo en una constante
  (`Entitlements::ALWAYS`), con el mismo espíritu que `NavigationOverrides::LOCKED`. No se pueden
  quitar ni en el plan ni por excepción.

### 2.3 Modelo de datos

- `membership_plans.entitlements` (JSON, lista de nodos). Reemplaza a `features`. Migra los datos y
  deja `features` en desuso, o bórrala en una segunda migración cuando nada la lea.
- `membership_plans.highlights` (JSON, lista de textos cortos, máximo 8): lo que la landing y
  «Contratar» muestran del plan. Si está vacía, se generan a partir de los módulos incluidos.
- `company_entitlements`: las excepciones por empresa.
  - `company_id`;
  - `node`;
  - `effect` (`string`, constantes `EFFECT_GRANT` / `EFFECT_REVOKE`);
  - `starts_at` y `ends_at` (`dateTime`, `ends_at` nulo = sin vencimiento);
  - `reason` (obligatorio);
  - `created_by_user_id`, `ended_by_user_id`;
  - timestamps.
  - Terminar una excepción antes de tiempo es ponerle `ends_at = now()`. No se borra: es historia.
- `company_plan_prices`: el precio especial.
  - `company_id`;
  - `membership_plan_id` (el precio especial es **de ese plan**: si la empresa cambia de plan, no se arrastra);
  - `monthly_price` (`bigInteger`, nulo) **o** `discount_percent` (entero 1–90, nulo): exactamente uno de los dos;
  - `starts_at` y `ends_at`;
  - `reason`;
  - `created_by_user_id`;
  - timestamps.

Las columnas nuevas van en `$fillable` (ver la trampa de `authorized_by_user_id`).

### 2.4 `EntitlementService`: la única respuesta a «¿la empresa puede?»

`App\Services\Membership\EntitlementService`. Es el único que sabe qué incluye una empresa.

- `for(Company $company): Entitlement`, memorizado por petición. El objeto `Entitlement` expone:
  - `allows(string $permission): bool`: si ese permiso concreto está dentro de lo que la empresa tiene.
  - `allowsNode(string $node): bool`: para preguntas de módulo o página («¿tiene `storefront.*`?»).
  - `filter(array $permissionNames): array`: deja solo lo permitido.
  - `explain(string $node)`: de dónde sale. Puede salir del plan, de una excepción que lo suma (con su
    vencimiento), de una que lo quita o de `ALWAYS`. Lo usan las pantallas para mostrar etiquetas.
- Cálculo, siempre en este orden y documentado en el código:
  1. Nodos del plan (`entitlements`), expandidos contra el árbol actual.
  2. **Más** las excepciones `grant` activas.
  3. **Menos** las excepciones `revoke` activas. Quitar gana a sumar.
  4. **Más** `ALWAYS`. Quitar `PLATFORM_ONLY`.
- Una excepción está activa si `starts_at <= now()` y (`ends_at` es nulo o `ends_at > now()`), en
  `America/Bogota`. El vencimiento se resuelve **al leer**, sin depender de un job: si el comando del
  día no corre, la excepción igual deja de valer a tiempo.
- Una empresa **sin plan** (`membership_plan_id` nulo, las de antes de las membresías) tiene todo,
  igual que hoy con `hasFeature`. No se le quita a nadie lo que ya usaba.

### 2.5 Dónde se hace cumplir

1. **`EffectivePermissionService::getEffectivePermissionNames()`**: devuelve los permisos directos del
   usuario **intersectados** con lo que permite la empresa activa. La empresa se lee con
   `TenantContext::activeCompanyId()` y el guard `web` explícito. Con esto quedan cubiertos de una vez:
   - `CheckPermission` en las rutas;
   - la prop compartida de permisos en `HandleInertiaRequests`;
   - el menú (`navigation.ts` filtra por permiso);
   - y cada `can()` del servidor.
   El super usuario no se limita.
2. **`CheckPermission`**: si el usuario **tiene** el permiso asignado pero el plan no lo incluye, no
   respondas «No tienes permiso». Responde con el mensaje del plan: «Tu plan no incluye ‹etiqueta del
   permiso›. Cámbialo en Ajustes > Membresía.», con la etiqueta de `PermissionHelper`. Deja un
   `AccessLog` con una acción propia (`plan_denied`), distinta de `permission_denied`.
3. **Lo que no es una ruta**: se pregunta a `EntitlementService` donde pasa. `Company::hasFeature()`
   queda como atajo que traduce las funciones viejas a nodos:

   | Función vieja | Nodo |
   |---|---|
   | `online_store` | `storefront.*` (y `StorefrontContext::sellable` lo sigue exigiendo para la tienda pública) |
   | `promotions` | `promotions.*` (`PromotionService` y `CouponService` siguen preguntando antes de aplicar precios o cupones) |
   | `multi_warehouse` | `stores.warehouses.create` |
   | `catalog_import` | `products.catalog_import.*` |

4. **`EnsurePlanFeatures`**: antes de retirarlo, recorre cada prefijo de `ROUTES` (incluido `media.`)
   y comprueba que todas sus rutas exigen un permiso cuyo nodo queda cubierto. Si alguna ruta exige un
   permiso de otro módulo, agrega el nodo que corresponde o conserva el middleware solo para ese caso.
   Ninguna ruta puede quedar abierta por el cambio.
5. Los permisos asignados que quedaron fuera del plan **no se borran** de `model_has_permissions`:
   solo se ignoran. Si la empresa sube de plan, los recupera sin que nadie los vuelva a asignar.

### 2.6 Precio especial

- `MembershipPricing::priceFor(MembershipPlan $plan, BillingCycle $cycle, ?Company $company = null)`.
  - Con empresa y un precio especial activo **para ese plan**, la base mensual es `monthly_price` o el
    precio del plan menos `discount_percent`.
  - Después se aplica el descuento del ciclo y el mismo redondeo de hoy, hacia arriba a mil.
  - Sigue siendo **el único cálculo** de precio de membresía.
- Lo usan:
  - el cobro (`MembershipBillingService`);
  - Ajustes > Membresía (con una etiqueta «Precio especial hasta ‹fecha›»);
  - el cuadro «Cambiar plan o ciclo», que muestra el precio de esa empresa en cada plan;
  - Empresas.
- La landing y «Contratar» **nunca** muestran precios especiales: llaman a `priceFor` sin empresa.
- Un cobro ya generado conserva su monto: el precio especial solo afecta los cobros nuevos.

### 2.7 Pantallas

**Plataforma > Planes > editar** (solo super usuario):
- Datos y precio (como hoy) y topes (como hoy).
- **«Qué incluye»**: el árbol de módulos → páginas → acciones, con las etiquetas de `PermissionHelper`.
  Reutiliza el componente del asignador de Roles; no escribas otro.
  - Marcar un módulo o una página completos guarda el comodín (`modulo.*`), y la pantalla lo dice:
    «incluye lo que se agregue después».
  - Un nodo con hijos marcados a medias se ve indeterminado.
  - `ALWAYS` se ve marcado y bloqueado; `PLATFORM_ONLY` no aparece.
- **«Destacados para la landing»**: lista editable, con un botón para generarla a partir de los módulos.
- Al guardar un plan que **quita** nodos, avisa antes cuántas empresas lo usan y qué pierden. Los
  datos se conservan y el acceso se corta. El cambio aplica de inmediato, porque el plan describe el
  producto, y queda en la bitácora de cada empresa afectada (`CompanyMembershipEvent`).

**Plataforma > Empresas > ‹empresa› > pestaña «Plan y funciones»**:
- El mismo árbol en solo lectura, con el origen de cada nodo (`explain`):
  - «Del plan»;
  - «Agregado hasta 30 oct.»;
  - «Quitado»;
  - «Siempre incluido».
- Botón **«Agregar excepción»**: elegir un nodo del árbol, sumar o quitar, desde/hasta, motivo.
  También se puede terminar una excepción vigente.
- Sección **«Precio especial»**: plan, precio mensual o descuento, vigencia, motivo.
- Historial de excepciones y precios. Todo cambio deja su renglón en la bitácora de membresía.

**Ajustes > Membresía** (la empresa):
- «Qué incluye tu plan»: el árbol en solo lectura, con las funciones agregadas y su vencimiento.
- El precio especial, si hay.
- En «Cambiar plan o ciclo»: qué gana y qué pierde con el plan elegido, comparando los dos árboles.

**Roles y Usuarios**:
- El asignador de permisos muestra **solo** lo que el plan incluye. Lo demás no se pinta, ni
  deshabilitado (regla de `CLAUDE.md`).
- Al pie va una nota: «N funciones no están en tu plan» con un enlace a Ajustes > Membresía, solo si
  el usuario puede verla.

**Landing y «Contratar»**:
- Las viñetas de cada plan salen de `highlights`.
- La tabla comparativa sale de los módulos incluidos (nivel módulo), no de una lista fija en el `.tsx`.

### 2.8 Avisos y comando diario

`membership:process`, que ya corre a las 03:00:
- avisa al administrador de la empresa 3 días antes de que venza una función agregada o un precio especial;
- registra en la bitácora la excepción o el precio que venció ese día.

No hace falta que el comando «apague» nada: el vencimiento ya se resuelve al leer.

---

## 3. Parte 2: medios de pago por empresa

### 3.1 Qué hay hoy y qué cambia

| Hoy | Después |
|---|---|
| La caja ofrece una lista fija (`SalePayment::POS_METHODS`) y `PaymentDialog.tsx` la repite. | La caja ofrece los medios que la empresa configuró y que valen **en esa caja, a esa hora y por ese monto**. |
| La tienda tiene cuatro interruptores por sede en `storefront_settings.payments` (contraentrega, datáfono contraentrega, transferencia, crédito). Todo se cobra al entregar. | La tienda ofrece **pago en línea** por pasarela y pago al recibir, según las reglas de la empresa. |
| Wompi existe solo para cobrar la membresía, con llaves de la plataforma. | Cada empresa conecta **su propia cuenta** de Wompi, Mercado Pago, PayU, ePayco o Bold. La plata va a la empresa, no a la plataforma. |

### 3.2 Modelo de datos

**`payment_methods`**: un medio de pago configurado por la empresa. El modelo se llama `PaymentMethod`;
no lo confundas con `CompanyPaymentMethod`.

| Campo | Para qué |
|---|---|
| `company_id` | Dueña. Con `CompanyScope`. |
| `name` | Lo que lee el cajero o el cliente: «Nequi», «Datáfono Bancolombia», «Paga con Wompi». |
| `kind` | `string` con constantes en el modelo: `cash`, `card` (datáfono manual), `transfer`, `credit`, `store_credit`, `exchange`, `gateway_checkout` (tienda), `gateway_link` (QR/link en caja), `gateway_terminal` (datáfono integrado), `other`. **El comportamiento sale del `kind`**, no del nombre. |
| `gateway_account_id` | Para los `gateway_*`: con qué cuenta de pasarela se cobra. |
| `is_system` | Efectivo, Saldo a favor y Cambio por devolución. No se borran; `exchange` nunca se muestra (solo lo arma la devolución). |
| `icon`, `display` (JSON) | Cómo se ve: ícono, descripción corta por canal, texto de instrucciones para el cliente (p. ej. el número de Nequi y el titular), si se resalta. |
| `requires_reference`, `reference_label` | Si al cobrar hay que anotar un número («Número de aprobación», «Últimos 4 dígitos»). |
| `fee_percent`, `fee_fixed` | Comisión estimada, para el informe de lo que de verdad entra. COP entero en `fee_fixed`. |
| `sort_order`, `is_active` | |
| `availability` (JSON) | **Cuándo aparece** (ver 3.4). |

**`payment_gateway_accounts`**: la cuenta de pasarela de la empresa.

| Campo | Notas |
|---|---|
| `company_id`, `provider` (`wompi`, `mercadopago`, `payu`, `epayco`, `bold`), `environment` (`sandbox`, `production`) | Única por empresa + proveedor + entorno. Ninguna de esas columnas es nula; recuerda que MariaDB deja repetir `NULL` en un índice único. |
| `public_fields` (JSON) | Lo que puede verse: llave pública, id de comercio. |
| `secret_fields` (texto, cast `encrypted`) | JSON cifrado en reposo con las llaves secretas. Nunca viaja al navegador, ni enmascarado dentro de una prop que lo contenga entero. Para mostrar, usa una máscara como `PaymentGatewayCredential::mask`. |
| `webhook_token` | Aleatorio y largo, va en la URL del webhook de esa cuenta. Se puede rotar. |
| `is_enabled`, `verified_at`, `last_error` | «Probar conexión» llena `verified_at` o `last_error`. |

**`payment_transactions`**: cada intento de cobro por pasarela.

| Campo | Notas |
|---|---|
| `company_id`, `payment_method_id`, `gateway_account_id`, `provider`, `environment` | |
| `reference` | Único, `PAY-…` (ULID). Es la llave de idempotencia. |
| `amount` (COP entero), `currency` (`COP`) | Los centavos que piden algunas pasarelas se calculan **solo en el conector**. |
| `channel` | `pos`, `online`. |
| `payable_type` / `payable_id` | El pedido, o el intento de venta de la caja (ver 3.7). |
| `status` | `pending`, `approved`, `declined`, `voided`, `expired`, `error`. Constantes en el modelo. |
| `gateway_transaction_id`, `checkout_url`, `qr_payload`, `terminal_id` | |
| `expires_at`, `approved_at`, `applied_at` | `applied_at`: cuándo se convirtió en venta o confirmó el pedido. |
| `payload` (JSON) | Lo que respondió la pasarela, **saneado**: nunca números de tarjeta ni llaves. |

**`payment_terminals`**: datáfonos integrados.
- Campos: `company_id`, `store_id`, `register_id` (nulo = cualquier caja de la sede),
  `gateway_account_id`, `device_id`, `name`, `is_active`, `last_seen_at`.

**Cambios en tablas existentes**
- `sale_payments.payment_method_id` (FK). `method` **se queda** y guarda el `kind`. Las reglas que
  dependen de él siguen leyendo `method`:
  - redondeo solo con todo en efectivo;
  - solo el efectivo da cambio;
  - `NET_AMOUNT_SQL`;
  - arqueo.
  Y agrega `sale_payments.payment_transaction_id` (nulo) y `reference` (nulo).
- El índice único `sale_id, method` pasa a `sale_id, payment_method_id`: así una venta puede pagarse
  con Nequi **y** Daviplata, dos medios del mismo `kind`. Actualiza la trampa «Cobro repartido» de
  `CLAUDE.md`: la regla sigue siendo un renglón por medio configurado, y solo el efectivo puede exceder.
- `orders.payment_method_id` (FK). `orders.payment_method` se queda con el `kind`. Estado nuevo
  `Order::STATUS_PENDING_PAYMENT`, antes de `new`.

### 3.3 Conectores de pasarela

```
app/Payments/Gateways/
  PaymentGateway.php        // interfaz
  GatewayRegistry.php       // const DRIVERS = ['wompi' => WompiGateway::class, ...]
  WompiGateway.php
  MercadoPagoGateway.php
  PayUGateway.php
  EpaycoGateway.php
  BoldGateway.php
```

La interfaz, como mínimo:

- `fields(): array`: qué llaves pide, cuáles son secretas, cómo se llaman en pantalla. La pantalla de
  la cuenta se arma **solo** con esto, igual que el editor de bloques con `fields()`.
- `capabilities(): array`: `checkout` (tienda), `link`, `qr`, `terminal`, `refund`, `void`. La interfaz
  solo ofrece lo que el conector declara. Si Bold o PayU no tienen datáfono integrado, la opción no aparece.
- `test(GatewayAccount): TestResult`.
- `createCheckout(PaymentTransaction, array $context)`: devuelve URL o datos del widget.
- `createLink(PaymentTransaction)` y `createQr(PaymentTransaction)`.
- `createTerminalIntent(PaymentTransaction, PaymentTerminal)`.
- `fetch(PaymentTransaction): GatewayStatus`: relee el estado en la pasarela.
- `verifyWebhook(Request, GatewayAccount): ?GatewayEvent`: firma válida y referencia, o `null`.
- `cancel(PaymentTransaction)`: anula un link, QR o intento de datáfono pendiente, si se puede.

**Cliente de Wompi**: `WompiService` hoy recibe las llaves de la plataforma. Haz que reciba un objeto
de credenciales, para que la membresía (llaves de la plataforma) y las ventas (llaves de la empresa)
usen **el mismo cliente**. No dupliques la lógica de firmas ni la de tokens de aceptación.

**Contrato de cada pasarela: verificarlo, no suponerlo.** Antes de escribir cada conector, lee la
documentación oficial vigente y deja el contrato resumido en `config/<proveedor>.php`, como ya se hizo
con `config/wompi.php` («contrato verificado (sep. 2026)» en `CLAUDE.md`). Debe cubrir:
- URLs de sandbox y producción;
- autenticación;
- cómo se firma el checkout;
- cómo se verifica el webhook;
- cómo se relee una transacción;
- si hay links, QR y datáfono, y su disponibilidad **en Colombia**.

Puntos a confirmar, que pueden haber cambiado:

| Pasarela | A confirmar |
|---|---|
| Wompi | Web Checkout/Widget con firma de integridad; `checksum` de eventos (llega en mayúsculas, ya documentado); API de links de pago; tokens de aceptación en `GET /merchants/info` con `x-merchant-public-key`. |
| Mercado Pago | Preferencias de Checkout Pro con `external_reference` y `notification_url`; firma del webhook en `x-signature` (`ts` y `v1`) con `x-request-id`; relectura por `GET /v1/payments/{id}`; QR dinámico; **Point** (datáfono) y su disponibilidad en Colombia. |
| PayU | WebCheckout con firma (`ApiKey~merchantId~referenceCode~amount~currency`); página de confirmación con su propia firma; API de consultas. |
| ePayco | Checkout con llave pública; confirmación con `x_signature`; validación por API. |
| Bold | Botón de pagos con firma de integridad; API de links de pago; firma de webhooks; si existe API para datáfonos integrados. |

Si una capacidad no existe o no está disponible en Colombia, el conector no la declara. Escríbelo en
el resumen del `config`.

### 3.4 Cuándo aparece un medio de pago: `PaymentMethodAvailability`

Un solo servicio decide qué medios valen en un contexto. Lo usan las props de la caja, la validación
del servidor al cobrar, el checkout de la tienda y la creación del pedido. **Nadie más filtra medios de
pago.** Es la misma lección que con `PromotionService`: si la pantalla y el cobro preguntan a lugares
distintos, el cobro no cuadra.

Contexto:
- canal (`pos` / `online`);
- sede;
- caja;
- fecha y hora (`America/Bogota`);
- monto;
- cliente (registrado, con cupo de crédito, con saldo a favor);
- para la tienda, la entrega (`delivery` / `pickup`).

Reglas en `availability`, todas opcionales. Vacío = sin restricción:

| Regla | Ejemplo |
|---|---|
| `channels` | Solo caja; solo tienda; en la tienda, solo «al recibir». |
| `stores`, `registers` | Solo en la sede Norte; solo en la Caja 1. |
| `schedule` | Lunes a viernes de 7:00 a 21:00; varias franjas por día. |
| `valid_from`, `valid_until` | Una campaña: «Paga con Bold del 1 al 15». |
| `min_amount`, `max_amount` | Contraentrega hasta $ 300.000; transferencia desde $ 20.000. Se miden contra **lo que se cobra con ese medio**, no contra el total de la venta. |
| `delivery_modes` | Contraentrega solo a domicilio. |
| `customer` | Requiere cliente registrado; requiere cupo (crédito); requiere saldo (saldo a favor). |
| `combinable` | Si se puede mezclar con otros medios en el mismo cobro. Un `gateway_link` por venta. |

Además:
- **Crédito** sigue exigiendo `pos.sale.credit` y las reglas de cupo y mora que ya existen.
- **Saldo a favor** sigue pasando por `StoreCreditService`.
- **El servidor revalida siempre**: medio activo y disponible en ese contexto, `kind` permitido en ese
  canal y referencia presente si se exige. La lista que pinta el `.tsx` es solo una sugerencia.
- **Cómo aparece**: orden (`sort_order`), agrupación en la tienda («Paga en línea», «Paga al
  recibir», «Crédito»), medio por defecto por canal, instrucciones para el cliente e ícono.

### 3.5 La caja

- `Pos/Sale` recibe la prop `paymentMethods`, ya resuelta para esa caja y ese momento. `PaymentDialog`
  pinta con ella y deja de tener medios fijos.
- El comportamiento de cada medio sale de su `kind`:

  | `kind` | Qué pasa en la caja |
  |---|---|
  | `cash` | Monto, cambio y redondeo. La regla del redondeo, idéntica en `PaymentDialog` y `SaleService`. |
  | `card`, `transfer`, `other` | Monto y, si se exige, la referencia. |
  | `credit` | Cliente con cupo; exige `pos.sale.credit`. |
  | `store_credit` | Saldo a favor del cliente. |
  | `gateway_link` | Ver 3.6. |
  | `gateway_terminal` | Ver 3.6. |

- `StorePosSaleRequest` acepta `payment_method_id` (y `reference`) por renglón. `SaleService::create`
  traduce cada renglón a `method` = `kind`.
- **Arqueo y cierre de turno**: el efectivo esperado sigue igual. Agrega el detalle por medio
  configurado: cuánto se vendió con «Datáfono Bancolombia», con «Nequi», etc. Sirve para cuadrar los
  vouchers.

### 3.6 Pasarela en la caja: QR, link y datáfono

Flujo (la venta **no se crea** hasta que la pasarela aprueba):

1. El cajero elige un medio `gateway_link` (o `gateway_terminal`) por un monto. El resto se puede
   cobrar con otros medios, si el medio es combinable.
2. El servidor crea la fila `payment_transactions` en `pending` **antes** de llamar a la pasarela
   (regla de `CLAUDE.md`: «primero la fila pending, después la pasarela»).
   - Con link o QR, la pasarela devuelve el link o el QR.
   - Con datáfono, se envía el cobro al dispositivo elegido (`payment_terminals` de esa caja o sede).
3. La caja muestra el QR y el link, o «esperando el datáfono», con el tiempo restante y un botón
   «Cancelar cobro», que llama a `cancel` del conector.
   - Consulta el estado cada 2–3 s en una ruta propia (`GET /pos/pagos/{reference}`) que, si sigue
     pendiente, relee con `fetch`.
   - El webhook también puede aprobarla.
4. **Aprobada**: `SaleService::create` registra la venta con ese renglón de pago, ligado a
   `payment_transaction_id`, y marca `applied_at`. Todo en una transacción, con la fila bloqueada,
   para que el sondeo y el webhook no creen dos ventas.
5. **Rechazada, vencida o cancelada**: la caja vuelve al cobro con el tiquete intacto.
6. El tiquete en espera se guarda en el navegador **y** en el servidor (reutiliza el mecanismo de
   tiquetes suspendidos), para no perderlo si se recarga la página.

**Pagos por conciliar.** Si la caja se cerró, se fue la luz o el cajero canceló tarde, puede llegar
una aprobación sin venta. Esa transacción queda `approved` sin `applied_at` y aparece en una bandeja,
«Pagos por conciliar», con estas acciones:
- aplicarla a una venta o pedido;
- abonarla como saldo a favor de un cliente (nunca al consumidor final genérico);
- registrar que se reembolsó por fuera, con nota.

Cada acción va con su permiso. **Nunca** se descarta en silencio una plata que entró.

### 3.7 La tienda en línea

- El checkout muestra los medios que devuelve `PaymentMethodAvailability` para la sede, la entrega y el
  monto, agrupados.
- **Pago al recibir** (`cash`, `card`, `transfer`…): el flujo de hoy, sin cambios.
- **Pago en línea** (`gateway_checkout`):
  1. `OrderService::create` crea el pedido en `pending_payment`. Aparta inventario y saldo a favor
     como hoy y crea la `payment_transaction`.
  2. El cliente va al checkout de la pasarela, en redirección o widget. **Los datos de tarjeta nunca
     tocan el servidor.**
  3. Al volver, la página de estado **no confía en los parámetros de la URL**. Muestra «confirmando…»
     y consulta el estado: lo que vale es el webhook o la relectura con `fetch`.
  4. Aprobado: el pedido pasa a `new`, con `payment_status = paid`, y entra al alistamiento de siempre.
  5. Rechazado o vencido: el pedido se cancela y se libera lo apartado, inventario y saldo a favor.
     El vencimiento lo hace un comando programado cada minuto (`payments:expire`). El plazo es el
     ajuste `payments.online_hold_minutes` de `SettingsService` (defecto 30).
- **Al entregar un pedido pagado en línea**: `OrderService::deliver` arma la venta con el renglón de la
  pasarela por lo que se cobró, y el domiciliario no cobra nada.
  - Si se alistó **menos** de lo pagado, la diferencia vuelve al cliente.
  - En la primera versión vuelve como saldo a favor, con su movimiento y su nota.
  - Si el conector declara `refund`, se ofrece además «Reembolsar por la pasarela», y queda registrado
    en la transacción.
- Un pedido en `pending_payment` no aparece en la bandeja de alistamiento ni cuenta como pedido nuevo
  en el tablero.
- `storefront_settings.payments` queda en desuso: sus cuatro interruptores se migran a reglas de
  `availability` (ver 3.10). La pestaña **Funciones** del editor deja de mostrar medios de pago y
  enlaza al módulo. Los medios de pago **no se publican** con la tienda: son operación, no diseño.
  Actualiza la trampa de `CLAUDE.md` que dice que viven en `storefront_settings`.

### 3.8 Webhooks de las empresas

- Ruta: `POST /webhooks/pagos/{provider}/{token}`, fuera de CSRF, con `throttle`.
- `{token}` identifica la cuenta (`payment_gateway_accounts.webhook_token`).
- La ruta de la membresía (`webhooks/wompi`) **no se toca**.
- Orden fijo, el mismo de la regla de Wompi en `CLAUDE.md`:
  1. firma, con el secreto **de esa cuenta**;
  2. relectura de la transacción en la pasarela con `fetch`;
  3. idempotencia por `reference`, con la fila bloqueada.
- Antes de aplicar, se comprueba que **monto y moneda** coinciden con la fila `pending`. Si no
  coinciden, la transacción queda en `error` y se avisa: no se aprueba nada por un monto distinto.
- Responde 200 rápido. Lo pesado (crear la venta, confirmar el pedido) va en la misma transacción de
  base de datos y nunca dispara correos fuera de `DB::afterCommit`.

### 3.9 Pantallas

**Nuevo módulo «Medios de pago»** en el menú, en Administración > Empresa, con cuatro pestañas:

1. **Medios**:
   - lista ordenable por arrastre;
   - crear y editar con nombre, tipo, cuenta de pasarela (si aplica), cómo se ve, instrucciones,
     referencia y comisión;
   - el bloque **«Cuándo aparece»**: canales, sedes, cajas, horario por día, vigencia, montos, entrega
     y cliente;
   - una vista previa: «Así se ve en la caja» / «Así se ve en la tienda».
2. **Pasarelas**:
   - agregar una cuenta (proveedor y entorno); el formulario se arma con `fields()`;
   - «Probar conexión»;
   - la URL del webhook para copiar, con instrucciones por proveedor;
   - rotar el token;
   - encender y apagar;
   - las llaves secretas se ven enmascaradas y solo se reemplazan, nunca se leen.
3. **Datáfonos**: registrar dispositivos por sede o caja, ver su última conexión y enviar un cobro de
   prueba en sandbox.
4. **Transacciones**: todas las transacciones con filtros (estado, medio, fechas, canal), el detalle
   saneado de cada una y la bandeja «Pagos por conciliar».

**Plataforma > «Pasarelas para empresas»** (super usuario): qué proveedores pueden conectar las
empresas, para apagar uno si falla. Es distinto de «Pasarela de pagos», que es la cuenta con que la
plataforma cobra membresías: deja clara la diferencia en ambas pantallas.

**Informes**:
- «Ventas por medio de pago» agrupa por medio configurado, con subtotal por `kind`, y usa
  `NET_AMOUNT_SQL`.
- Columna de comisión estimada y neto.
- Informe nuevo, «Conciliación de pasarelas»: transacciones contra ventas y pedidos, con las
  pendientes y las huérfanas.

### 3.10 Migración de datos

En orden, en migraciones separadas:

1. Por cada empresa, los medios del sistema y los de siempre:
   - Efectivo;
   - Tarjeta (datáfono);
   - Transferencia;
   - Crédito;
   - Saldo a favor;
   - Cambio por devolución (oculto).

   Con el mismo `kind` de los `METHOD_*` de hoy.
2. Rellenar `sale_payments.payment_method_id` desde `method`, y `orders.payment_method_id` desde
   `orders.payment_method`.
3. Cambiar el índice único de `sale_payments` **después** del relleno.
4. Pasar `storefront_settings.payments` de la versión publicada de cada sede a reglas `availability`
   (canal `online`, esa sede):

   | Interruptor de hoy | Medio de pago |
   |---|---|
   | `cash_on_delivery` | Efectivo al recibir |
   | `card_on_delivery` | Datáfono al recibir |
   | `transfer` | Transferencia |
   | `customer_credit` | Crédito |

   Lo apagado queda sin el canal `online` para esa sede.
5. `CompanyProvisioner` crea los medios del sistema a toda empresa nueva, como «Toda sede nace con su
   Bodega principal». Una empresa sin «Efectivo» revienta el primer cobro.
6. Planes existentes: convertir `features` a `entitlements`:
   - todo lo que **no** estaba detrás de una función queda incluido en los tres planes;
   - `online_store` → `storefront.*`;
   - `promotions` → `promotions.*` y `coupons.*`;
   - `multi_warehouse` → `stores.warehouses.create`;
   - `catalog_import` → `products.catalog_import.*`.

   Ninguna empresa debe perder ni ganar acceso por la migración. Escribe una prueba que lo compare
   antes y después.
7. Módulo nuevo `payment_methods` en el árbol de los planes:
   - las páginas de medios manuales, en los tres planes;
   - `payment_methods.gateways.*` y `payment_methods.terminals.*`, por defecto en Pro y Cadena.

   Es solo el punto de partida: el super usuario lo cambia en el editor del plan. Actualiza
   `PlatformSeeder` igual.

### 3.11 Permisos nuevos

Agrégalos a `PermissionHelper`. Cada uno lo exige **al menos una ruta**, y una migración de datos se
los da a quienes hoy administran la empresa (patrón `..._grant_promotion_permissions`).

| Permiso | Para |
|---|---|
| `payment_methods.index.view` / `create` / `edit` / `delete` | La pestaña Medios. |
| `payment_methods.gateways.view` / `edit` / `test` | Cuentas de pasarela. |
| `payment_methods.terminals.view` / `edit` | Datáfonos. |
| `payment_methods.transactions.view` / `reconcile` | Transacciones y «Pagos por conciliar». |
| `pos.sale.gateway` | Cobrar con QR, link o datáfono en la caja. |
| `companies.index.entitlements` | Excepciones y precio especial (super usuario). |

---

## 4. Trampas que vas a encontrar

- **El plan corta el acceso, no los datos.** Bajar de plan nunca borra registros ni permisos asignados.
- **Una sola fuente por pregunta:**
  - qué incluye la empresa → `EntitlementService`;
  - qué medios valen → `PaymentMethodAvailability`;
  - cuánto vale una membresía → `MembershipPricing`.
  Si una pantalla lo calcula por su lado, se desalinea.
- **El `kind` manda las reglas de plata**: cambio, redondeo, arqueo, neto. El nombre del medio es solo texto.
- **Dos pagos del mismo `kind` en una venta** ahora son válidos (Nequi + Daviplata). Revisa cada
  `groupBy('method')` y cada lugar que suponía uno por `kind`: informes, arqueo, tiquete,
  `ReceiptService`, devoluciones.
- **Devoluciones** (`CustomerReturnService`, `SaleService::refund`): el reembolso sale por el medio que
  se elija entre los permitidos para devolver. Una venta pagada por pasarela solo se reembolsa por la
  pasarela si el conector declara `refund`; si no, como saldo a favor o reembolso manual registrado.
- **Centavos**: la plataforma maneja COP entero. Multiplicar o dividir por 100 se hace solo dentro del conector.
- **Nada de llaves en props de Inertia**, en logs ni en `payload`. Revisa también los mensajes de error
  que devuelve la pasarela antes de guardarlos.
- **Webhook antes que el sondeo, o al revés**: los dos caminos terminan en el mismo método, que bloquea
  la fila y es idempotente.
- **Horarios en `America/Bogota`**: un medio «de 7:00 a 21:00» se evalúa con la hora de Colombia, no
  con la del servidor.
- **`RouteSmokeTest`**: las pantallas nuevas tienen que abrir con las props completas. Si alguna
  necesita una cuenta de pasarela que el barrido no monta, exímela por nombre de parámetro.

## 5. Pruebas mínimas

**Planes**
- Un permiso asignado al usuario pero fuera del plan: la ruta responde 403 con el mensaje del plan; el
  menú no lo muestra y la prop de permisos no lo trae.
- `modulo.*` incluye una página agregada después al árbol; un nodo de acción suelto no.
- Excepción `grant` con vencimiento: vale hasta la fecha y no un minuto después (con `Carbon::setTestNow`).
- `revoke` gana a `grant`. `ALWAYS` no se puede quitar. `PLATFORM_ONLY` se rechaza.
- Empresa sin plan: todo permitido.
- Migración de `features` a `entitlements`: mismos permisos efectivos antes y después, para cada
  usuario de las semillas.
- Precio especial: `priceFor` con y sin empresa; no se arrastra al cambiar de plan; la landing no lo ve.

**Medios de pago**
- `PaymentMethodAvailability`: canal, sede, caja, horario (bordes 6:59 / 7:00 / 21:00), vigencia,
  mínimo y máximo, cliente con o sin cupo.
- La caja no puede cobrar con un medio que no vale en ese contexto, aunque el navegador lo mande.
- Nequi + Daviplata + efectivo en una venta; el redondeo solo aplica con todo en efectivo.
- Webhook de cada conector con firma válida, inválida y repetida (`Http::fake` para la relectura);
  monto distinto → `error`.
- Pedido pagado en línea: `pending_payment` → aprobado → `new`; vencido → cancelado y liberado.
- Entrega con menos de lo pagado: la diferencia vuelve como saldo a favor.
- QR en la caja: sondeo y webhook a la vez crean **una** sola venta.
- Aprobación sin venta: aparece en «Pagos por conciliar».
- Migración de `storefront_settings.payments`: cada sede ofrece en línea exactamente lo mismo que antes.

## 6. Fases y criterio de terminado

| Fase | Entrega | Terminado cuando |
|---|---|---|
| A | Nodos, `EntitlementService`, cumplimiento en permisos y migración de `features` | Nadie gana ni pierde acceso; pruebas de la Parte 1 en verde. |
| B | Editor de planes con árbol, excepciones por empresa con vencimiento, precio especial, pantallas de Membresía y landing | El super usuario arma un plan desde la pantalla y una empresa ve lo suyo. |
| C | `payment_methods`, `PaymentMethodAvailability`, caja y tienda con medios manuales, migración de datos | La caja y la tienda se comportan igual que hoy con los medios migrados, y ya obedecen las reglas nuevas. |
| D | Conectores y cuentas de pasarela, checkout en línea, webhooks, vencimiento de pedidos | Pago en línea de punta a punta en sandbox con **cada** pasarela que declare `checkout`. |
| E | QR y link en la caja, «Pagos por conciliar» | Cobro con QR en sandbox; una sola venta aunque lleguen sondeo y webhook. |
| F | Datáfonos integrados donde el conector lo declare | Cobro de prueba en un datáfono de sandbox del proveedor que lo soporte. |
| G | Informes por medio configurado y conciliación | Los totales cuadran con `NET_AMOUNT_SQL` y con las transacciones. |

Al cerrar cada fase:

```bash
npx tsc --noEmit
npm run build
php artisan test
php artisan migrate:fresh --seed
node scripts/codemod-units.mjs --check
```

## 7. Al final

- Agrega a `CLAUDE.md` las trampas nuevas, en el mismo tono:
  - planes por nodos;
  - excepciones que vencen al leer;
  - precio especial solo en `MembershipPricing`;
  - medios de pago por `kind`;
  - `PaymentMethodAvailability` como única fuente;
  - el índice único nuevo de `sale_payments`;
  - pasarelas de empresa y de plataforma separadas;
  - webhooks por cuenta;
  - «Pagos por conciliar»;
  - contrato verificado de cada pasarela.

  Corrige las trampas que quedaron viejas: «Cobro repartido» y «Domicilio, franjas y medios de pago
  son por sede».
- Actualiza el `README.md`: cómo conectar una pasarela en sandbox, cómo exponer el webhook en local y
  cómo correr `payments:expire`.
- No dejes botones, pestañas ni rutas sin acción real, ni permisos que ninguna ruta exija, ni listas de
  medios de pago escritas en un `.tsx`.
