# Notas para trabajar en este proyecto

Sistema de supermercado "AgoraShop": Laravel 12 + Inertia 2 + React 19 + TypeScript estricto.
«La Canasta» es la empresa de demostración, no el nombre de la plataforma (`APP_NAME`, `platform.name`).
El `README.md` explica cómo levantarlo y cómo está armado. Aquí van las reglas que no se ven
leyendo el código.

## Convenciones

| Tema | Regla |
|---|---|
| Código | Inglés: tablas, columnas, modelos, rutas, componentes, variables |
| Interfaz | Español de Colombia |
| Tildes | Todo texto visible va con tildes y eñes. `node scripts/tildes.mjs` falla si reaparece una palabra del diccionario; una cadena que es código va en `ALLOW` o con `tildes-ok` en su línea. Las «dudosas» se deciden a mano |
| Comentarios | Español, y solo donde expliquen una decisión no obvia |
| Moneda | COP entero, sin decimales, en `bigInteger`. Se formatea con `money()` de `lib/format.ts` |
| Impuestos | El precio al público **incluye** IVA; la base se calcula hacia atrás con `Money::baseFromGross` |
| Enums | Nunca `enum` de MySQL: `string` + constantes públicas en el modelo (`Sale::STATUS_PAID`) |
| Validación | Un `FormRequest` por acción cuando la acción tiene su propio formulario |
| Autorización | `permission:<nombre>` en la ruta, siempre. El front solo decide si se pinta el botón |
| Fechas | `America/Bogota`; en BD `dateTime`, no `timestamp` |
| Ajustes | Todo valor configurable pasa por `SettingsService`, con su defecto en `DEFAULTS` |
| Tema | `useTheme(fallback)` aplica `data-theme`; la eleccion vive en `localStorage`, no en la BD |

## Lo que no se hace

- Botones, menús o pestañas sin acción real.
- Rutas declaradas sin controlador, o controladores que devuelvan datos inventados.
- Pantallas alimentadas con arrays literales en el `.tsx`: todo llega por props de Inertia.
- Permisos en el catálogo que ninguna ruta exija.
- Renderizar un botón deshabilitado cuando falta el permiso: simplemente no se pinta.

## Trampas conocidas

- **MariaDB y `timestamp`**: solo la primera columna `TIMESTAMP NOT NULL` de una tabla recibe
  valor por defecto. Usa `dateTime()` para las fechas del dominio.
- **Guards**: la tienda en línea usa el guard `customer`. `TenantContext`, `StoreContext`,
  `CompanyScope`, `AccessLog` y `HandleInertiaRequests` piden el guard `web` de forma
  explícita. Si escribes código nuevo que lea el usuario, haz lo mismo.
- **Windows**: `PHP_CLI_SERVER_WORKERS=1` en `.env`, o `php artisan serve` se cae.
- **Consecutivos**: siempre por `NumberSequenceService`, que bloquea la fila del contador.
  Nunca `count() + 1`. El de ventas va por empresa y prefijo (`sale:C1`, `sale:MOS`), no por sede:
  el numero es unico en la empresa y dos sedes con su «Caja 1» sacaban las dos el C1-000001.
- **Existencias**: solo `StockService` toca `product_stocks`, y siempre dentro de una
  transacción que escriba también `stock_movements`.
- **`StockService` recibe bodega, no sede**: el inventario se lleva por bodega
  (`product_stocks.warehouse_id`, único con `product_id`). `store_id` sigue ahí, desnormalizado
  y siempre igual al de la bodega, porque medio sistema lo usa en joins. Ningún método adivina
  la bodega: quien mueve mercancía dice de cuál, y se la pide a `WarehouseService`.
- **Qué bodega usa cada canal**: caja → `posSourceFor`, tienda en línea → `onlineSourceFor`,
  compras → `receivingDefaultFor`. De cada una hay **una sola** encendida por sede, y quien lo
  hace cumplir es `WarehouseService::assignFlags` dentro de una transacción, no la base: un
  índice único no sabría dejar dos bodegas apagadas. Toda sede nace con su «Bodega principal»
  desde un gancho `created` en el modelo `Store`; una sede sin bodega revienta el primer cobro.
- **El pedido guarda su bodega**: `orders.warehouse_id` se fija al crearlo y no se vuelve a
  resolver. Si cambia cuál bodega respalda la tienda, los pedidos ya tomados se siguen
  alistando y despachando de donde se apartaron. Lo mismo la devolución de una venta: vuelve a
  la bodega del movimiento original, no a la que hoy atiende la caja.
- **Cuentas por sede, no por fila**: con varias bodegas cada artículo tiene una fila por
  bodega. Todo lo que cuente referencias, bajo mínimo o agotados tiene que agrupar por
  `product_id` y sumar (`InventoryController::metrics`, `DashboardService`, `ReportService`,
  `PurchaseSuggestionService`). Contar filas sueltas duplica referencias y marca bajo mínimo lo
  que en la sede sobra: cero en el piso y doscientos atrás no es una compra, es una reposición.
- **Traslados**: `StockTransferService` es el único camino. Por despachar (`draft`) no mueve
  nada; despachar saca del origen y deja la mercancía **en camino**, sin contar en ninguna
  bodega; recibir mete en el destino el despacho completo y da de baja como `waste` lo que no
  apareció, con el número del traslado. Despachar y recibir son **siempre dos actos**, también
  dentro de una sede: el único que los junta es `dispatchAndReceive` (traslado directo), que
  pide `inventory.transfers.direct` y solo existe entre bodegas de la misma sede. Un traslado
  despachado no se anula: se recibe, aunque sea en cero. Los lotes viajan en
  `stock_transfer_items.lots` para que no se pierda el vencimiento, y por eso esos movimientos
  van con `touchLots: false`. Y un traslado **no** recalcula el costo promedio: el costo es del
  artículo, no del estante.
- **Solicitudes de traslado**: `StockTransferRequestService`. Pedir no mueve inventario; quien
  surte genera traslados normales desde la solicitud (`stock_transfers.stock_transfer_request_id`).
  `sent_quantity` no se suma a mano: `StockTransferRequest::syncProgress()` lo recalcula desde
  los traslados no anulados, y `StockTransferService` lo llama al crear y al anular. Si agregas
  otro camino que cree o anule traslados de una solicitud, llámalo también o lo pendiente miente.
- **Entradas y salidas de bodega**: la pantalla de varios artículos entra por
  `inventory.index.adjust`, el mismo permiso del ajuste de a uno. No inventes uno nuevo: en
  una base en uso haría falta una migración de datos para repartirlo. El motivo no tiene
  columna propia: vive en el `type` del movimiento (`waste` sale como avería en los
  informes) y escrito al frente de la nota. Y no recalcula el costo promedio: mercancía que
  entra con un costo nuevo es una compra, y esa es la única puerta que lo recalcula.
- **Precio de un pedido**: `OrderService::create` pregunta a `PromotionService` por el canal en
  linea, igual que el carrito. Si leyera `storePrice()` a secas, el pedido cobraria mas de lo
  que el cliente vio al confirmar.
- **Cupon en la venta**: `SaleService` acepta `coupon_discount` y lo reparte entre las lineas de
  articulo. Va aparte del descuento del cajero: lo que la empresa regalo no lo autoriza nadie en
  la caja, igual que una promocion.
- **Redondeo**: `SaleService` solo redondea cuando el canal es `Sale::CHANNEL_POS` **y** todos
  los pagos son en efectivo. Si lo aplicas a un pedido en línea, la suma de los pagos deja de
  coincidir con el total y la venta se rechaza.
- **`migrate:fresh` con el navegador abierto**: vacía la tabla `sessions` y la pestaña que
  quedara abierta responde 419. Es normal; basta recargar. El 419 ya se maneja con gracia en
  `bootstrap/app.php`, junto con 403, 404 y 500 para peticiones de Inertia.
- **Columnas nuevas en un modelo**: si no las agregas a `$fillable`, `create()` las ignora en
  silencio y la columna queda en `null` sin error. Pasó con `authorized_by_user_id`.
- **Correo**: todo sale por el mailer por defecto, que es `brevo` (relevo SMTP, `config/mail.php`). No le
  pongas `->mailer()` a una notificacion ni cambies `mail.*` en caliente. Un envio dentro de una peticion va
  en `try`: el SMTP puede fallar y el correo no debe tumbar el registro, la venta ni el pedido. El host es
  `smtp-relay.sendinblue.com` a proposito: el relevo al que llega Colombia no trae `brevo.com` en el
  certificado. `php artisan mail:check <correo>` prueba el envio de verdad.
- **Plata que entro por un medio**: nunca sumes `sale_payments.amount` a secas. En efectivo el
  monto trae el cambio adentro; usa `SalePayment::NET_AMOUNT_SQL` en SQL o `netAmount()` en PHP,
  o el arqueo y los informes quedan inflados.
- **Cobro repartido**: una venta admite un pago por **medio configurado** (indice unico
  `sale_id, payment_method_id`; el viejo `sale_id, method` ya no existe). `method` guarda el `kind`,
  asi que Nequi y Daviplata (dos `transfer`) caben en la misma venta; efectivo, uno solo. La suma de
  los pagos debe ser **mayor o igual** al total y solo el efectivo puede exceder; el sobrante es el
  cambio y vive en la linea de efectivo.
- **Redondeo y cobro combinado**: el servidor solo redondea cuando todos los pagos son efectivo.
  `PaymentDialog` aplica la misma regla; si la cambias en un lado, cambiala en el otro o el
  cajero asignara un monto que el servidor rechaza.
- **Diferencia de caja**: `difference_status` solo significa algo cuando `difference != 0`. Un
  turno que cuadra se marca `resolved` al cerrar para que no aparezca en la bandeja. Resolver
  es documentar una decision, no mover plata: si entra o sale efectivo, eso es un `CashMovement`
  del turno donde ocurre.
- **Vista previa del editor**: va en un `iframe` porque `md:`/`lg:` miden el ancho de la
  ventana. Si la metes de vuelta en un `div`, el conmutador celular/computador deja de servir.
- **Tienda responsiva**: `StoreLayout` no encierra el contenido en una columna de celular. La
  navegacion de abajo es `md:hidden` y la de arriba `hidden md:block`; si agregas una pantalla
  de tienda, piensala en las dos anchuras.
- **Bloque nuevo en la tienda**: se escribe una clase en `app/Storefront/Blocks/` que extienda
  `BlockData`, se suma a `BlockCatalogue::TYPES` y se pinta en `StoreBlocks.tsx`. El editor arma
  el formulario solo con lo que diga `fields()`. Si necesita datos del servidor (articulos,
  nombres de categoria), se resuelven en `StorefrontRenderer::resolve`, que es el mismo que
  alimenta la tienda y la vista previa.
- **Domicilio y franjas son por sede**: viven en `storefront_settings` y se editan en la pestana
  Funciones. `SettingsService` ya no los maneja. Lo que el checkout lee sale de `StorefrontConfig`, y
  siempre de la **version publicada**, no del borrador. Los **medios de pago ya no**: son operacion,
  no se publican con la tienda y viven en Medios de pago (`payment_methods`), con la sede y el canal
  como reglas. `storefront_settings.payments` quedo en desuso: solo lo lee `LegacyStorefrontPayments`
  (la migracion que lo paso a reglas).
- **Aspecto de la tienda**: el tema y la estructura llegan por la prop compartida `storefront` (el
  cascaron publicado; `storeTheme` queda para el blade) y los aplica `StoreTheme.tsx`, el mismo componente
  en la tienda y en la vista previa. `--deal-ink` tambien se calcula en el servidor. `--accent-ink` se
  calcula en el servidor (`StorefrontPublisher::ink`): dejarlo fijo en blanco hacia ilegible un
  acento claro, que es justo lo que la verificacion de contraste promete evitar.
- **Iconos de la tienda**: se importan uno por uno en `components/storefront/icons.tsx`. Un `import * as` de
  `@phosphor-icons/react` mete casi cinco megas en el paquete de la tienda. Si agregas un
  nombre a `config/storefront_icons.php`, agregalo tambien al mapa `ICONS`.
- **`isDirty` esta tomado**: Eloquent ya lo define. En `StorefrontBlock` el metodo se llama
  `hasUnpublishedChanges()`.
- **Lleva N paga M**: se cuenta **por articulo** (`nForM` agrupa por `product_id`). Juntar
  articulos distintos en un mismo trio regalaria el mas barato por llevar uno de cada uno, que
  no es lo que anuncia la estanteria. El unico que se cuenta en grupo es el combo.
- **Descuento de promocion vs. del cajero**: `pos.allow_discount_percent` vigila al cajero. Lo
  que rebaja una promocion no puede contar ahi, o un 3x2 pediria firma de supervisor. En
  `SaleService::create` se mide el descuento manual **antes** de aplicar promociones.
- **Totales del POS**: las promociones de cantidad no se pueden calcular en el navegador sin
  repetir la aritmetica del servidor. El tiquete usa `POST /pos/cotizar`; si agregas un tipo de
  promocion nuevo, no lo calcules en el `.tsx`.
- **Deshacer en la caja**: «Vaciar» y «Cancelar cambio» pasan por `offerUndo` (aviso de 8 s y Ctrl+Z).
  `clearTicket` queda para después de cobrar o suspender, sin aviso. Si agregas otra acción manual que
  borre el tiquete, pásala también por `offerUndo`. Deshacer nunca pisa un tiquete nuevo: el aviso se
  va en cuanto se escanea, se elige cliente o se abre otro cambio.
- **Claves que se ven**: si una clave se guarda o viaja al servidor (`work_schedule.miercoles`, el país de
  la importación), no se le pone tilde; se separa la etiqueta (`DAY_LABELS` en `lib/work-days.ts`,
  `{ value, label }`).
- **Precio de un articulo**: nunca lo leas de `products.price` para cobrar. `PromotionService`
  es el unico que sabe cuanto vale hoy, y depende del canal. Si agregas una pantalla que
  muestre precios y otra que cobre, las dos tienen que preguntarle al mismo servicio o el
  cobro no cuadra con lo que vio el cliente.
- **Linea sin articulo**: `SaleService::create()` admite una linea con `description` y sin
  `product_id` (asi entra el domicilio). Esa linea no mueve inventario y su costo es cero; si
  recorres `sale->items` para tocar stock, saltate las que no tienen producto.
- **Importar una maestra nueva**: se escribe una clase en `app/Imports/` que extienda
  `MasterImport`, se suma a `ImportRegistry::IMPORTS`, se agrega la accion `import` a la
  pagina `index` del modulo en `PermissionHelper` y se declaran las dos rutas **antes** de
  las que llevan parametro, o `{model}` se traga el `/importar`. La pantalla y el lector de
  CSV ya son compartidos: no escribas otro.
- **Dos importaciones distintas**: `/productos/importar` carga la maestra por **SKU**, con
  precios, en una transaccion y sin bitacora. `/catalogo/importar` trae de una fuente externa
  por **EAN**, en cola, por bloques y dejando rastro por articulo. No se mezclan.
- **EAN siempre string**: en PHP, en TypeScript, en el JSON y en la base. Un `(int)` o un
  `parseInt` se come los ceros de la izquierda —que son significativos— y el escaner de caja
  deja de encontrar el articulo. `ProductNormalizer::ean()` ademas valida el digito de
  verificacion GTIN y normaliza el UPC de 12 digitos a 13.
- **Open Food Facts responde 200 con `status: 0`** cuando el codigo no existe. Nunca te fies
  solo del codigo HTTP. El `page_size` maximo real es 100 aunque pidas mas, y el uso justo
  pide no pasar de una peticion por segundo.
- **Reimportar no pisa lo editado**: `CatalogImporter` solo rellena campos vacios. Ni
  `category_id`, ni `is_active`, ni precios. Si agregas un campo a `FILLABLE_ON_UPDATE`,
  piensa si el comerciante pudo haberlo cambiado a mano.
- **Lo importado entra apagado y en cero**: la fuente no trae precios, y un articulo activo
  con precio cero es un articulo que la caja regala.
- **La importacion se despacha a la cola**: `php artisan queue:work --queue=imports`. Sin
  worker se queda en «En espera», y para eso esta el boton **Importar ahora**, que corre el
  mismo job dentro de la peticion (`CatalogImportController::run`). Ahi no hay reintentos:
  lo que falle se cierra en el acto llamando a `failed()` a mano.
- **El ritmo de la fuente lo lleva el cliente**, no quien lo llama: `OpenFoodFactsClient`
  espera lo que falte antes de cada peticion real. Un acierto de cache no paga esa espera, y
  ningun camino nuevo puede olvidarse de respetar el uso justo. En pruebas se apaga con
  `CATALOG_OFF_DELAY_MS=0` en `phpunit.xml`.
- **La categoria de destino vive en `criteria['category_id']`**: sin ella no se podria repetir
  ni ejecutar una corrida sin volver a preguntarla.
- **Un articulo tiene varios proveedores**: viven en `product_suppliers`. `products.supplier_id`
  se conserva como **principal** (atajo que usa medio sistema) sincronizado con `is_primary`.
  La pantalla de compras resalta por `product_suppliers`, no por `products.supplier_id`: asi un
  articulo que surten dos se resalta con los dos.
- **En compras el proveedor no filtra, resalta**: el catalogo se ve completo y se le puede
  pedir cualquier cosa a cualquiera. Lo que no sea suyo se marca `is_off_supplier` y la orden
  se puede separar en una por proveedor (`PurchaseOrderSplitter`). Si vuelves a filtrar por
  proveedor, se pierde la razon de ser del modulo.
- **Una compra llega por partes**: `received_quantity` de la peticion es **lo que llega ahora**,
  no el acumulado; el servidor lo suma. Mientras falte algo la orden queda en `partial` y se
  puede volver a recibir; `received_at` solo se llena cuando entro todo. Cada remesa deja su
  propio lote en `product_lots` y su propio movimiento en el kardex.
- **Una orden con mercancia adentro no se anula ni se separa**: anularla dejaria el inventario
  contando algo que la orden dice que nunca paso. Se cierra con `purchases.close`, que no mueve
  existencias: lo que llego ya entro cuando llego.
- **La sugerida la calcula el servidor**: `PurchaseSuggestionService`. Lo que manda el navegador
  son solo cantidad y costo; proveedor, existencia, minimo y sugerido se recalculan al guardar.
- **`last_cost` se escribe al recibir, no al pedir**: lo que se pidio es una intencion, lo que
  llego es lo que costo. Y se le anota al proveedor **del renglon**, no al de la orden, o la
  proxima sugerencia de precio saldria del proveedor equivocado.
- **Ventas no es Pedidos**: `sales` es plata cobrada, `orders` es un encargo por alistar. Un
  pedido genera su venta al entregarse (`OrderService::deliver`). No mezcles los listados.
- **Permiso nuevo en el catalogo**: agregarlo a `PermissionHelper` no se lo da a nadie, porque
  lo que manda son los permisos directos del usuario. En una base que ya esta en uso hace falta
  una migracion de datos que lo reparta (ver `..._grant_sales_history_permission`).
- **Tema por superficie**: el inicial sale de `panel.default_theme` / `pos.default_theme`, no de la
  ruta. Si agregas una pantalla con `PosLayout`, suma su ruta a `Surface::POS_PATHS`: de ahi sale
  `data-surface` en `app.blade.php`, y sin el se pinta con la escala y el tema del panel un instante.
- **GD**: `ImageService` lo necesita para las miniaturas. Sin la extensión guarda el original
  sin romperse, pero `UploadedFile::fake()->image()` sí revienta en pruebas: usa
  `->create('x.jpg', 120, 'image/jpeg')` si no quieres depender de GD.
- **Mapas**: el proveedor es de la plataforma (Plataforma > Mapas, claves `maps.*` de `PlatformSettingsService`) y
  la direccion de las teselas y el credito los arma solo `MapConfig` (`current()`, `build()`): el navegador nunca arma
  una URL de proveedor. Solo viaja la llave del proveedor en uso, y es de navegador: se protege restringiendola al
  dominio en la consola del proveedor, no escondiendola. El credito libre del personalizado se escapa (se pinta como
  HTML). Un proveedor de teselas nuevo es una URL en `MapConfig`, no otro motor: Leaflet y Google son los dos unicos y
  cada uno es su propio paquete (`create-map.ts`).
- **Pintar un mapa**: siempre con `MapCanvas` (o `LocationPicker` para elegir un punto). Descarga el motor solo cerca
  de la pantalla y dice cuando el proveedor falla en vez de quedar en gris. Los marcadores son elementos propios
  anclados con CSS (`maps.css`); lo que va encima del mapa lleva `map-overlay` o queda debajo de los controles de
  Leaflet, y nunca tapa el credito del proveedor: la licencia pide que se vea.
- **Buscador de la landing**: las claves `map_*` de `StoreFinderBlock`; un bloque o una version publicada sin ellas
  vale con sus defectos (mapa al lado de la lista). La ventana ampliada comparte el estado del buscador (filtros,
  sede elegida, ubicacion): no le pongas filtros propios. «Buscar en esta zona» acota la lista, no el mapa.
- **Tres diseños del buscador** (`layout`: `list_map`, `nearest_first`, `by_company`): el mapa que admite cada uno
  vive en `StoreFinderBlock::MAP_LAYOUTS` y lo corrige `normalize()`. `LandingRenderer::resolve` normaliza cada bloque
  (tambien lo publicado): el `.tsx` no repite esa tabla. Los campos de un diseño llevan `when` y el formulario los oculta
  sin borrarlos. El domicilio de cada sede (`delivery`) sale de su version publicada, todas en una consulta
  (`StorefrontConfig::deliverySummary`); `fee_from` con zonas es la zona mas barata, porque el checkout obliga a elegir
  una. `next_slot` cuenta cupos: solo si un buscador visible tiene `show_next_slot` (`wantsNextSlot`), tambien en
  `landing.nearby`.
- **Archivos adjuntos**: siempre por `App\Support\Uploads`. Se guarda en `Uploads::diskName()` (lo público)
  o `Uploads::privateDiskName()` (devoluciones, importaciones), y el enlace sale de `Uploads::url()`. Nunca
  `Storage::disk('public')`, `'/storage/'.$path` ni `asset('storage/...')`: con `UPLOADS_DISK=firebase` el
  archivo vive en Firebase y ese enlace no existe. Al navegador le llega la URL ya resuelta (`*_url`), no la
  ruta. Lo privado no tiene enlace (`getUrl` revienta): se sirve con `Uploads::privateDisk()->response()`
  detras de un permiso. Si una libreria necesita archivo en disco (PDF, lector CSV), `Uploads::localPath()`.
  `MediaAsset` guarda su disco por fila; `uploads:firebase` lo cambia al migrar.

- **Marca de la plataforma** (VentaIO): logo, logo oscuro, isotipo (`platform.brand_*`), favicon e imagen para
  compartir (`landing.favicon`, `landing.seo_image`) solo visten lo que no tiene empresa: landing, contratacion,
  ingreso con varias empresas y el panel del super usuario sin empresa (`BrandThemeService::platformSettings`).
  Una empresa sin logo se presenta con su nombre y su canasta, nunca con la marca de la plataforma, y ninguna
  escribe en `landing/brand/`. Los originales viven en `resources/brand/ventaio`; `php artisan platform:brand`
  los sube al disco de archivos (Firebase en produccion) con el hash en el nombre. Si cambias uno, vuelve a correrlo.
- **Nombre de la marca**: sin `brand.display_name` propio, `SettingsService` devuelve el nombre de la
  empresa. El defecto de `DEFAULTS` es el de la primera empresa; si lo lees a mano por otro lado,
  una empresa nueva sale llamandose «La Canasta».
- **Marca del panel y la caja**: vive en ajustes (`brand.*`, `appearance.*`, `panel.*`, `pos.*`), no en
  `companies.logo` ni en literales. `BrandThemeService` la vuelve tokens y el blade la inyecta en
  `#brand-theme` **despues** de `@vite`, con selectores de especificidad doble para ganarle a `app.css`
  aunque Vite la inyecte despues en desarrollo; `BrandStyle` la mantiene al dia en las visitas de
  Inertia. Si la empresa no toco nada, no se inyecta nada y manda `app.css`.
- **Color de marca**: la interfaz nunca usa el color crudo si no se lee. `resolveAccent` lo acerca a
  negro o a blanco hasta 4,5:1 contra superficie, fondo y tinte. `App\Support\Color` y `lib/color.ts`
  hacen la misma cuenta (`ColorParityTest` la compara con Node): si cambias una, cambia la otra.
- **CSS de marca**: se arma solo con valores validados (hex por regex, enums de listas cerradas). Nunca
  metas texto libre en `#brand-theme`: se imprime con `{!! !!}`.
- **Tamaños y esquinas**: nada de `text-[13px]` ni `rounded-[10px]` nuevos. Tamaños en `rem` (escalan
  con `--ui-scale`) y esquinas con `rounded-chip/control/card/sheet`. Los bordes si van en px.
  `node scripts/codemod-units.mjs --check` falla si queda algo por pasar. La tienda en linea queda
  por fuera: tiene su propio editor de aspecto.
- **Tabla o tarjetas**: todo listado va con `Table` (o envuelto en `Table`, aunque adentro lleve `<thead>`/`<td>`
  a mano), y asi trae el conmutador Tabla/Tarjetas. Las tarjetas son la misma tabla con otro CSS (`.data-table` en
  `app.css`): `Table` le pone a cada celda el titulo de su columna leyendo el encabezado del DOM; la primera columna
  con titulo es el de la tarjeta, una sin titulo o «Acciones» va sin rotulo de lado a lado, y una fila de una sola
  celda ocupa todo el ancho. No escribas una tarjeta aparte por pantalla. La eleccion es de quien mira
  (`localStorage`, `useTableView`), una por pantalla; `name` separa dos listados de la misma pantalla y
  `switchable={false}` apaga el conmutador en lo que no es un listado (la vista previa de Ajustes). Las matrices
  (existencias por talla y color, matriz de compras) y el tiquete quedan como `<table>` sin tarjetas.
- **Densidad**: solo la aplican `Card`, `Table`, `Field` y el `Button` mediano (`calc(... * var(--density))`).
  La caja la ignora: `html[data-surface='pos']` fija `--density: 1`.
- **Menu configurable**: `navigation.ts` es el catalogo; la empresa solo guarda diferencias
  (`panel.navigation`). La configuracion oculta, el permiso filtra despues, y nunca al reves. `admin`,
  `company` y `settings.index` no se pueden ocultar: ahi vive Ajustes (`NavigationOverrides::LOCKED`).
- **Ajustes por sede**: solo los del tiquete, en `store_setting_overrides`, no en `settings` con
  `store_id` nulo (MariaDB deja repetir `NULL` en un indice unico). Se leen con `forStore()` y se
  escriben con `setForStore()`; `null` quita la excepcion.
- **Formularios de Ajustes**: los datos viajan anidados (`{brand: {accent}}`), porque el validador lee
  `brand.accent` como `brand[accent]`. Una clave con punto literal no la encontraria y el ajuste no
  se guardaria. `SettingsSchema::pluck()` los vuelve planos para guardar.
- **Imagenes de marca**: `storeBrandAsset` guarda PNG con transparencia; `store()` guarda JPEG y le pone
  fondo al logo. SVG no se acepta sin saneador. El logo del tiquete se regenera solo cuando cambia el
  logo claro o el isotipo (`BrandAssetService::refreshReceiptLogo`).
- **Lo propio de la caja** (disposicion, atajos, sonidos) viaja como prop `posLayout` de `Pos/Sale`; el
  orden del riel, como `posRail`, solo en las rutas de caja. No los metas en la prop `brand`.
- **Tiquete**: `ReceiptService::viewData()` lo arman el HTML, el PDF y la vista previa de Ajustes. NIT,
  base e IVA salen siempre. `ReceiptPreview.tsx` repite el orden de `pos/receipt-print.blade.php`:
  si cambias uno, cambia el otro.
- **Empresa activa**: se lee con `TenantContext::activeCompanyId()` / `TenantContext::company()`, nunca con
  `$user->company`. El super usuario no tiene empresa propia: la elige en el selector y vive en sesion.
  Sin elegir esta en consolidado, y las rutas de empresa pasan por `company.selected`.
- **Plataforma > Usuarios**, dos pestañas. *De plataforma* (`PlatformUserController`): super usuarios, rol global
  `super_admin` (sin `company_id`), sin empresa ni sedes; no se eliminan, se desactivan (firman lo de plataforma y el
  indice unico de `users.email` cuenta tambien las filas borradas). *De empresas* (`PlatformCompanyUserController`):
  todas las cuentas de todas las empresas; la empresa se elige al crear y no se cambia. Cada pestaña responde 404 a
  la cuenta de la otra. Crear y editar cuentas de empresa va siempre por `UserAccountService` (tambien desde
  Usuarios dentro de la empresa): tope del plan, clave temporal, rol con su plantilla y sedes. Las sedes del listado
  se leen sin `CompanyScope`, o la empresa elegida en el selector esconderia las de las demas.
- **Aprovisionar una empresa**: siempre por `CompanyProvisioner`, que usan Empresas, la contratacion en linea
  y la carga masiva. Una empresa creada por otro camino queda sin roles, sin caja o sin tienda.
- **Membresia**: el estado lo mueve `MembershipService` (y el comando `membership:process`), nunca un
  update suelto. `EnsureMembershipActive` bloquea a las suspendidas; los topes del plan los revisa
  `MembershipLimiter` en cada alta.
- **Precio de una membresia**: solo `MembershipPricing::priceFor($plan, $cycle, $company)`. Con la empresa
  aplica su precio especial vigente (`company_plan_prices`); la landing, «Contratar», el cobro y el MRR de la
  plataforma preguntan ahi. Ni la landing ni el `.tsx` lo calculan.
- **Planes por nodos**: un plan guarda `entitlements` (`modulo.*`, `modulo.pagina.*`, `modulo.pagina.accion`),
  no funciones. `Entitlements` los expande contra el catalogo de `PermissionHelper`; `EntitlementService::for()`
  resuelve plan → excepciones que agregan → excepciones que quitan → `Entitlements::ALWAYS`. Lo de
  `PLATFORM_ONLY` (`platform.*`, `companies.*`) nunca entra a un plan. Una empresa sin plan lo tiene todo.
- **El plan intersecta, no borra**: `EffectivePermissionService` cruza los permisos directos del usuario con el
  plan. Lo asignado fuera del plan se conserva y vuelve solo si el plan lo incluye otra vez; Roles y Usuarios
  solo pintan lo del plan y `syncWithinPlan` no toca lo de afuera. El super usuario no se limita. Un 403 por plan
  dice «Tu plan no incluye ‹etiqueta›. Cámbialo en Ajustes > Membresía.» y queda en la bitacora como
  `plan_denied`. `hasFeature()` sigue vivo, traducido a nodos en `Entitlements::FEATURE_NODES`.
- **Excepciones de plan vencen al leer**: `company_entitlements.ends_at` no lo apaga ningun proceso;
  `EntitlementService` ignora lo vencido cuando arma el permiso (memoizado por peticion, recalculado al cruzar
  el vencimiento). `membership:process` solo avisa tres dias antes y deja constancia cuando vence.
- **Tarjetas**: el numero y el CVC nunca llegan al servidor; se tokenizan en el navegador contra Wompi y se
  guarda la fuente de pago. Un FormRequest que acepte `card_number` es un error.
- **Webhook de Wompi**: firma, relectura de la transaccion e idempotencia por `reference`, en ese orden.
  Cobro nuevo: primero la fila `pending` (confirmada en la base), despues la pasarela.
- **Tienda multiempresa**: la empresa de la tienda sale de la sede elegida (`StorefrontContext`), nunca de
  «la primera empresa». Cambiar de empresa cierra la sesion del cliente y usa otro carrito;
  `CartService` nunca mueve un carrito entre empresas.
- **Clasificacion de empresa** (alimentos, textiles, belleza…): catalogo de plataforma en `business_categories`
  (`BusinessCategoryCatalog::DEFAULTS`), una por empresa en `companies.business_category_id`. Se elige al crear
  (Empresas), al contratar (`SignupRequest`, la guarda `company_signups` hasta que se paga) y en la carga masiva
  (columna `clasificacion`; vacia = alimentos, como antes). Desactivar una solo la quita de las opciones: las
  empresas que la tienen la conservan y la landing las sigue agrupando ahi. Siempre queda una activa. Su icono
  es de `config/landing_icons.php` y se pinta con `landingIcon()` de `landing-icons.ts`, el mismo mapa en la
  landing, el panel y «Contratar».
- **Landing de plataforma**: bloques en `app/Landing/Blocks/`, datos por `LandingRenderer::resolve()`, sin
  SQL libre. `store_finder`, `header` y `footer` no se borran.
- **Imagenes y videos de la landing**: un campo `image` o `video` guarda la ruta bajo `landing/`; la direccion la pone
  `LandingBlock::withMediaUrls` (`clave_url`, tambien dentro de las listas) y el editor la recibe de `mediaUrlMap` (por
  clave, y por ruta lo de las listas). El video se sube aparte (`uploadVideo`, MP4 o WebM hasta
  `VideoOptions::MAX_VIDEO_KB`).
- **Videos (landing y tienda)**: siempre con `useLoopingVideo`/`VideoFrame` de `components/media/looping-video.tsx`:
  sin sonido, sin barra, solo mientras se ve, pausado si piden menos movimiento, y el archivo no se pide hasta que esta
  por aparecer. Formatos, topes y reglas de subida en `App\Support\VideoOptions` (`uploadRules`), los mismos en las dos.
  `php artisan serve` no atiende rangos de bytes y entrega un archivo a la vez: ahi saltar dentro de un video no
  funciona y varios videos grandes en una pagina se estorban; en Firebase no pasa.
- **Opciones de video**: velocidad, arranca solo, se repite, como llena, esquinas, sombra y marco viven una sola vez en
  `App\Support\VideoOptions`; cada bloque con video declara cuales ofrece en `VIDEO_OPTIONS` y el front las lee con
  `videoOptions()`. El fondo ya no es del bloque sino de su pestaña «Diseño» (`appearance`, ver «Diseño de sección»):
  lo pinta `SectionShell`, sin `data-theme` anidado porque el acento de la sede viaja en linea y un tema anidado le
  pondria encima el de fabrica. Con color propio la letra sale de `inkProps()`, y el acento sobre fondo oscuro de
  `--sec-accent`.
- **Videos de la tienda**: son filas de `media_assets` con `mime` `video/...` (`isVideo()`, `ofKind()`), con su
  descripcion obligatoria. El campo es `video` y su clave termina en `_media_id` (`video_media_id`), asi publicar,
  pintar e independizarse los encuentran como a una imagen. La biblioteca sin `kind` devuelve solo imagenes, y el
  renderizador no pinta un video en un campo de imagen ni al reves (`mediaPayload`/`videoPayload`).
- **Demo del sistema** (`app_demo`): los datos de ejemplo salen de `DemoWorkspace` (por servidor, como todo dato de
  pantalla) y lo que el visitante hace vive en el estado de `AppDemo.tsx`. No le agregues rutas ni consultas: la demo
  no toca la base (`LandingMediaAndDemoTest` lo mide). Un modulo nuevo es una entrada en `DemoWorkspace::MODULES`, sus
  datos en `build()` y su vista en `ModuleView`. Nombres genericos, sin marcas reales: la landing es publica.
- **Ajustes de plataforma**: por `PlatformSettingsService`, con su defecto en `DEFAULTS`; los de empresa
  siguen en `SettingsService`.
- **Wompi, contrato verificado (sep. 2026)**: tokens de aceptacion en `GET /merchants/info` con el encabezado
  `x-merchant-public-key` (la forma `/merchants/{llave}` se apaga el 31/10/2026). El `checksum` de los
  eventos llega en mayusculas: se compara sin importar mayusculas. La marca de la tarjeta se deduce del
  BIN que devuelve Wompi, no de lo que diga el navegador. Todo esta resumido en `config/wompi.php`.
- **Sede vendible**: una sola regla, `StorefrontContext::sellable()` (sede activa; empresa activa, sin
  suspender, con `storefront.*` en su plan; tienda encendida y publicada). La usan la landing, el buscador, el enlace
  `/tienda/{empresa}/{tienda}` y `setStore()`. Una empresa aprovisionada nace con la tienda apagada; la
  primera publicacion la enciende. Un nombre de ruta fijo de `/tienda` va en `Company::RESERVED_SLUGS`.
- **Semillas con historia** (`FashionCompanySeeder`): el reloj se mueve dia por dia con
  `Carbon::setTestNow` (y `CarbonImmutable`) para que kardex, cajas e informes tengan sus fechas,
  y se suelta en un `finally`. Nada de «hoy» queda en el futuro: `at()` lo recorta a la hora real.
  Las compras siguen los pasos de `PurchaseOrderController::receive`; si cambias la recepcion,
  cambia tambien la semilla.
- **Carga masiva**: `MasterImportService::run()` guarda cada fila en su propio punto de guardado y con
  `dryRun` es la vista previa. Nada con efectos fuera de la base (correos, archivos) va en `created()`
  sin `DB::afterCommit`: en la vista previa todo se deshace y no debe salir ningun correo.
- **Cliente de la caja que se registra en linea**: nunca se le pone contraseña directo; se activa con el
  codigo que llega al correo de su ficha (`CustomerRegisterController`).
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
  de pago: ya estaba pagada. Si esa orden se anula, su respuesta se borra y la devolucion vuelve a quedar
  pendiente (`SupplierReturnService::replacementCancelled`).
- **Rutas de devoluciones antes de `compras/{order}`**, o `{order}` se traga «devoluciones».
- **Lote de una compra**: `receiveLot()` recibe `purchaseOrderItemId` al recibir una orden; asi una
  devolucion con orden saca justo el lote que entro con ella. Los lotes viejos no lo tienen: el respaldo es
  el `lot_code` del renglon y despues FEFO.
- **Servicios no son articulos**: viven en `services`, sin existencias. Lo que consumen esta en
  `service_components` y sale del inventario como lineas hijas de la venta (`parent_sale_item_id`), por
  `StockService`, desde la bodega de la venta. Nunca metas un servicio en `products` ni le crees
  `product_stocks`.
- **La venta no depende del maestro**: la linea del servicio guarda `service_snapshot` (maestro, quitados,
  quien autorizo) y cada componente su `master_quantity` y `component_change`. Cambiar el servicio manana no
  cambia ninguna venta pasada.
- **Ajustar un servicio en caja se autoriza en el servidor**: `SaleService` compara lo que llega contra el
  maestro (`ServiceSaleBuilder`) y exige `pos.sale.service_remove/reduce/increase/add` o un `approver` para
  esa accion. El `.tsx` solo decide si pinta el control.
- **Precio de un servicio**: solo `ServicePricing::priceFor()`, por canal. En `bundled` los componentes
  valen $0; en `itemized` se cobran al precio fijado en el servicio (`service_components.unit_price`, el
  mismo en caja y tienda, sin promociones) o, si esta vacio, al vigente de `PromotionService` sin
  promociones de cantidad. Un articulo agregado en caja va siempre al vigente. Cambiar ese precio, como
  la forma de cobro, pide `services.show.edit_price`.
- **Cupon y componentes**: el cupon se reparte entre lineas cobradas; los componentes de $0 y la linea del
  domicilio no reciben.
- **Contar unidades vendidas**: excluye los componentes de $0 (`SaleItem::COUNTS_AS_SOLD_SQL`), o un
  servicio cerrado infla los «mas vendidos». Lo que suma plata no cambia.
- **Devoluciones por linea**: `refundedQuantities()` suma por `refunded_from_sale_item_id`. No vuelvas a
  agrupar por `product_id|description`: un articulo suelto y el mismo dentro de un servicio se confunden.
- **Codigo de servicio**: no puede ser el SKU ni el codigo de barras de un articulo, y al reves
  (`ScanCodeGuard`). El escaner de caja no puede tener dos respuestas.
- **Pedido con servicio**: la fila del servicio va sin `product_id` y sus articulos cuelgan de ella
  (`parent_order_item_id`). Se reservan y se alistan los hijos; la fila del servicio no se alista. Al
  entregar, la venta nace con lo alistado y con el precio del pedido (`price_locked`), sin volver a
  preguntar al maestro.
- **Devoluciones de venta**: `CustomerReturnService` es el unico camino. En caja nace resuelta (`registerAtPos`,
  `exchangeAtPos`); en la tienda el cliente la pide (`requestOnline`) y pasa por aprobar, recibir (ahi se
  decide el destino de cada articulo) y resolver. La plata sale por `SaleService::refund` (venta en negativo)
  con `options`; el inventario lo mueve la devolucion **al resolver**, con la DV como referencia del kardex, y
  por eso llama `refund` con `move_stock: false`. Averia = entra (`return`) y se da de baja (`waste`).
- **Lo que queda por devolver** lo da `returnableQuantities()`: lo vendido menos lo devuelto menos lo que apartan
  las devoluciones abiertas. `refundedQuantities()` cuenta por `refunded_from_sale_item_id`, no por la nota.
- **Una venta con devoluciones no se anula** (`SaleService::assertCancellable`): el inventario y la plata ya
  se movieron por otro documento. Tampoco una devolucion ni una venta de cambio.
- **Saldo a favor**: solo `StoreCreditService`, con la fila del cliente bloqueada. Se gasta primero lo que vence
  antes; `customers.store_credit_balance` es la suma del libro, no un contador. Un pedido lo aparta al
  confirmar (`order_hold`), lo devuelve si se cancela y la venta de la entrega lo cobra sin volver a
  descontarlo (`store_credit_held`). Al consumidor final generico (documento de puros 2) nunca se le abona.
- **Cambio en caja**: el medio `exchange` solo lo acepta `SaleService::create` con `exchange_limit`, que pone
  `exchangeAtPos`. Sale en negativo en la devolucion y en positivo en la venta nueva: se anula solo en los
  informes por medio de pago. `StorePosSaleRequest` no lo ofrece.
- **Plazo de devolucion**: `returns.max_days`, desde la venta o la entrega del pedido. Fuera de plazo, en caja se
  autoriza con PIN (`return_window` → `sales.returns.approve`); en la tienda ya no se puede pedir.

- **Tienda por paginas**: `storefront_blocks.page` (home, category, product, cart, search) y cada tipo declara
  en que paginas vale (`BlockData::pages()`). Una instantanea vieja sin `page` es portada.
- **Estructura global de la tienda** (barra superior, encabezado, menu, tarjeta, barra del carrito, navegacion
  del celular, pie, paginas de categoria/producto/busqueda) vive en `storefront_settings` con su defecto en
  `StorefrontManager::defaultSettings()`: se versiona y se publica con los bloques. No la pongas en el `.tsx`.
- **Un solo `ProductCard`** para toda la tienda y la vista previa. El precio por unidad lo calcula el
  servidor (`StorefrontProducts::pricePerUnit`, el unico calculo); la imagen llega resuelta (`image_path` → `thumb_path` →
  `image_url`).
- **Varias fotos por ficha**: articulo (`product_images`), articulo principal (`product_family_images`) y servicio
  (`service_images`). Cuantas, lo decide la empresa (`catalog.max_images`, Ajustes > Catalogo, 1 por defecto, hasta
  `CatalogImages::MAX_LIMIT`) y lo mide el FormRequest con lo que la ficha ya tiene mas lo que llega. Bajar el tope no
  borra nada: solo no deja subir. Subir, cambiar la portada y borrar archivos pasan por `CatalogImages`; la portada es
  la primera por `sort_order` y, en articulo y servicio, tambien queda en `image_path` (lo que leen listados, caja y
  tarjeta). La tarjeta muestra la portada; la ficha recorre todas en `ImageCarousel`, con lo que arma
  `StorefrontProducts::gallery()`/`serviceGallery()` (el mismo metodo en la tienda y la vista previa).
- **Carrito de la tienda**: se agrega con `PUT /tienda/carrito/cantidad` (cantidad absoluta, idempotente) y el
  resumen viaja en la prop `cart`. El navegador es optimista pero el total siempre sale del servidor.
- **Domicilio gratis**: `delivery.free_from = 0` significa que no hay; nunca pintes «gratis desde $ 0».
- **Panel del menú de categorías**: lo más vendido de la categoría (con sus subcategorías) llega por
  `GET /tienda/menu/categoria/{id}` (`StorefrontListing::top`), con `q` para buscar dentro. Es el mismo
  `ProductCard` y el mismo precio vigente; no pongas productos del menú en el cascarón (`storefront`): se
  piden al señalar la categoría.
- **Vista previa honesta**: un bloque que no pintaría nada (carrusel sin láminas, estante sin artículos,
  oferta relámpago sin promoción) sale en la vista previa como un aviso de lo que le falta
  (`emptyReason` en `StoreBlocks.tsx`), y uno oculto lleva la marca «Oculto». Si agregas un tipo de bloque,
  dile ahí cuándo está vacío, o quien lo agregue creerá que el editor no lo dejó.
- **Entrar en la tienda es un diálogo**, no una página: todo «Entrar» llama `openLogin()` (`login-dialog.ts`) y
  `LoginDialog` vive una sola vez en `StoreLayout` (hoja que sube en el celular, ventana en el computador).
  El servidor vuelve a `return_to`, pero solo toma ruta y consulta bajo `/tienda`, nunca el dominio. La página
  `/tienda/ingresar` queda para quien llega sin sesión a una ruta protegida, con el mismo `LoginForm`.
- **Plantilla de tienda**: diseño = `StorefrontTemplate::DESIGN_KEYS`; marca, domicilio, pagos, contacto y
  datos legales son siempre de la sede. Una plantilla nunca guarda ids de articulos, categorias ni
  promociones (`fields('template')`). Independizarse copia los medios de plataforma a la biblioteca de la
  empresa; no referencies medios de plataforma desde una tienda independiente.
- **Medios de plataforma**: `media_assets.company_id` nulo. Nunca los muestres en la biblioteca de una empresa.
- **Diseño de sección (tienda y landing)**: un solo catalogo, `App\Support\SectionAppearance` (`groups($surface)`), guardado
  en `payload.appearance` (tienda) o `data.appearance` (landing). Se guarda escaso: solo lo que difiere del defecto del
  tipo (`appearanceDefaults()`), y un bloque que nunca toco «Diseño» no lleva la clave y se ve como antes. Lo pinta
  `components/sections/SectionShell.tsx`, lo traduce `appearance.ts` y lo edita `DesignPanel.tsx` (el mismo en los dos
  editores). Agregar un ajuste = entrada en el catalogo + su traduccion en `appearance.ts`; el editor lo pinta solo. Un
  valor neutro no emite atributos: si lo cambias, las tiendas que nunca tocaron Diseño cambian.
- **`BlockBackground` y `LandingBackground` ya no existen**: el `background` de un bloque de tienda y el `tone` de uno de
  landing se migraron a `appearance` (`..._move_section_backgrounds_to_appearance`), y `BlockData::upgradeBackground` /
  `LandingBlock::upgradeTone` convierten al vuelo las instantaneas viejas (versiones, plantillas). No vuelvas a leer
  `background` en un `.tsx`.
- **Cuatro versiones de cada imagen o video de banner** (computador y celular, en modo claro y en modo oscuro):
  `App\Support\MediaVariants`. La de computador en claro es la clave de siempre (`media_id`, `video_media_id`, `image`);
  las otras son claves hermanas con nombre fijo (`media_mobile_id`, `dark_media_id`, `dark_media_mobile_id`;
  `video_dark_media_id`…; en la landing `image_mobile`, `image_dark`, `image_mobile_dark`), asi `isMediaKey`, publicar,
  pintar e independizarse las encuentran sin saber de versiones. Un campo nuevo se declara con
  `MediaVariants::field(...)` (el principal lleva `variants`, las hermanas `variant_of`) y el editor las pinta juntas
  (`MediaVariantsField` / `LandingMediaVariants`); `normalize()` las completa en nulo sin repetirlas en los defectos.
  Ninguna es obligatoria: en oscuro manda el modo (oscura de celular, oscura de computador, y si no hay oscuras, las
  claras); la regla esta una vez en el servidor y una en `lib/media-variants.ts`. El renderizador las manda como
  `media`, `media_mobile`, `media_dark`, `media_mobile_dark` (`StorefrontRenderer::variants`); en el `.tsx`,
  `BannerImage {...bannerMedia(item)}` o `useMediaVariant` para videos. La falta de una oscura solo se avisa al
  publicar si la tienda se ve en los dos modos (esquema `auto` o conmutador), nunca bloquea.
- **Diseño de modo oscuro**: en la pestaña «Diseño» solo el grupo `background` (`THEMED_GROUPS`, marcado `themed`) puede
  cambiar en oscuro, y se guarda en `appearance.dark` con lo que difiere de claro. El servidor lo manda resuelto
  (`appearance.dark` entero, o `null`) y `SectionShell` lo elige con `appearanceFor()`. En una lamina, lo de su grupo
  `design` en `fila.dark` (`themedItem()` en `banner-style.ts`). Tamaño, espacio y animacion son los mismos en los dos modos.
- **Modo que revisa el editor**: `EditorSchemeContext` (`editor-scheme.tsx`), uno por editor (tienda, plantilla, landing).
  Lo cambian los conmutadores de la vista previa, de «Diseño» y del diseño de la lamina, y viaja a la vista previa como
  `scheme` (libre de firma en `PreviewController::FREE_PARAMETERS`), que fuerza el tema sin tocar lo que escogio quien mira.
- **Diseño de cada banner**: `item.style` en los repetidores con imagen (laminas de portada, mosaicos, laterales), con
  sus listas en `App\Storefront\Blocks\BannerStyle` y `BannerStyle::field()` en el esquema; `auto` no se guarda. Los
  campos con `group: 'design'` van en el plegable «Diseño de esta lámina» del editor. En el `.tsx`, `banner-style.ts`.
- **`cn` es twMerge**: un `leading-*` antes de un tamaño de letra se pierde. Para titulos con tamaño elegible usa
  `titleClass(size, defecto, leading)`, que pone el interlineado al final.
- **Tailwind lee solo las carpetas de codigo**: `@import 'tailwindcss' source(none)` + `@source` en `app.css`. Sin eso
  escaneaba `context/*.md` y un `rounded-[${x}px]` de un documento tumbaba el CSS de desarrollo con un 500. Si una
  clase vive en otra carpeta, suma su `@source`; Vite no ve una carpeta nueva hasta reiniciar.
- **Lo publicado de la tienda se cachea** 10 minutos por sede y pagina (`StorefrontPublisher::cacheKey`, tambien el
  cascaron). Lo que cambie la tienda por fuera del editor (una migracion de datos, un comando) llama `forget`.
- **Colores y plantilla**: una plantilla nunca cambia el acento ni la oferta de la sede (no estan en
  `DESIGN_KEYS['theme']`). Trae `palette_suggestion` (`accent`, `deal_color`; las tintas las calcula el servidor) y la sede
  la aplica a mano: el boton de Aspecto o la casilla del selector de plantillas, que guardan por el mismo `ThemeController`
  con su regla de contraste. Por eso una sugerencia tiene que pasar 4,5:1 (oferta contra blanco): una que no pase se
  rechazaria al aplicarla (`StorefrontTemplateCatalogSeederTest` lo mide).
- **Catalogo de plantillas**: `database/seeders/data/storefront_templates.json` + `StorefrontTemplateCatalogSeeder`
  (idempotente por `slug`, nunca toca la que ya existe ni el «por defecto»), llamado desde `StorefrontTemplateSeeder`
  despues de «Supermercado». Todo pasa por las reglas del editor (listas del tema, request de cada ajuste, `normalize` en
  contexto plantilla) y revienta con su motivo si el JSON no cumple; `validate()` debe dar cero advertencias: si una
  advierte, arregla el JSON. Las imagenes son recetas (`["motivo", ...colores]`) que pinta `ScenePainter` y guarda
  `BannerPainter::scene()` como medios de plataforma; la miniatura de la galeria es `BannerPainter::thumbnail()`
  (1200 x 800, con el nombre). Un motivo nuevo va en `ScenePainter::MOTIFS`, en `draw()` y en `describe()`. Las
  plantillas creadas a mano reciben un `slug` con cola al azar para no quitarle el suyo a una del catalogo.
- **Suscriptores de novedades**: el formulario del pie solo existe si la sede lo enciende (`footer.newsletter`, apagado
  por defecto); con el apagado, `POST /tienda/newsletter` responde 404. Pide autorizacion de datos, no repite correo por
  empresa y tiene tope de intentos. La lista vive en Tienda en linea › Suscriptores (`storefront.newsletter.view`).
- **Franja legal del pie**: sale de la empresa (razon social, NIT, direccion, contacto) y no se puede quitar.
- **La ficha de la tarjeta la arma `StorefrontProducts`** (`present`, `presentServices`): precio de
  `PromotionService`/`ServicePricing`, imagen resuelta, precio por unidad y aviso de existencias segun
  `card.stock_display`. La cifra exacta de existencias no viaja al navegador salvo que la sede lo pida.
- **Tiempo real (las pestañas se ponen al dia solas)**: `ChangeCollector` anota cada guardado o borrado de los modelos
  de `Topics::MODELS` (escucha `eloquent.saved/deleted`) y al final de la peticion, o de cada trabajo de la cola, sale
  **un** `ResourcesChanged` por empresa en `company.{id}.changes`, con tema y sede, nunca datos. `LiveRefresh` (en
  `AppLayout` y `PosLayout`) recarga la pantalla si el tema esta en `realtime.topics`, que sale de `Topics::ROUTES`
  por el nombre de la ruta; la pestaña que hizo el cambio no se recarga (`X-Socket-ID` + `toOthers`). En una peticion
  GET no se anota nada: recargar es un GET, y si guardara de paso dos pestañas se recargarian entre si para siempre.
  Un `update` masivo o un `DB::table` no dispara eventos de modelo: llama `ChangeCollector::record()`. Modelo nuevo
  que deba avisar → una linea en `MODELS`; pantalla nueva → una en `ROUTES`. Reverb arranca con `composer dev`
  (`reverb:start`); si esta caido, `GracefulBroadcaster` deja de intentar 30 s. Las pruebas lo apagan
  (`BROADCAST_CHANGES=false`). Echo autoriza cada canal con la cookie `XSRF-TOKEN` del momento (`channelAuthorization`
  en `app.tsx`), no con la `<meta>`: al iniciar sesion el token cambia sin recargar la pagina.
- **Publicado no es visible**: la tienda solo muestra lo publicado con disponible (existencia − apartado) en la bodega
  `onlineSourceFor`, o con `allow_negative_stock`. La regla vive en `StorefrontProducts::query()` / `sellableVariants()`
  (`AVAILABLE_SQL`) y el panel la lee de ahi (`StorefrontAssortmentService`). No vuelvas a leer `catalog.show_out_of_stock`:
  quedo sin efecto. Una familia sin presentaciones disponibles no tiene tarjeta; un servicio que no alcanza para una unidad
  tampoco se muestra (`StorefrontServiceCatalog::onlyAvailable`). `product_stocks.out_of_stock_since` la mantiene solo
  `StockService`. El carrito no borra la linea agotada: la marca (`unavailable`) y el pago no sigue hasta quitarla.
- **«Tienda en linea» en el panel** (`StorefrontAssortmentService`): una consulta derivada (`catalog`) con una fila por
  articulo y **una** bodega unida; filtros, vistas rapidas, indicadores y sugerencias se escriben sobre sus columnas, asi
  dicen lo mismo. Los umbrales son `storefront_catalog.*` de Ajustes. El servicio memoriza bodegas y ajustes por sede: los
  controladores lo toman nuevo en cada accion (`freshAssortment()`), porque el controlador puede vivir mas que una
  peticion. Descartar una sugerencia es por sede (`storefront_suggestion_dismissals`) y publicar el articulo borra sus
  descartes. Los saltos a traslados, compras y promociones son enlaces con precarga (`products[]`, `to_warehouse`,
  `warehouse`, `create=1`) que exigen su propio permiso alla.
- **Listados de la tienda** (categoria, ofertas, busqueda): `StorefrontListing` filtra y ordena por el precio
  **vigente** en PHP sobre los candidatos del alcance, hasta `MAX_CANDIDATES`. Si agregas un filtro, va ahi.
- **Busqueda**: se compara `products.search_name` (`SearchText::key` del nombre y la marca), que el modelo
  mantiene al guardar. Lo que entre por consulta directa queda en nulo y se busca por el nombre tal cual.
- **La vista previa usa las paginas reales** (`Home`, `Catalog`, `Product`, `Cart`) envueltas en
  `PreviewRootContext`: no dupliques su marcado en `Preview.tsx`. La firma del enlace cubre la sede;
  `page`, `category`, `product` y `device` se cambian sin volver a firmar (`PreviewController::FREE_PARAMETERS`).
- **Ajustes de la tienda por su pestaña**: lo operativo va por `storefront.editor.settings`, la estructura por
  `storefront.editor.structure` (permiso de contenido) y la tarjeta por `storefront.editor.look` (aspecto).
  Una clave nueva va en `StorefrontSetting::KEYS`, en `SettingRequests::MAP` con su request y en la ruta
  que le toque.
- **Imagenes de un bloque**: toda clave que termine en `media_id` o `media_mobile_id` es una imagen o un video
  (`BlockData::isMediaKey`), tambien las versiones (`dark_media_id`) y las de `appearance.dark`. La revision de
  publicar, el renderizador y la copia al independizarse las encuentran por ahi, en cualquier nivel.
- **Opciones numericas de un select** (columnas, layout «2», autoplay) llegan al `.tsx` como numero: comparalas
  con `String(...)`, no con `text(...)`.
- **Icono de una categoria**: se guarda sin el sufijo `Icon` y de la lista de `config/storefront_icons.php`.

- **Articulo con subarticulos**: el principal vive en `product_families` y no se vende, no tiene existencias ni
  se compra. Cada subarticulo es una fila normal de `products` (`product_family_id`, `variant_value_1_id/2_id`,
  `variant_key`, `variant_label`). Caja, compras, inventario, lotes, servicios, pedidos y devoluciones trabajan
  por subarticulo sin saber de familias. Nunca metas el principal en `products`.
- **Lo comun lo escribe solo `ProductFamilyService`**: categoria, marca, proveedor principal, IVA y el nombre
  compuesto se copian a cada subarticulo porque medio sistema los lee de `products`. Si editas un subarticulo
  por otro camino, esas columnas no se tocan. Crear, convertir, agrupar, separar, deshacer, importar
  (`placeImported`) y publicar la tarjeta (`setFlag`) pasan por ahi.
- **Nulos y defectos en la matriz**: una casilla vacia llega como `null`; en una columna sin nulos
  (`ProductFamilyService::NOT_NULL`, como `purchase_pack_size`) no se escribe y queda el valor que habia o el
  defecto de la base. Y `ProductFamily` repite en `$attributes` los defectos de la base: una familia creada sin
  decir `is_active` (sembrada o importada) llegaba en nulo a `settle()` y quedaba apagada.
- **Un subarticulo con movimientos no se borra**: se desactiva. Convertir o agrupar conserva el `id`, el kardex y
  las ventas.
- **Unico de combinaciones por `variant_key`**, no por las dos columnas de valor: MariaDB deja repetir `NULL`.
- **Caracteristicas parametrizables, primero la canasta**: el catalogo es por empresa (`ProductAttributeCatalog`,
  con sus defectos en `DEFAULTS`: volumen, peso, unidades por paquete, sabor, tamaño, color, talla). Una medida
  (`measure`) guarda cantidad y unidad en cada valor y llena `content_amount/unit` del subarticulo; por eso el
  precio por litro sale solo. Un valor nuevo se crea al vuelo; nada de listas fijas en el `.tsx`.
- **Lleva N paga M** se cuenta por `product_family_id ?? product_id`: las tallas de un mismo articulo se juntan;
  articulos distintos no. `PromotionTarget::TYPE_FAMILY` alcanza a todos los subarticulos.
- **Tienda**: una ficha por familia. `StorefrontListing::collapseFamilies` junta sus filas antes de ordenar y
  paginar (el precio que ordena es el menor vigente) y `StorefrontProducts::present` la emite una vez en
  estantes. La ficha de familia lleva el id de la familia y la llave `f:{id}`: casa fichas por `key`, nunca por
  `id`, o la tarjeta desaparece de la pagina. «Desde» con el menor precio vigente de `PromotionService`,
  agotada solo si todos lo estan. El carrito, el pedido y la venta van siempre por subarticulo.
  `/tienda/producto/{id}` de un subarticulo redirige (301) a `/tienda/articulo/{slug}?variante={id}`.
- **Publicar la tarjeta no es publicar las presentaciones**: `product_families.is_online_visible` y `is_featured`
  son de la familia y no se copian. Ocultar una talla se hace en el subarticulo.
- **Compras con matriz**: la matriz es solo pantalla; al servidor llegan renglones por subarticulo, como siempre.
- **Codigo de una familia**: entra en `ScanCodeGuard`; escanearlo en caja ofrece elegir el subarticulo, no cobra nada.
- **Servicio con el articulo completo**: `service_components.product_family_id` con `product_id` como presentacion
  por defecto. En caja y en la tienda se cambia por otra del mismo articulo sin firma (`ServiceSaleBuilder` lo
  ve como la misma linea); en `itemized` se cobra la elegida al precio vigente, nunca un precio fijo. En la
  tienda la eleccion viaja en `cart_items.choices` (`{familia: subarticulo}`) y la limpia
  `ServicePricing::cleanChoices`. Un componente sin `product_family_id` es un subarticulo puntual.
- **Importar subarticulos**: `articulo_principal`, `atributo_1/valor_1`, `atributo_2/valor_2` son opcionales en
  `ProductImport`. Lo comun sale de la primera fila; una fila que lo contradiga falla con su motivo. Un archivo
  viejo sin esas columnas no saca a nadie de su articulo. Exportar escribe las mismas columnas, y los
  encabezados se normalizan (`MasterImport::aliases`) para que exportar → importar de lo mismo.

### Medios de pago y pasarelas de las empresas

- **El comportamiento sale del `kind`, nunca del nombre**: `cash` da cambio y se redondea, `credit` pide cupo,
  `store_credit` gasta el saldo, `gateway_*` crea una transaccion. «Nequi» es un `transfer` con otro nombre.
  `PaymentMethodCatalog::DEFAULTS` son los de fabrica (por `system_key`); toda empresa nace con ellos (gancho
  `created` de `Company`). Quien cobra por dentro sin elegir medio (devoluciones, semillas) manda solo el `kind`
  y `defaultFor()` lo resuelve al de fabrica de ese canal.
- **`PaymentMethodAvailability` es la unica fuente** de «que medios valen aqui y ahora»: props de la caja
  (`forRegister`), validacion del cobro (`resolve`), checkout, pedidos de mostrador, cobro en la puerta
  (`collectable`), revision de publicar y logos del pie (`configuredOnline`). Ningun `.tsx` tiene una lista de
  medios; si agregas una regla a `availability`, va ahi y en `StorePaymentMethodRequest::cleanAvailability`.
  El horario es de Bogota, incluye el inicio y no el fin; el minimo y el maximo se miden contra lo que se cobra
  con ESE medio, no contra la venta.
- **`CompanyPaymentMethod` no es esto**: es la tarjeta con que la empresa paga su membresia. Y
  `PaymentGatewaySetting`/`PaymentGatewayCredential` son la pasarela DE LA PLATAFORMA (membresias). Las cuentas
  de las empresas viven en `payment_gateway_accounts` (secretos `encrypted:array`, ocultos y enmascarados con
  `maskedSecrets()`); que proveedores pueden conectar lo decide `payments.company_providers` de la plataforma.
- **Un conector nuevo** implementa `PaymentGateway` (en `app/Payments/Gateways/`), se suma a
  `GatewayRegistry::DRIVERS` y resume su contrato verificado en `config/<proveedor>.php`. Las llaves que pide la
  pantalla salen de `fields()`; lo que ofrece, de `capabilities()`. Nada se ofrece que el conector no declare.
- **Webhooks de empresa**: `POST /webhooks/pagos/{proveedor}/{token}`, un token por cuenta (rotarlo invalida el
  viejo). Orden fijo en `PaymentWebhookController`: firma con el secreto de ESA cuenta (401), relectura en la
  pasarela (503 si no responde, para que reintente) y `GatewayPaymentService::settle` con la fila bloqueada. Un
  aprobado con otro monto o moneda queda en `error` y cancela lo que esperaba; nunca se aprueba.
- **La URL de vuelta es solo una pista**: la pagina de pago en linea pregunta el estado al servidor, que relee
  en la pasarela. Lo que diga `?status=APPROVED` no aprueba nada.
- **Pedido con pasarela**: nace `pending_payment` con la mercancia apartada y sin transiciones manuales. Aprobado
  → `new`; rechazado, vencido o con monto distinto → `cancelled` con todo liberado (reserva, saldo a favor y
  cupon). `payments:expire` corre cada minuto (`payments.online_hold_minutes`, 30 por defecto). Entregar menos de
  lo pagado deja la diferencia como saldo a favor (`TYPE_ONLINE_OVERPAID`).
- **QR, link y datafono en la caja**: la venta NO existe hasta que aprueba. `PosGatewayPayments::start` valida
  el tiquete entero armando la venta y deshaciendola (`gateway_preview`), guarda el tiquete como suspendido y la
  fila `pending`, y despues llama a la pasarela. Sondeo y webhook terminan en la misma liquidacion: una venta.
  Mientras espera, ese tiquete no aparece en «Tiquetes suspendidos» (`heldSaleIdsAwaiting`); si falla, vuelve.
- **Pagos por conciliar**: aprobado sin venta ni pedido (`PaymentTransaction::toReconcile`). Se resuelve en
  Medios de pago > Transacciones (`PaymentReconciliationService`): registrar la venta en una caja abierta,
  aplicar a un pedido, saldo a favor, reembolso por la pasarela o constancia de devolucion por fuera. Conciliar
  documenta una decision; nunca cobra otra vez.
- **Comision en los informes**: estimada con `fee_percent` + `fee_fixed` del medio y solo sobre lo que entro.
  La real la dice el extracto. La conciliacion de pasarelas (`ReportService::gatewayReconciliation`) no cuenta
  los cobros de prueba del datafono (sin medio).
- **En pruebas, `Http::fake` acumula**: el primer fingido que calza gana. Para cambiar la respuesta de la
  pasarela en medio de una prueba usa `fakeWompi()` de `CreatesPaymentGateways`. Y dos peticiones del mismo test
  reusan la instancia del controlador (con sus servicios ya cargados): si cambias un ajuste de plataforma entre
  las dos, parte la prueba.

## Antes de dar algo por terminado

```bash
npx tsc --noEmit
npm run build
php artisan test
```

`RouteSmokeTest` abre todas las rutas GET del panel: si agregaste una pantalla y se te olvidó
una prop, ahí sale. Si la ruta nueva necesita estado que el barrido no monta, exímela por
nombre de parámetro en `panelRoutes()` en vez de aflojar la aserción.

Y, si tocaste migraciones o seeders, `php artisan migrate:fresh --seed`.
