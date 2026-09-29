# Notas para trabajar en este proyecto

Sistema de supermercado "La Canasta": Laravel 12 + Inertia 2 + React 19 + TypeScript estricto.
El `README.md` explica cómo levantarlo y cómo está armado. Aquí van las reglas que no se ven
leyendo el código.

## Convenciones

| Tema | Regla |
|---|---|
| Código | Inglés: tablas, columnas, modelos, rutas, componentes, variables |
| Interfaz | Español de Colombia |
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
  Nunca `count() + 1`.
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
- **Plata que entro por un medio**: nunca sumes `sale_payments.amount` a secas. En efectivo el
  monto trae el cambio adentro; usa `SalePayment::NET_AMOUNT_SQL` en SQL o `netAmount()` en PHP,
  o el arqueo y los informes quedan inflados.
- **Cobro repartido**: una venta admite un pago por medio (indice unico `sale_id, method`). La
  suma de los pagos debe ser **mayor o igual** al total y solo el efectivo puede exceder; el
  sobrante es el cambio y vive en la linea de efectivo.
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
- **Domicilio, franjas y medios de pago son por sede**: viven en `storefront_settings` y se
  editan en la pestana Funciones. `SettingsService` ya no los maneja. Lo que el checkout lee
  sale de `StorefrontConfig`, y siempre de la **version publicada**, no del borrador.
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
- **Wompi, contrato verificado (sep. 2026)**: tokens de aceptacion en `GET /merchants/info` con el encabezado
  `x-merchant-public-key` (la forma `/merchants/{llave}` se apaga el 31/10/2026). El `checksum` de los
  eventos llega en mayusculas: se compara sin importar mayusculas. La marca de la tarjeta se deduce del
  BIN que devuelve Wompi, no de lo que diga el navegador. Todo esta resumido en `config/wompi.php`.
- **Sede vendible**: una sola regla, `StorefrontContext::sellable()` (sede activa; empresa activa, sin
  suspender, con `online_store`; tienda encendida y publicada). La usan la landing, el buscador, el enlace
  `/tienda/{empresa}/{tienda}` y `setStore()`. Una empresa aprovisionada nace con la tienda apagada; la
  primera publicacion la enciende. Un nombre de ruta fijo de `/tienda` va en `Company::RESERVED_SLUGS`.
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
- **Franja legal del pie**: sale de la empresa (razon social, NIT, direccion, contacto) y no se puede quitar.
- **La ficha de la tarjeta la arma `StorefrontProducts`** (`present`, `presentServices`): precio de
  `PromotionService`/`ServicePricing`, imagen resuelta, precio por unidad y aviso de existencias segun
  `card.stock_display`. La cifra exacta de existencias no viaja al navegador salvo que la sede lo pida.
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
- **Imagenes de un bloque**: toda clave `media_id` o `media_mobile_id` es una imagen (`BlockData::isMediaKey`).
  La revision de publicar, el renderizador y la copia al independizarse las encuentran por ahi.
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
