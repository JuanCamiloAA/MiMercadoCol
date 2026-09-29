# La Canasta — sistema de supermercado

Sistema de punto de venta, inventario, cartera, domicilios y tienda en línea para supermercados de barrio.
Laravel 12 + Inertia 2 + React 19 + TypeScript estricto + Tailwind 4 + MySQL/MariaDB.

---

## Puesta en marcha

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
```

En `.env` apunta a tu base de datos (por defecto `canasta_pos` en MySQL/MariaDB) y luego:

```bash
php artisan migrate:fresh --seed
npm run build            # o `npm run dev` mientras desarrollas
php artisan serve
```

La aplicación queda en `http://127.0.0.1:8000`.

> En Windows, `PHP_CLI_SERVER_WORKERS` debe quedar en `1`: el servidor embebido de PHP no
> soporta *workers* en esa plataforma y se cae con valores mayores.

### Requisitos del PHP

Además de lo que pide Laravel 12, el sistema usa **GD** para recortar las fotos de los
artículos. En XAMPP se habilita quitando el `;` de `extension=gd` en `php.ini` y reiniciando
Apache. Si no está, las imágenes se guardan igual pero en su tamaño original.

Los correos de estado de pedido salen por cola. En desarrollo basta `QUEUE_CONNECTION=sync`;
en producción, `database` o `redis` y un `php artisan queue:work` corriendo.

### Tarea programada

El bloqueo de crédito por mora corre una vez al día. En producción basta con el cron de Laravel:

```
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Para ejecutarlo a mano: `php artisan credit:block-overdue`.

La ronda de membresías corre a las 03:00: cobra las renovaciones automáticas, pasa a gracia lo
vencido, suspende lo que agotó la gracia, manda recordatorios y vence las contrataciones sin pagar.
A mano: `php artisan membership:process` (con `--dry-run` muestra lo que haría sin tocar nada).

A las 02:30 se cierra el saldo a favor que ya venció: `php artisan store-credit:expire`.

### Pasarela de pagos (Wompi)

Las llaves se cargan en **Plataforma > Pasarela de pagos**, por entorno (pruebas y producción), y
se guardan cifradas. Sin las cuatro llaves del entorno no se enciende, y sin pasarela no aparece
ningún botón de pago. La URL de eventos que se pega en el panel de Wompi es
`https://tu-dominio/webhooks/wompi`. El `.env` (`WOMPI_*`) solo siembra la primera vez.

---

## Cuentas de prueba

Todas usan la contraseña `Password123*`.

| Correo | Rol | Qué puede hacer |
|---|---|---|
| `super@lacanasta.co` | Super administrador | Todo, en todas las empresas |
| `admin@lacanasta.co` | Administrador | Todo dentro de La Canasta |
| `supervisor@lacanasta.co` | Supervisor de tienda | Opera la tienda; no administra cuentas ni sedes |
| `cajera@lacanasta.co` | Cajero (PIN `1234`) | Abre caja, vende y cierra turno |
| `cajero2@lacanasta.co` | Cajero (PIN `4321`) | Igual que el anterior |
| `bodega@lacanasta.co` | Bodega e inventario | Recibe compras, ajusta stock, alista pedidos |
| `domicilios@lacanasta.co` | Domiciliario | Mueve pedidos y cobra contraentrega |
| `contable@lacanasta.co` | Auxiliar contable | Informes, cartera y cierres de caja |

**Tienda en línea**: se entra desde la landing (`/`), eligiendo una sede en el buscador, o con el
enlace de cada tienda (`/tienda/la-canasta/centro`). **Entrar** abre el ingreso encima de la página
(hoja que sube desde abajo en el celular, ventana en el computador) y deja al cliente donde estaba.
Con el documento del cliente y la contraseña `Cliente123*`, por ejemplo `43112233` (Luz Marina
Agudelo), o con **Crear cuenta**.

**Segunda empresa de demostración**: «Mercado El Vecino» (Envigado y Bello), administrador
`admin@elvecino.co` con la misma contraseña. Su tienda está publicada: sirve para probar la
landing con dos empresas y el carrito por empresa.

---

## Cómo se entra a cada cosa

| Pantalla | Ruta | Permiso |
|---|---|---|
| Panel de inicio | `/dashboard` | `dashboard.index.view` |
| Punto de venta | `/pos` | `pos.sale.view` |
| Tiquete para imprimir | `/pos/ventas/{venta}/imprimir` | `pos.sale.view` |
| Ventas (historial) | `/ventas` | `sales.index.view` |
| Devoluciones de venta | `/ventas/devoluciones` | `sales.returns.view` |
| Apertura de caja | `/caja/apertura` | `registers.open.view` |
| Cierre de caja | `/caja/cierre` | `registers.close.view` |
| Artículos | `/productos` | `products.index.view` |
| Servicios | `/servicios` | `services.index.view` |
| Inventario y kárdex | `/inventario` | `inventory.index.view` |
| Traslados entre bodegas | `/traslados` | `inventory.transfers.view` |
| Solicitudes de traslado | `/traslados/solicitudes` | `inventory.transfer_requests.view` |
| Bodegas de una sede | `/sedes/{sede}/bodegas` | `stores.warehouses.view` |
| Compras | `/compras` | `purchases.index.view` |
| Devoluciones a proveedor | `/compras/devoluciones` | `purchases.returns.view` |
| Clientes | `/clientes` | `customers.index.view` |
| Cartera | `/cartera` | `credit.index.view` |
| Pedidos (domicilios) | `/pedidos` | `orders.index.view` |
| Empleados | `/empleados` | `employees.index.view` |
| Usuarios y permisos | `/usuarios` | `users.index.view` |
| Roles | `/roles` | `roles.index.view` |
| Informes | `/informes` | `reports.sales.view` |
| Tienda en línea (surtido) | `/tienda-en-linea` | `storefront.catalog.manage` |
| Importar artículos | `/catalogo/importar` | `products.catalog_import.view` |
| Editor de la tienda | `/tienda/editor` | `storefront.editor.view` |
| Plantillas de tienda | `/plataforma/plantillas-tienda` | `platform.store_templates.view` (super usuario) |
| Medios | `/medios` | `storefront.editor.content` |
| Cupones | `/cupones` | `coupons.index.view` |
| Sedes | `/sedes` | `stores.index.view` |
| Ajustes | `/ajustes` | `settings.index.view` (guardar: `settings.index.brand` para marca, apariencia y menú; `settings.index.pos_layout` para caja y tiquete; `settings.index.edit` para reglas de caja y crédito) |
| Tienda del cliente | `/tienda` | pública (pide elegir sede) |
| Importar una maestra | `/<modulo>/importar` | `<modulo>.index.import` |
| Plazos de pago | `/plazos-pago` | `payment_terms.index.view` |
| Membresía de la empresa | `/ajustes?pestana=membresia` | `settings.membership.view` (pagar: `settings.membership.manage_payment`) |
| Landing pública | `/` | pública |
| Contratar | `/contratar` | pública |
| **Plataforma** (solo super usuario) | | |
| Empresas | `/plataforma/empresas` | `companies.index.view` |
| Solicitudes | `/plataforma/solicitudes` | `platform.leads.view` |
| Planes y ciclos | `/plataforma/planes`, `/plataforma/ciclos` | `platform.plans.view`, `platform.billing_cycles.view` |
| Pasarela de pagos | `/plataforma/pasarela` | `platform.gateway.view` |
| Editor de la landing | `/plataforma/landing` | `platform.landing.view` |
| Carga masiva (CSV) | `/plataforma/carga-masiva` | `platform.imports.view` |

---

## Cómo está armado

### Multiempresa y sedes

- Toda tabla de negocio lleva `company_id` y el *global scope* `CompanyScope` la filtra sola.
- `App\Support\TenantContext` resuelve la empresa activa; `App\Support\StoreContext`, la sede.
- El super usuario elige la empresa en el selector de la barra superior. Sin elegir ve
  «Todas las empresas»: el inicio es el resumen de la plataforma y cualquier pantalla de empresa
  pide elegir una (`company.selected`). Con una elegida opera como su administrador, caja incluida:
  abre caja a su nombre con su sesión, sin PIN.
- Toda empresa nace por `App\Services\Platform\CompanyProvisioner` (Empresas, contratación en
  línea y carga masiva): sede con bodega, caja, roles, plazos de pago, tienda apagada, administrador
  y membresía, todo en una transacción.
- La membresía (planes, ciclos, prueba, gracia, suspensión, cobros) vive en
  `App\Services\Membership\*`. Una empresa suspendida solo llega a Ajustes > Membresía para pagar.
- La tienda en línea toma la empresa de la sede elegida (`StorefrontContext`); el cliente y el
  carrito son de cada empresa.
- La sede activa vive en sesión y se cambia desde la barra superior. Si la persona solo tiene
  una sede habilitada, se elige sola y nunca se le pregunta.
- Los dos *helpers* usan el guard `web` de forma explícita: la tienda en línea entra con el
  guard `customer` y no debe filtrar nada por empresa.

### Permisos

- `App\Helpers\PermissionHelper` es el catálogo completo, en formato `modulo.pagina.accion`.
  Si un permiso no está ahí, no existe: no se guarda y ninguna ruta lo exige.
- **El rol es una plantilla, no la fuente de la verdad.** Lo que puede hacer una persona son
  sus permisos directos (`model_has_permissions`).
- Cambiar un rol no altera a nadie: se guarda la diferencia y se ofrece propagarla eligiendo
  a quién, mostrando cuántos permisos gana y pierde cada uno (`RoleTemplateService`).
- En React, `usePermission().can('...')` decide si el botón se pinta; la ruta lo vuelve a
  comprobar en el servidor con el middleware `permission:<nombre>`.

### Reglas de negocio

Viven en servicios, nunca en controladores:

| Servicio | De qué responde |
|---|---|
| `SaleTotalsCalculator` | Totales en pesos enteros con IVA incluido en el precio |
| `StockService` | Único punto que toca `product_stocks`; siempre escribe kárdex |
| `SaleService` | Venta, anulación y devolución, todo en una transacción |
| `CreditService` | Cupo, saldo, abonos y bloqueo por mora |
| `RegisterSessionService` | Turnos de caja, esperado y arqueo |
| `OrderService` | Pedidos: estados válidos, alistamiento y entrega |
| `NumberSequenceService` | Consecutivos con bloqueo de fila; nunca `count() + 1` |
| `CartService` | Carrito de la tienda en línea, guardado en base de datos |
| `ImageService` | Recorta la foto del artículo a 900 px y arma la miniatura de 280 px |
| `SettingsService` | Ajustes por empresa con valores por defecto en un solo lugar, y excepciones por sede (`forStore`) para el tiquete |
| `BrandThemeService` | De los ajustes de marca a tokens: color ajustado por contraste, tipografías, esquinas, escala y el CSS de `#brand-theme` |
| `BrandAssetService` | Isotipo y logos de la empresa en PNG con transparencia, y el logo del tiquete en blanco y negro |
| `ReceiptService` | Lo que imprime un tiquete: ajustes de su sede, logo y código de barras o QR; lo usan el HTML, el PDF y la vista previa |
| `StorefrontManager` | El borrador de cada sede: encontrarlo, retratarlo y reponerlo |
| `StorefrontPublisher` | Revisar antes de publicar, versionar, revertir y comparar |
| `StorefrontRenderer` | Convertir un retrato en lo que pinta la tienda; el mismo para lo publicado y para la vista previa |
| `StorefrontConfig` | Lo que la sede **publico** sobre domicilio, zonas, franjas y medios de pago; es lo que lee el checkout |
| `CouponService` | Unico sitio que decide si un cupon sirve y cuanto rebaja |
| `OpenFoodFactsClient` | Habla con la fuente externa; no toca base de datos |
| `ProductNormalizer` | Limpia y valida lo que llega de la fuente; clase pura, sin Laravel |
| `CatalogImporter` | Mete lo normalizado en el catalogo sin pisar lo editado a mano |
| `ReportService` | Informes de ventas, inventario, cartera y caja |
| `DashboardService` | Cifras del panel de inicio |

**Devoluciones**: se registran como una venta nueva con valores en negativo, fechada el día en
que el cliente devolvió. Así el informe del día original no cambia hacia atrás, la caja del
turno refleja la plata que salió y el inventario recupera la mercancía.

**Anulación** es otra cosa: corrige un error del cajero, devuelve el stock y saca la venta de
las cifras.

### Editor de la tienda

Cada sede tiene **su propia tienda en linea**: su portada, su aspecto y sus reglas de domicilio.
Se arma desde `/tienda/editor` y lo que se edita es siempre la sede activa de la barra superior.
La tienda tiene estilo de supermercado: barra superior con mensajes, encabezado de compra con
buscador y carrito, menu de categorias con el chip de ofertas en rojo, banners, estantes, una
tarjeta de producto unica con el precio protagonista y el precio por unidad, «Agregar» que se
vuelve contador sin recargar la pagina y barra flotante del carrito con el avance al domicilio
gratis.

**Tres pestanas**

| Pestana | Que decide | Permiso |
|---|---|---|
| Aspecto | Marca (nombre, lema, logo, isotipo, favicon), colores (acento y rojo de oferta, con su contraste), tipografia, formas, esquema (claro, oscuro, del sistema, si el cliente lo cambia) y la **tarjeta de producto** | `storefront.editor.appearance` |
| Contenido | Los bloques de **cada pagina** (portada, categoria, producto, carrito, busqueda) y la **estructura de toda la tienda**: barra superior, encabezado, menu de categorias, barra del carrito, navegacion del celular y pie | `storefront.editor.content` |
| Funciones | Domicilio, cobertura, franjas, medios de pago, textos, SEO, catalogo y las paginas de categoria, producto y busqueda | `storefront.editor.settings` |

Publicar, ver el historial y copiar entre sedes tienen su propio permiso (`.publish`,
`.history`, `.copy_store`). Quien no lo tenga puede dejar el borrador listo, pero no publicarlo.

**Bloques disponibles**

| Bloque | Paginas | Que pone |
|---|---|---|
| Aviso | todas | Una franja con un aviso |
| Banner principal | portada, categoria, busqueda | Una o varias laminas (imagen de computador y de celular, titulo, boton, vigencia por lamina), solo o con uno o dos banners al lado |
| Mosaico de banners | portada, categoria, busqueda | De dos a cuatro banners (imagen o color con texto) |
| Categorias | portada | Circulos con icono, mosaicos con foto o etiquetas; todas, las elegidas o las mas vendidas |
| Estante de productos | todas | Fila deslizable o rejilla: destacados, mas vendidos, ofertas, lo nuevo, «vuelve a comprar», una promocion, una categoria o una lista; con «Ver todo» y fondo |
| Oferta relampago | portada, categoria, busqueda, carrito | Una promocion con su cuenta regresiva; se va sola al vencer |
| Carrusel | portada, categoria, busqueda | Imagenes que se pasan de lado, cada una con su vigencia |
| Franja con boton | todas | Un llamado con su boton: WhatsApp de la sede, una pagina, un enlace |
| Beneficios, Texto, Preguntas frecuentes | portada, producto | Promesas con icono, texto libre, preguntas |
| Datos de la tienda | portada | Direccion, telefono y horario de la sede |

Los de la pagina de categoria se pueden limitar a unas categorias («el banner de Aseo solo en
Aseo»). Los de categoria y busqueda van arriba de los resultados; los de producto, debajo de la
ficha; los del carrito, debajo del resumen.

**Como funciona**

- Cada bloque se puede **mover, ocultar, duplicar, eliminar y programar** y mostrarse solo en
  celular o solo en computador. Su formulario lo arma el editor solo a partir de `fields()` en
  la clase del bloque (`app/Storefront/Blocks/`). Agregar uno nuevo es escribir esa clase,
  sumarla a `BlockCatalogue::TYPES` y pintar su componente en `pages/Storefront/blocks/`.
- **La estructura global** (barra superior con variables como `{domicilio}` o `{gratis_desde}`,
  encabezado, menu, tarjeta, carrito, pie, paginas) vive en `storefront_settings`, una clave por
  pieza, cada una con su `FormRequest` y listas cerradas. Se versiona y se publica con los
  bloques. La **franja legal del pie** (razon social, NIT, direccion, telefono, correo, terminos,
  tratamiento de datos y PQRS) sale de la empresa y no se puede quitar.
- **No hay boton de guardar.** Cada cambio baja al borrador solo, con Ctrl+Z para deshacer.
- **Borrador y publicado son dos cosas.** Publicar deja una **version** con el retrato completo
  del sitio —tema, bloques de todas las paginas, estructura, ajustes, zonas y franjas— que se
  puede recuperar despues. Revertir no borra historia: crea una version nueva.
- Antes de publicar el editor **revisa**: sin medios de pago, sin zonas con el domicilio
  encendido, con un color ilegible (acento o rojo de oferta), con un banner sin texto
  alternativo o sin enlace de tratamiento de datos, no deja. Avisa de estantes vacios,
  imagenes de mas de 1 MB, categorias del menu apagadas y mensajes con variables sin valor.
- **Vista previa en vivo** de cualquier pagina (portada, una categoria, un articulo, el carrito
  o la busqueda), en celular y computador, con las **mismas paginas** que ve el cliente. Tocar un
  bloque o una zona (barra superior, encabezado, menu, pie) abre sus propiedades. Va dentro de un
  `iframe` con enlace firmado de media hora: las reglas de tamano de Tailwind miden el ancho de
  la **ventana**, no el del panel.
- **Copiar entre sedes** lleva aspecto, bloques (y la plantilla que se sigue) y funciones con la
  estructura al **borrador** de la otra sede, nunca a lo publicado.
- Los iconos salen de `config/storefront_icons.php` y del mapa de `components/storefront/icons.tsx`;
  las imagenes se suben con texto alternativo obligatorio y se guardan en variantes WebP, con una
  copia angosta para el `srcset`.

**Plantilla base (super usuario)**

`/plataforma/plantillas-tienda` (`platform.store_templates.view/edit/publish`) arma plantillas de
**solo diseño** con el mismo editor: bloques, forma del tema y estructura, sin marca, sin
domicilio y sin ids de ninguna empresa (los estantes van por regla y los enlaces a paginas o
busquedas). La vista previa se ve con el catalogo de una sede elegida.

- Las tiendas nuevas nacen con la plantilla **por defecto** y la **siguen**: cada publicacion de
  la plantilla les llega con su propia marca, color, domicilio y pagos.
- Al **personalizar**, la tienda se independiza: el diseño queda como suyo y sus imagenes se
  copian a la biblioteca de la empresa. Lo que ve el cliente no cambia.
- **Volver a la plantilla** (o aplicar otra) guarda antes el diseño propio como una version del
  historial, para poder deshacer.
- El reparto entre diseño y sede vive en `StorefrontTemplate::DESIGN_KEYS`.

**Domicilio, franjas y cupones**

El costo del domicilio, el pedido minimo, el «gratis desde», las zonas de cobertura, las franjas
de entrega con su cupo y los medios de pago habilitados son **de la sede**, no de la empresa, y
salen de la pestana Funciones. El checkout los respeta: cobra la tarifa de la zona elegida, no
deja pedir por debajo del minimo, no ofrece una franja llena y solo acepta los medios de pago
encendidos. `free_from = 0` significa que no hay domicilio gratis: nunca se ve «gratis desde $ 0».

Los **cupones** se administran en `/cupones` (`coupons.index.*`): porcentaje, valor fijo o
domicilio gratis, con vigencia, pedido minimo, tope del descuento, limite de usos —total y por
cliente—, «solo el primer pedido» y alcance a todo el pedido, a unas categorias o a unos
articulos. El cliente lo escribe al pagar y el descuento se calcula en el servidor.

**Catalogo de la tienda**

- `/tienda/categoria/{slug}`, `/tienda/ofertas` y `/tienda/buscar` comparten pagina: filtros
  (subcategoria, marca, precio, solo ofertas, solo disponibles), orden y «Cargar mas» o paginas,
  segun la sede. El precio que se filtra y se ordena es el **vigente**, con promociones.
- El buscador sugiere mientras se escribe, sin tildes y perdonando la ortografia: «arros» trae
  «Arroz» (`App\Support\SearchText`, columna `products.search_name`).
- El carrito se toca con `PUT /tienda/carrito/cantidad` (cantidad absoluta) y viaja resumido en la
  prop compartida `cart`: el navegador es optimista, pero el total siempre sale del servidor.

### Importar articulos desde un catalogo externo

Para arrancar una tienda nueva sin digitar miles de articulos a mano. Se entra por
**Catalogo -> Importar articulos** (`/catalogo/importar`, permiso `products.catalog_import.view`).

La fuente es **Open Food Facts**, una base colaborativa mundial bajo licencia ODbL. Es publica,
no pide llave y **no tiene precios**: precio, costo, impuesto y existencias son siempre del
comerciante.

**El asistente**

1. **Filtros.** Pais, marca, categoria de la fuente, busqueda libre, solo con imagen y cuantos
   traer. Ademas se elige la **categoria propia** donde caen los articulos nuevos: en este
   proyecto `category_id` no admite nulos y dejar que el sistema escoja «la primera» termina
   con mil articulos donde nadie los puso.
2. **Que hay.** La tabla marca cada articulo como *Nuevo*, *Ya existe* o *Eliminado*, para
   saber de antemano que se va a crear y que se va a completar. Se escoge uno por uno o todos.
3. **Resultado.** Barra de avance con contadores en vivo y, al terminar, el detalle articulo
   por articulo con su motivo, filtrable y exportable a CSV.

**Reglas que no se negocian**

- Lo que entra, entra **apagado y sin precio**. Se activa cuando se tarifa.
- Si el articulo ya existe, **solo se completan los campos vacios**. La categoria, el estado,
  los precios y el nombre que haya puesto el comerciante no se tocan nunca. Correr la misma
  importacion dos veces no duplica nada ni deshace trabajo manual.
- Nunca se crean categorias solas. Si la de la fuente coincide **por nombre** con una de la
  empresa se usa esa; si no, cae en la elegida en el paso 1.
- Un articulo eliminado no resucita solo: se omite con el motivo.
- Una empresa a la vez. El job es `ShouldBeUnique` por empresa y la pantalla lo avisa antes.

**El codigo de barras es texto, siempre**

Los EAN llevan ceros a la izquierda que son significativos. Se tratan como string en PHP, en
TypeScript, en el JSON y en la base; un `(int)` o un `parseInt` en cualquier punto del camino
corrompe el codigo y el escaner de caja deja de encontrar el articulo. El normalizador ademas
valida el digito de verificacion GTIN y pasa los UPC de 12 digitos a 13, para que el mismo
articulo no entre dos veces segun quien lo escanee.

**Tambien por archivo**

La misma pantalla acepta un CSV con columnas `ean,nombre,marca,presentacion,categoria,imagen`
—la salida del script que filtra el volcado de Open Food Facts por Colombia—. Se lee en
streaming y pasa por el mismo normalizador y las mismas reglas; el archivo se borra al terminar.

Es distinto de `/productos/importar`, que carga la maestra completa por **SKU** con precios.
Este va por **EAN**, en cola, y deja bitacora de cada articulo.

**Con cola o sin cola**

La importacion se despacha a la cola, que es lo correcto cuando hay un worker:

```bash
php artisan queue:work --queue=imports
```

Cuando no lo hay —lo normal en una instalacion de mostrador sobre XAMPP— la corrida se queda
en «En espera». Para eso esta el boton **Importar ahora**, en la tarjeta de avance y en cada
fila pendiente del historial: corre la importacion dentro de la peticion y devuelve el
resultado. El navegador espera, pero termina.

Es lento a proposito: la fuente solo admite una consulta por segundo, asi que traer veinte
articulos escogidos a mano tarda unos veinte segundos. Para miles, la cola sigue siendo el
camino. Ahi no hay reintentos automaticos: lo que falle se cierra en el acto, con su motivo.

### Bodegas y traslados

Cada **sede** puede tener varias **bodegas**. Lo tipico son dos: el piso de venta, que es la
mercancia exhibida, y el deposito de atras. El inventario se lleva por bodega, no por sede:
`product_stocks` es unico por `(product_id, warehouse_id)`.

De cada sede se decide, en `/sedes/{sede}/bodegas`, tres cosas:

| Bandera | Que significa |
|---|---|
| Vende en caja | De ahi descuenta el punto de venta |
| Vende en la tienda en linea | Eso es lo que la tienda muestra disponible, reserva y despacha |
| Recibe las compras | Ahi entra una orden de compra si no se dice otra cosa |

**De cada una hay exactamente una encendida por sede.** Marcarsela a una se la quita a la que
la tenia, dentro de una transaccion. Una bodega con bandera no se puede desactivar hasta
pasarsela a otra. Las demas bodegas guardan mercancia pero no venden: lo que esta en ellas no
aparece en caja ni en la tienda hasta que se traslade.

En el punto de venta, un articulo agotado en el piso pero con existencia atras lo dice
(«+40 en bodega»), para que el cajero mande a alguien por el en vez de decir que no hay.

Toda sede nace con su «Bodega principal», con las tres banderas encendidas. Una empresa que
nunca cree una segunda no deberia notar ningun cambio.

**Lo que se mide contra la sede, no contra la bodega**: las alertas de minimo, la sugerencia
de compra y los informes de inventario suman todas las bodegas. Un articulo con cero en el
piso y doscientos atras no es una compra: es una reposicion.

#### Traslados

`/traslados` mueve mercancia de una bodega a otra, con documento numerado (`TR-0001`):

| Paso | Quien | Que pasa en el inventario |
|---|---|---|
| Por despachar | Quien lo arma, o la bodega que surte al atender una solicitud | Nada. Aqui no se valida la existencia |
| Despachar | La bodega de origen | Sale del origen. La mercancia queda **en camino** y no cuenta en ninguna bodega |
| Recibir | La bodega de destino | Entra en el destino. Lo que no llego se da de baja como averia, con el numero del traslado |
| Anular | — | Solo por despachar. Uno despachado se recibe, aunque sea en cero |

Despachar y recibir son siempre dos actos, aun entre bodegas de la misma sede: quien recibe
cuenta lo que llego. Cada acto lo hace alguien con el permiso y con acceso a la sede de esa
bodega.

El **traslado directo** junta los dos actos en uno, como antes. Solo existe entre bodegas de la
misma sede y pide su propio permiso, `inventory.transfers.direct`, para poder quitarselo a quien
deba seguir el flujo completo.

#### Solicitudes de traslado

`/traslados/solicitudes` es el paso previo: la bodega que necesita mercancia la pide
(`SOL-0001`) y la que la tiene decide cuanto manda. Pedir **no mueve inventario**.

| Accion | Quien | Que pasa |
|---|---|---|
| Pedir | Quien llega a la sede de la bodega que pide | Queda **pendiente** |
| Generar traslado | Quien llega a la sede de la bodega que surte | Nace un traslado por despachar (o ya despachado) con lo que se decide mandar, nunca mas de lo pendiente. Si falta algo queda **atendida en parte** |
| Rechazar | La bodega que surte | Solo si no se ha mandado nada. Pide motivo |
| Cerrar | La bodega que surte | Solo atendida en parte: deja de estar pendiente lo que falta |
| Anular | La bodega que pide | Solo mientras nadie la haya atendido |

Lo pendiente se recalcula desde los traslados: si se anula un traslado generado, esas unidades
vuelven a quedar pendientes sin que nadie tenga que restarlas.

Los lotes viajan con la mercancia: lo que sale con vencimiento llega con el mismo vencimiento,
asi que mover una caja de leche de bodega no la saca de la alerta de «por vencer». Y un
traslado **no** recalcula el costo promedio: el costo es del articulo, no del estante.

El boton **Sugerir reposicion** arma traslados por despachar con lo que esta bajo minimo en la
bodega que vende pero tiene existencia en otra de la misma sede. No salen solos: la maquina
propone que subir, quien decide cuanto cabe en el estante es el que lo esta mirando.

Desde Existencias, el boton del mapa abre **donde esta este articulo**: cada bodega de la
empresa con lo que hay, lo apartado y un atajo para pedir el traslado. Ver sedes a las que no
estas asignado pide `inventory.index.view_other_stores`; mover mercancia sigue pidiendo acceso
a la sede.

### Compras

La pantalla de **nueva orden** (`/compras/crear`) funciona como el punto de venta: buscar o
escanear, tocar, ajustar la cantidad, confirmar.

**El proveedor no filtra el catalogo: lo resalta.** Antes solo se podia pedir lo que el
proveedor elegido surtia, asi que comprarle dos cosas a dos proveedores obligaba a armar dos
ordenes desde cero, recorriendo la tienda dos veces. Ahora se ve todo el catalogo de la sede,
marcado con quien surte que, ordenado para que lo urgente quede arriba: primero lo del
proveedor y bajo minimo, despues lo del proveedor, despues lo bajo minimo de otros, y al final
el resto. Nunca se esconde nada.

Si la orden queda mezclada, se avisa y se ofrece **Separar**: se parte en una orden por
proveedor, enlazadas por `parent_order_id`, sin perder ni duplicar nada. Se puede separar al
crear o despues, desde la ficha de la orden.

**La cantidad sugerida** sale de `PurchaseSuggestionService`:

```
falta     = max(0, minimo - existencia)
objetivo  = ceil(minimo x reorder_multiplier) - existencia
sugerido  = ceil(max(falta, objetivo) / purchase_pack_size) x purchase_pack_size
```

La existencia es la **disponible** de la sede activa —lo reservado para pedidos en linea ya
esta comprometido— y el empaque redondea hacia arriba: si el proveedor vende por cajas de doce,
pedir siete no es una opcion.

**El servidor no le cree al navegador**: de cada renglon solo acepta cantidad y costo. El
proveedor, la existencia, el minimo y el sugerido los recalcula al guardar, y deja la foto del
momento (`stock_at_order`, `min_at_order`, `suggested_quantity`) para que tres meses despues se
pueda explicar por que se pidieron cincuenta.

**Recepcion por partes.** Un pedido de 120 del que llegan 100 no queda recibido: la orden pasa a
**Recibida en parte** y sigue abierta para cuando entre el resto, que puede venir en otro lote y
otro dia. En el formulario se escribe **lo que llega ahora**, no el total del pedido, y viene
puesto en lo que falta. Cada remesa deja su propio lote y su propia entrada en el kardex, asi que
la ficha muestra cuando llego cada parte.

Si el resto ya no va a llegar, **Cerrar con lo que llego** da la orden por terminada sin mover
inventario. Anular una orden a la que ya le entro mercancia no se permite: dejaria el inventario
contando algo que la orden dice que nunca paso.

**Atajos**: `F1` al buscador, `Enter` agrega el primer resultado, `F4` agrega todos los que
estan bajo minimo, `Esc` suelta el foco. Un codigo de ocho a catorce digitos en el buscador se
trata como escaneo.

### Devoluciones a proveedor

`/compras/devoluciones` saca del inventario lo que vuelve al proveedor (vencido, averiado, mal
despachado, sobrante) y lleva la cuenta de lo que el proveedor responde. Se arma desde la ficha de
una orden recibida (**Devolver**) o suelta, escaneando artículos. El único camino es
`SupplierReturnService`.

| Estado | Qué significa |
|---|---|
| Borrador | Se edita libremente. No mueve inventario |
| Pendiente de respuesta | Salió de la bodega (kárdex `Devolución a proveedor`, consecutivo `DP-0001`) y el proveedor aún no contesta por todo |
| Cerrada | El proveedor respondió por todo lo devuelto |
| Anulada | Solo un borrador. Despachada no se anula: si la mercancía vuelve, es un rechazo |

- **Nunca deja negativo**: despachar mide existencia menos apartado para pedidos, con la fila
  bloqueada, aunque la empresa permita vender en negativo.
- **No recalcula el costo promedio**: sale al costo vigente; lo que se le reclama al proveedor es
  el costo de la orden (o el último que cobró ese proveedor).
- **Respuestas**: nota crédito, reembolso, reposición (genera una orden de compra
  `source = replacement` que entra por la recepción normal) o rechazo (la mercancía vuelve a la
  bodega). La suma no puede pasar de lo que queda pendiente.
- La orden de compra muestra la columna **Devuelto** y el valor neto; el listado de proveedores,
  cuánto tiene pendiente cada uno. El documento para el transportador sale en PDF.
- Permisos: `purchases.returns.view`, `create`, `dispatch`, `respond` y `cancel`.

### Devoluciones de venta

`/ventas/devoluciones` es el historial y la bandeja de las devoluciones de los clientes. Cada una es un
documento `DV-0001` colgado de su venta, con el motivo y el destino de cada artículo, y la respuesta
al cliente. El único camino es `CustomerReturnService`.

| Dónde | Cómo funciona |
|---|---|
| Caja (`Devolución`, F6) | Se busca la venta (del turno o por número, de cualquier día) y se resuelve en el acto: reembolso (efectivo, tarjeta o transferencia), saldo a favor, cambio por otro artículo o «no procede». Se imprime el comprobante |
| Tienda en línea | El cliente la pide desde «Mis pedidos» sobre un pedido entregado y dentro del plazo, con motivo y hasta 3 fotos. El equipo la aprueba o rechaza, la recibe y la resuelve (reembolso o saldo a favor). El cliente recibe un correo en cada paso y puede retirarla mientras no se haya recibido |
| Panel | «Registrar devolución» desde la venta, el pedido o el listado: queda aprobada o, si la mercancía ya está en la sede, recibida |

- **A dónde va cada artículo**: vuelve al inventario (a la bodega de donde salió) o va a avería (entra y se
  da de baja, así la pérdida sale en los informes). Lo dañado o vencido se propone como avería. El
  inventario se mueve al resolver, no antes.
- **Cambio**: la caja abre el tiquete con el valor devuelto como primer pago; si el cliente se lleva más,
  paga la diferencia; si se lleva menos, lo que sobra se le devuelve en efectivo o queda como saldo.
- **Saldo a favor**: vive en la ficha del cliente con sus movimientos y su vencimiento (Ajustes > Reglas
  de caja). Se usa como medio de pago en caja y en el checkout de la tienda. Nunca se le abona al
  consumidor final genérico.
- **Plazo**: `returns.max_days` (30 por defecto) desde la venta o la entrega. Fuera de plazo, en caja lo
  autoriza un supervisor con su PIN.
- Si la venta quedó a crédito, lo devuelto se descuenta primero de la deuda.
- Una venta con devoluciones ya no se anula. `store-credit:expire` corre a diario y cierra el saldo vencido.
- Permisos: `sales.returns.view`, `create`, `approve`, `receive`, `resolve` y `cancel`; en caja,
  `pos.sale.refund`.

### Artículos con subartículos

Un artículo puede ser **individual** (como siempre) o **con subartículos**: el aceite en 500 ml,
1 L y 2 L; la leche en bolsa de 1 L, de 1,1 L o la paca de seis; la gaseosa por sabor y tamaño;
la camiseta por talla y color. El principal (`product_families`) agrupa y no se vende; cada
subartículo es un artículo de verdad, con su SKU, código de barras, precio, costo, mínimo y
existencias por bodega.

- **Características parametrizables** en `/atributos` (permisos `products.attributes.view/manage`):
  cada empresa trae de fábrica volumen, peso, unidades por paquete, sabor, tamaño, color y talla, y
  crea, renombra, ordena o desactiva las suyas. Una **medida** (volumen, peso, unidades) guarda
  cantidad y unidad: «900 ml» llena el contenido del subartículo y la tienda calcula el precio por
  litro sin digitarlo dos veces. Hasta dos características por artículo; los valores se crean al vuelo.
- **Formulario con matriz**: se eligen los valores y se genera una fila por combinación. **Llenar**
  pone o suma un valor (precio, precio en línea, costo, mínimo) a todas las filas o a las de un
  valor, y el lector de códigos baja solo a la siguiente fila. Un artículo individual se **convierte** en el primero de sus subartículos, y varios
  que ya existían se **agrupan**; en los dos casos cada uno conserva su id, kardex y ventas.
- **Caja**: escanear el código de un subartículo lo cobra; el del principal ofrece escoger.
- **Compras**: la orden muestra la matriz del artículo y guarda renglones por subartículo.
- **Tienda**: una sola tarjeta por artículo («Desde $ 7.300», «3 presentaciones», «Elegir»), su
  página `/tienda/articulo/{slug}` con disponibilidad cruzada entre características y fotos por
  color, y filtro por característica en categoría y búsqueda. Se publica desde *Tienda en línea >
  Con subartículos*; qué presentaciones se ofrecen se decide en cada subartículo.
- **Servicios**: un componente puede ser el artículo completo (con una presentación por defecto que
  el cajero o el cliente cambia por otra) o un subartículo puntual.
- **Importar y exportar** usan el mismo archivo de artículos con cinco columnas opcionales:
  `articulo_principal`, `atributo_1`, `valor_1`, `atributo_2`, `valor_2`.

### Promociones

Cuatro tipos, una sola pantalla (`/promociones`, permiso `promotions.index.view`):

| Tipo | Que hace | Se arma con |
|---|---|---|
| Precio especial | Antes $5.100 → ahora $4.200 | Articulos, con su precio |
| Descuento por porcentaje | 20% de rebaja | Articulos **o** categorias enteras |
| Lleva N paga M | 3x2, 2x1 | Articulos o articulos con subarticulos; **cuenta por articulo**, no en grupo |
| Combo | Varios articulos por un precio fijo | Articulos, con la cantidad de cada uno |

- **El lleva N paga M se cuenta por articulo.** Tres aceites dan un aceite gratis; dos aceites
  y un agua no dan nada, aunque la promocion cubra los dos articulos y sumen tres unidades. Si
  el mismo articulo viene en dos lineas con precios distintos, se regala la unidad mas barata.
  Lo que se cuenta en grupo es el **combo**, que para eso existe. Los subarticulos de un mismo
  articulo si se juntan: una talla 8 y dos talla 10 del mismo jean son un 3x2.
- Cada promocion decide **en que canal aplica**: tienda en linea, mostrador, o los dos.
- Tiene vigencia (`desde`/`hasta`, ambas opcionales), etiqueta (`-20%`, `3x2`, la que se quiera)
  y prioridad: cuando dos promociones tocan el mismo articulo, manda la de prioridad mas alta y,
  a igual prioridad, la que le convenga al cliente.
- **El precio lo decide el servidor.** `PromotionService` es el unico sitio que dice cuanto vale
  un articulo hoy, y lo consultan la vitrina, el carrito, el punto de venta y el registro de la
  venta. Por eso el cajero ve el mismo precio que va a cobrar: si el POS armara el tiquete con
  el precio de lista, el cobro no cuadraria con el total.
- El porcentaje se calcula sobre el precio del canal: en la tienda, sobre `online_price`.
- En la vitrina el articulo sale con su etiqueta, el precio anterior tachado y una franja
  **En promocion** en la portada.

**En la caja.** El articulo con promocion se marca en la cuadricula con su etiqueta y un borde
distinto, y al agregarlo el POS lo anuncia. En la linea del tiquete queda la promocion a la
vista con un boton **No aplicar**: el cajero decide, y lo que decida es lo que se cobra.

Las que dependen de la cantidad —3x2, combos— no se notan en el precio unitario, asi que el
tiquete le pregunta al servidor (`POST /pos/cotizar`) cuanto rebajan cada vez que cambia. Esa
aritmetica vive en un solo lado: dos copias de una regla de plata terminan discrepando.

El descuento de una promocion **no cuenta contra el tope del cajero**: un 3x2 rebaja un tercio
del tiquete y no por eso tiene que firmar un supervisor. `pos.allow_discount_percent` solo mide
lo que el cajero rebajo a mano.

### Ventas y Pedidos no son lo mismo

Se confunden con facilidad, así que conviene decirlo claro:

| | **Ventas** (`/ventas`) | **Pedidos** (`/pedidos`) |
|---|---|---|
| Qué es | Plata ya cobrada | Un encargo que hay que alistar y entregar |
| De dónde sale | Caja del mostrador y tienda en línea | Tienda en línea, WhatsApp y mostrador |
| Estados | Pagada, a crédito, devuelta, anulada | Recibido, alistando, listo, en camino, entregado |
| Tabla | `sales` | `orders` |

Un pedido **se convierte** en venta cuando se entrega: ahí `OrderService::deliver` genera la
venta del canal `online`. Por eso una venta del mostrador nunca aparece en Pedidos: nadie la
tiene que alistar ni llevar a ninguna parte.

`/ventas` sirve para buscar un tiquete por número o cliente, filtrar por rango de fechas, caja,
cajero, origen y estado, abrir el detalle con artículos, cobros y devoluciones, y reimprimir el
tiquete sin tener que abrir un turno de caja. Anular y devolver siguen en el punto de venta, que
es donde está el cajón del dinero.

### Cobro con varios medios de pago

Una venta admite **de uno a cuatro pagos**, uno por medio (efectivo, tarjeta, transferencia y
crédito). La modal de cobro va en tres pasos: elegir el medio, asignarle el monto y revisar los
pagos aplicados.

- Tocar un medio le asigna **lo que falte por cobrar**: un toque basta para el caso normal.
- **Solo el efectivo genera cambio.** Tarjeta, transferencia y crédito nunca pasan del faltante;
  el crédito, además, nunca pasa del cupo disponible.
- **Confirmar** solo se habilita cuando la suma cubre el total, y el aviso de la modal dice
  siempre qué falta.
- Atajos: `E` efectivo, `T` tarjeta, `R` transferencia, `C` crédito, dígitos para el monto,
  `Backspace` para borrar, `Enter` para confirmar y `Esc` para volver al tiquete **sin perder**
  los pagos ya asignados. `F12` abre el cobro y `F1` devuelve el foco al lector.
- Si la venta lleva crédito queda en estado `credit`: `due_total` es solo la parte fiada y
  `paid_total` lo que sí se cobró. A cartera entra únicamente la línea de crédito.
- Todo se vuelve a validar en el servidor contra el total recalculado desde los ítems; los
  montos que manda el navegador no se creen.

**Contraentrega.** El cobro del domiciliario usa la misma modal y las mismas reglas, con dos
diferencias: no admite crédito (una deuda no se paga con otra deuda) y **reemplaza** los pagos
que se habían supuesto al entregar. Si el pedido se tomó como «contraentrega en efectivo» pero
el cliente terminó pagando la mitad por transferencia, lo que queda guardado es lo que pasó.

**El domicilio va dentro de la venta.** Al entregar, la venta incluye una línea *Domicilio* por
el valor del envío, de modo que `sales.total` es igual al total del pedido. Antes esa plata se
cobraba y no aparecía en ningún informe ni en el arqueo. Los pedidos entregados **antes** de
este cambio conservan su venta sin esa línea; el cobro de esos se cuadra contra el total de su
venta, no del pedido.

**Cómo se guarda.** Una fila de `sale_payments` por medio, con `sequence` para el orden y
`reference` para el voucher. En la línea de efectivo, `amount` es **lo que el cliente entregó**
y `change_amount` el vuelto, de modo que:

```
sum(sale_payments.amount) - change_amount(efectivo) == sales.total
```

Esa resta es la que usan el arqueo de caja, el informe Z y los informes por medio de pago
(`SalePayment::NET_AMOUNT_SQL`). Sumar `amount` a secas infla el efectivo.

### En la caja

**Redondeo a la moneda menor.** Colombia dejó de circular monedas pequeñas, así que el total a
pagar se redondea al múltiplo más cercano (`pos.cash_rounding`, 100 por defecto; `1` lo apaga).
Solo aplica a ventas del POS pagadas únicamente en efectivo: un pedido en línea o un pago con
tarjeta se cobra exacto. La diferencia queda en `sales.rounding_total`, de modo que el arqueo
cuadra y el informe distingue lo vendido de lo redondeado.

**Autorización del supervisor.** El descuento por encima de `pos.allow_discount_percent` y la
venta a crédito piden un PIN de cuatro dígitos. Lo valida el servidor contra los usuarios
activos que tengan ese permiso, con límite de cinco intentos; la aprobación dura tres minutos,
se anota en `access_logs` y queda en `sales.authorized_by_user_id`. El cajero nunca puede
saltarse el tope desde el navegador: el límite se vuelve a revisar al guardar la venta.

**Tiquete.** Al confirmar, el POS muestra el cambio en grande y ofrece **Imprimir tiquete**,
que abre `/pos/ventas/{venta}/imprimir`: una vista del ancho del rollo (58 u 80 mm, según
Ajustes > Tiquete de la sede) que se manda sola a la impresora si así está configurado. No
necesita controladores de marca ni software adicional, solo la impresora térmica instalada
en Windows.

**Sesión viva.** La pantalla del POS avisa al servidor cada cuatro minutos (`/sesion-activa`),
así que un turno largo sin vender no vence la sesión. Y si aun así vence, el 419 ya no muestra
la página en blanco de Laravel: vuelve a la pantalla con un aviso y el carrito intacto.

### Servicios

`/servicios` es el maestro de lo que se cobra sin ser un artículo: armar una ancheta, porcionar y
empacar al vacío, una recarga, un arreglo de frutas. Un servicio **no tiene existencias**; lo que
consume vive en `service_components` y sale del inventario al venderse, como líneas hijas de la
venta, por `StockService` y desde la bodega de la caja.

| Forma de cobro | Qué cobra |
|---|---|
| Precio cerrado (`bundled`) | El valor del servicio. Los artículos van incluidos a $0 |
| Servicio + artículos (`itemized`) | El valor del servicio más cada artículo al precio que se le fije en el servicio o, vacío, a su precio vigente |
| Precio variable (`open_price`) | Lo digita el cajero. No se publica en la tienda en línea |

- **En la caja** se escanea o se toca como un artículo (su código no puede chocar con un SKU ni con
  un código de barras). La tarjeta del servicio deja quitar, reducir o aumentar lo que incluye, o
  agregar otro artículo; cada cambio exige `pos.sale.service_remove/reduce/increase/add` o el PIN
  del supervisor, y el servidor lo vuelve a revisar contra el maestro. De fábrica el cajero puede
  quitar, reducir y aumentar, pero no agregar.
- **La venta guarda la foto**: `service_snapshot` con el maestro, lo quitado y quién autorizó.
  Cambiar el servicio mañana no cambia una venta pasada. El tiquete muestra el servicio y debajo lo
  que incluyó.
- **Devolución por artículo**: cada componente dice si vuelve al inventario (por defecto, lo que
  diga el maestro). La plata se devuelve por línea cobrada.
- **Tienda en línea**: se publica desde *Tienda en línea > Servicios*. Se ve solo si está activo,
  publicado y con existencia de sus artículos en la bodega de la tienda; el cliente lo compra tal
  cual el maestro. El pedido aparta los artículos y quien alista registra lo que de verdad usó.
- **Informes**: el servicio cuenta en su categoría; *Más vendidos* no suma los componentes de $0, y
  *Consumo en servicios* dice cuánto inventario se fue por ese lado.
- Se importa y exporta por CSV (`/servicios/importar`) con la columna `articulos` en formato
  `SKU:cantidad:devuelve:precio;…` (`devuelve` es `si`/`no` y `precio` el del artículo dentro de un
  servicio detallado; los dos son opcionales).
- Permisos: `services.index.view/create/edit/delete/export/import`,
  `services.show.view/view_cost/edit_price` y los cuatro de la caja.

### Importar maestras

Las maestras se cargan desde un archivo CSV con el mismo botón **Importar** en su
listado: artículos, servicios, categorías, proveedores, clientes, empleados, sedes y cajas.

- Cada módulo tiene **su propio permiso**, `<modulo>.index.import`, asignable desde Roles.
  De fábrica lo traen solo el administrador y el super administrador.
- El botón solo se pinta si la persona tiene el permiso, y la ruta lo vuelve a exigir.
- La pantalla dice qué columnas espera, cuáles son obligatorias y qué significa cada una, y
  ofrece **descargar la plantilla** ya con los encabezados correctos.
- El archivo puede venir separado por `;` o por `,`, con o sin el BOM que agrega Excel.
- Si el registro ya existe **se actualiza** en vez de duplicarse. La clave es el SKU en
  artículos, el documento en clientes y empleados, el código en servicios, sedes y cajas, el NIT (o el
  nombre) en proveedores y el nombre en categorías.
- Una fila mala no tumba la carga: se omite y la pantalla dice **en qué línea y por qué**.
- Toda importación queda en `access_logs` con quién la hizo, el archivo y el resultado.

Agregar una maestra nueva es escribir una clase en `app/Imports/` y sumarla a
`ImportRegistry`: el lector de archivos, la pantalla y el permiso ya son compartidos.

### Diferencias de caja

Cuando un turno cierra descuadrado, la pantalla de apertura se lo recuerda al siguiente cajero.
Eso avisa, pero no resuelve: la decision sobre esa plata se toma en **Diferencias de caja**
(`/caja/diferencias`, permisos `registers.differences.view` y `.resolve`).

- Lista los turnos cerrados con diferencia, filtrables por estado, rango de fechas, caja y
  cajero, con el faltante y el sobrante acumulados del rango.
- Al abrir uno se ve la base, lo esperado, lo contado, la nota del cajero, **los movimientos de
  efectivo del turno** —sangrias e ingresos, que es donde suele estar la explicacion— y un
  enlace al informe Z.
- Se cierra con una de cuatro decisiones: **justificada**, **la asume la empresa**, **se le
  descuenta al cajero** o **el cajero repuso la plata**, siempre con una nota de por lo menos
  cinco letras. Queda guardado quien decidio y cuando, y ademas en `access_logs`.
- Si la decision se tomo por error, **volver a dejarla pendiente** es un clic.
- Un turno que cuadra nace resuelto: no ensucia la bandeja.
- El aviso de la apertura cambio: ahora dice si la diferencia **ya se resolvio** (y con que
  decision) o si sigue sin revisar, y lleva al modulo a quien tenga el permiso.

**La resolucion no mueve plata.** Es una decision documentada sobre algo que ya paso: si el
cajero repone el faltante, esa plata entra como un ingreso de efectivo en el turno donde se
recibe, no aqui.

### Lotes y vencimientos

Los artículos marcados con `track_lots` (lácteos, carnes, panadería) guardan cada entrada en
`product_lots` con su fecha de vencimiento. Las ventas consumen **FIFO por vencimiento**: sale
primero lo que se vence antes. La pestaña *Por vencer* de Inventario lista lo que se vence en
los próximos 15 días para sacarlo con descuento antes de perderlo.

### Avisos al cliente

Los cambios de estado de un pedido (*en alistamiento*, *en camino*, *entregado*, *cancelado*)
le llegan por correo al cliente, en cola. *Listo* es un estado interno de la bodega y no genera
aviso. Si el cliente no dejó correo, no pasa nada; y si el envío falla, el pedido igual cambia
de estado y el error queda en el log.

### Frontend

```
resources/js/
  app.tsx                 createInertiaApp + Toaster
  types/index.d.ts        PageProps compartidos
  lib/                    format.ts (moneda y fechas es-CO), cn.ts, permissions.ts, navigation.ts
  hooks/                  use-theme, use-surface, use-flash, use-filters
  components/brand/       BrandStyle (mantiene #brand-theme al día), BrandMark, BrandLogo
  components/ui/          Button, Field, Card, Table, Modal, Misc
  components/             PermissionMatrix
  layouts/                AuthLayout, AppLayout, PosLayout (oscuro), StoreLayout (cascarón de la tienda)
  components/storefront/  Piezas de la tienda: encabezado, menú, tarjeta, carrito, pie, carrusel…
  pages/                  una carpeta por módulo
```

- Los dos temas se aplican con `data-theme="light|dark"` en `<html>` y variables CSS.
  El back office y la tienda nacen en claro; el punto de venta y las pantallas de caja,
  en oscuro.
- **La tienda sirve en celular y en computador.** En el telefono la navegacion va abajo (de 3
  a 5 botones que elige la sede), el buscador ocupa su propia fila y los estantes se deslizan
  con el dedo; en computador el encabezado lleva «Recibir en», el buscador grande, la cuenta y
  el carrito, el menu de categorias abre un menu grande con subcategorias y el contenido usa
  hasta 1.280 px. El carrito y la ficha del articulo se reparten en dos columnas.
- **Interruptor de tema** (claro / oscuro / como el sistema) en las tres superficies: barra
  superior del panel, columna del punto de venta y, en la tienda, el pie (solo si la sede lo
  permite; el esquema de la tienda lo decide su editor). La eleccion se
  guarda en `localStorage` y manda sobre el tema propio de la pantalla; `como el sistema`
  sigue a `prefers-color-scheme` y reacciona si cambia mientras la pagina esta abierta.
  Un guion en `app.blade.php` lo aplica antes de pintar, para que no parpadee al recargar.
- Todas las cifras usan una tipografía de ancho fijo (IBM Plex Mono de fábrica) con
  `tabular-nums` para que las columnas no bailen.
- **Marca, panel y caja configurables** desde Ajustes, sin tocar código: nombre, isotipo,
  logos y color (ajustado solo si no se lee); tipografías de una lista cerrada, esquinas,
  densidad, escala y ancho; tema inicial e interruptor por superficie; menú lateral claro,
  oscuro o del color de la marca, completo o solo con íconos; orden, nombres y lo oculto del
  menú; página de inicio por rol; y en la caja, lado del tiquete, vista del catálogo, tamaño
  del total, orden del riel, atajos F1–F12 y sonidos. `BrandThemeService` lo vuelve tokens y
  el blade lo inyecta en `#brand-theme`; **con los valores de fábrica sale vacío** y todo se ve
  como siempre. Tamaños en `rem` (escalan) y esquinas con `rounded-chip/control/card/sheet`:
  `node scripts/codemod-units.mjs --check` avisa si alguien vuelve a escribir `px`.
- Los filtros y la paginación consultan siempre al servidor (`useFilters`).

---

## Pruebas

```bash
php artisan test
```

Corren contra la base `canasta_pos_test` (se crea sola con `RefreshDatabase`). Cubren:

- Ingreso con correo y con documento, cuenta desactivada y bloqueo por intentos.
- El middleware de permisos: 403 y registro en `access_logs`.
- Propagación de roles: no cambia a nadie hasta aplicarla y solo aplica la diferencia.
- Cuentas de acceso de empleados y cambio obligatorio de la contraseña temporal.
- Venta en efectivo, totales con IVA, consecutivos sin repetir y anulación.
- Venta a crédito: cupo, bloqueo, permiso del cajero y abonos.
- Devoluciones: parciales, tope de lo vendido, efecto en crédito y en la caja.
- Turnos de caja: uno solo por caja, arqueo y diferencia.
- Pedidos: saltos de estado válidos, reserva de stock y venta al entregar.
- Recepción de compras, ajustes y traslados.
- Tienda en línea: catálogo publicado, checkout a crédito y llegada al panel.
- Coherencia entre el panel y el informe del mismo día.
- Redondeo del efectivo: solo en el POS, solo pago en efectivo, y el arqueo lo tiene en cuenta.
- Cobro repartido: dos lineas en `sale_payments`, la invariante suma-cambio, rechazo cuando no
  alcanza, cuando la tarjeta excede o cuando se repite el medio, y el cambio solo en efectivo.
- Credito parcial: `status`, `paid_total` y `due_total` partidos, tope de cupo, permiso del
  cajero y la segunda caja que ya no cabe en el cupo.
- El esperado del cierre y el informe por medio de pago cuentan el efectivo neto.
- Promociones: los cuatro tipos con su aritmetica, el reparto del ahorro de un combo sin perder
  un peso, la prioridad entre dos promociones, la vigencia y el aislamiento entre canales.
- Pantalla de promociones: cada tipo exige lo suyo y no se guarda una que no haga nada.
- Promociones en la caja: el catalogo anuncia tambien las que dependen de la cantidad, el cajero
  puede declinarlas linea por linea, la cotizacion reporta lo que rebajan y no confunde ese
  descuento con el que puso el cajero a mano.
- El lleva N paga M cuenta por articulo: tres del mismo dan uno gratis, tres de articulos
  distintos no dan nada, y entre dos lineas del mismo articulo se regala la mas barata.
- Editor de la tienda: armar la portada, que el borrador no llegue al cliente, publicar con su
  version, descartar, recuperar una version anterior, reordenar, ocultar y el bloque unico.
- Tienda renovada: bloques por pagina y limitados a categorias, bloques nuevos (mosaico, oferta
  relampago que se va sola, franja de WhatsApp, estante de lo nuevo y «vuelve a comprar» solo con
  compras de esa empresa), banner sin texto alternativo que no se publica, estructura global con
  listas cerradas que se versiona, restaura y copia, carrito con cantidad absoluta e idempotente,
  domicilio gratis sin «desde $ 0» y minimo que bloquea en el servidor, imagen hasta la del
  catalogo externo, precio por unidad y existencias segun la regla, filtros y orden por precio
  vigente, busqueda que perdona tildes, plantillas (seguir, propagar, independizarse copiando
  imagenes, volver con version, sin ids, una por defecto, aprovisionamiento) y medios de plataforma
  que ninguna empresa ve.
- Diferencias de caja: el turno que cuadra no queda pendiente, el descuadrado si, resolver deja
  nombre y fecha, no se resuelve sin explicacion, reabrir vuelve a dejarla pendiente y el aviso
  de la apertura dice si ya se reviso.
- Contraentrega: la venta trae la linea del domicilio, el cobro repartido reemplaza lo supuesto
  al entregar, no admite credito, el cambio sale del efectivo y cobrar un pedido fiado deja la
  cartera en cero.
- PIN del supervisor: PIN malo, usuario sin permiso, límite de intentos y vencimiento.
- Lotes FIFO por vencimiento y el listado de lo próximo a vencer.
- Avisos de pedido: los estados que notifican, el cliente sin correo y la cancelación.
- Historial de ventas: la venta del mostrador aparece, el rango de fechas filtra, el detalle
  trae articulos y cobro, la devolucion queda colgada de su venta y el tiquete se reimprime.
- Importacion de maestras: las siete pantallas abren, el rol sin permiso recibe 403, el mismo
  documento actualiza en vez de duplicar, la fila incompleta se omite con su motivo, el
  encabezado sin columnas obligatorias se rechaza y se leen archivos con coma y con BOM.
- Devoluciones a proveedor: borrador sin movimiento, despacho que nunca deja negativo (con
  apartados y con `allow_negative_stock`), rechazo que vuelve a meter, respuestas que no pasan de
  lo pendiente, reposición por orden de compra, anulación y permisos por acción.
- Servicios: maestro, componentes y códigos que no chocan con SKU; venta cerrada y por artículos;
  cada ajuste en caja con y sin permiso o PIN; promociones y cupón sobre componentes; cotización
  igual al cobro; venta suspendida; devolución y anulación por artículo; informes; tienda en línea,
  carrito y pedido que aparta, alista y entrega lo usado.
- Devoluciones de venta: en caja con destino por artículo (inventario o avería), saldo a favor que se
  abona, se gasta, se devuelve al anular y vence, rechazo documentado, plazo con PIN, cambio que cobra la
  diferencia o devuelve lo que sobra; en la tienda, la solicitud que aparta cantidades, aprobar, recibir,
  resolver, rechazar, retirar, permisos por paso y el saldo a favor en el checkout y en la entrega.
- **Barrido de pantallas** (`RouteSmokeTest`): abre las rutas GET del panel y exige que
  ninguna reviente. Es la red que atrapa la consulta rota o la prop que se dejó de enviar.

---

## Comandos útiles

```bash
php artisan migrate:fresh --seed     # base limpia con 30 días de operación simulada
php artisan credit:block-overdue     # bloquea a los clientes en mora
php artisan membership:process       # ronda diaria de membresías (--dry-run para simular)
php artisan queue:work               # envía los correos de pedidos
php artisan queue:work --queue=imports  # procesa las importaciones de catálogo
npm run dev                          # front en caliente
npx tsc --noEmit                     # revisión de tipos
php artisan test --filter=RouteSmokeTest   # revisa que ninguna pantalla reviente
```
