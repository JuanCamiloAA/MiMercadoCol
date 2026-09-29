# PROMPT — Módulo de Importación de Artículos desde API externa (miMercadoCol)

> **Cómo usar este archivo:** pégalo completo como primer mensaje en una sesión de Claude Opus con el repositorio de miMercadoCol abierto. Está escrito para ser ejecutado de una sola pasada, por fases, sin dejar código muerto ni a medias.

---

## 0. Contrato de trabajo (leer antes de escribir una sola línea)

Eres un desarrollador senior de Laravel + Inertia + React. Vas a implementar un módulo completo, funcional y probado. Reglas que no se negocian:

1. **Nada sin funcionamiento.** No entregues código que no hayas ejecutado. Cada fase termina con `php artisan test` en verde y `npm run build` sin errores de TypeScript.
2. **Explora antes de crear.** Antes de escribir un archivo, lee los que ya existen en el proyecto (modelos, middleware de empresa/sede, sistema de roles y permisos, layouts de Inertia, componentes UI existentes). **Reutiliza las convenciones del proyecto**; no introduzcas un estilo nuevo.
3. **Si algo del proyecto contradice este prompt, gana el proyecto.** Avísame en una nota y sigue la convención existente.
4. **No inventes campos ni endpoints.** Si un dato no existe en la fuente, la columna queda nullable y vacía; no la rellenes con valores de adorno.
5. **Migraciones reversibles.** Todo `up()` tiene su `down()` correcto y probado con `migrate:fresh` y con `migrate:rollback`.
6. **Todo lo que escribe en base de datos va dentro de una transacción** y es idempotente: correr la misma importación dos veces no puede duplicar ni corromper nada.
7. **Cero llamadas de red en los tests.** Usa `Http::fake()` con fixtures JSON guardadas en el repo.
8. **Prohibido** `dd()`, `dump()`, `console.log`, `any` de TypeScript, y `@ts-ignore` en el código final.
9. Trabaja **fase por fase**. Al terminar cada fase: muéstrame el diff resumido, el resultado de los tests y espera antes de seguir a la siguiente.
10. Todo el texto visible al usuario va **en español** (labels, mensajes de error, notificaciones). Los identificadores de código en inglés o español, según la convención que ya use el repo.

---

## 1. Contexto del proyecto

- **Producto:** miMercadoCol — back office + POS + tienda online para supermercados y tiendas de barrio en Colombia.
- **Stack:** Laravel 12, Inertia, React, TypeScript.
- **Arquitectura de datos:** multiempresa **y** multisede. Toda tabla de negocio se aísla por `empresa_id`; el stock y los movimientos se aíslan además por `sede_id`.
- **Roles y permisos:** los que ya existen en el proyecto (misma mecánica del ERP). No crees un sistema nuevo.

### Qué vamos a construir

Un módulo de back office con el que **el administrador de una empresa importa artículos desde una API pública de códigos de barras (Open Food Facts)** hacia el catálogo de su empresa, eligiendo qué traer. Sirve para arrancar una tienda nueva sin digitar miles de productos a mano.

### Qué NO es este módulo

- No es el alta de productos en caja (eso ya existe o se hace aparte).
- No sincroniza precios: **Open Food Facts no tiene precios**. Precio, costo, impuesto y stock son siempre del comerciante.
- No consulta la API durante la venta. En caja la API es, como mucho, un fallback con timeout corto.

---

## 2. La fuente de datos

**Open Food Facts** — base colaborativa mundial bajo licencia ODbL.

| Concepto | Valor |
|---|---|
| Base URL producción | `https://world.openfoodfacts.org` |
| Base URL staging | `https://world.openfoodfacts.net` |
| Autenticación | Ninguna (API pública, sin API key) |
| User-Agent | **Obligatorio y propio**: `miMercadoCol/1.0 (contacto@dominio.com)` |
| Licencia | ODbL — uso comercial permitido; atribución obligatoria si se redistribuye la base |

### Endpoints que usa este módulo

**a) Producto por código de barras**

```
GET /api/v2/product/{ean}.json?fields=code,product_name,generic_name,brands,quantity,categories_tags,image_front_small_url,countries_tags
```

Respuesta cuando existe:

```json
{
  "code": "7702001139415",
  "status": 1,
  "status_verbose": "product found",
  "product": {
    "code": "7702001139415",
    "product_name": "Yogurt Original",
    "generic_name": "Yogurt con dulce",
    "brands": "Alpina",
    "quantity": "1 L",
    "categories_tags": ["en:dairies", "en:yogurts"],
    "countries_tags": ["en:colombia"],
    "image_front_small_url": "https://images.openfoodfacts.org/..."
  }
}
```

**b) Búsqueda / catálogo paginado**

```
GET /api/v2/search?countries_tags_en=Colombia&brands_tags={marca}&categories_tags_en={categoria}&fields={campos}&page={n}&page_size=100&sort_by=unique_scans_n
```

Respuesta: `{ "count": 14382, "page": 1, "page_size": 100, "products": [...] }`

### ⚠️ Trampas de esta API que DEBES manejar (esto es la mitad del trabajo)

1. **Un producto inexistente devuelve HTTP 200 con `"status": 0`.** Nunca te fíes solo del código HTTP. Comprueba siempre `status === 1` antes de leer `product`.
2. **Casi todos los campos pueden faltar o venir vacíos.** `product_name` vacío es frecuentísimo. Un producto sin nombre **se descarta**, no se importa con nombre en blanco.
3. **`brands` es un string con marcas separadas por coma**, no un array. Toma la primera y normalízala.
4. **`categories_tags` viene como `["en:dairies", ...]`** con prefijo de idioma. Hay que limpiarlo.
5. **`page_size` máximo es 100.** Pedir más no falla: devuelve 100 y te deja creyendo que trajiste más.
6. **La API es gratuita y de uso justo.** Máximo ~1 petición por segundo desde el worker. Nunca en paralelo masivo.
7. **Los códigos llegan con ceros a la izquierda significativos y a veces con comillas.** Trátalos SIEMPRE como string. Si los pasas a `int` en cualquier punto (PHP o JS) corrompes el EAN: es el bug clásico de este módulo.
8. **La API puede tardar o caer.** Timeout de 15 s en el worker, reintentos con backoff, y la importación nunca puede dejar la UI colgada.
9. **Un mismo EAN puede venir repetido** entre páginas si el catálogo cambia durante el recorrido. Deduplica en memoria y en base con `upsert`.

---

## 3. Modelo de datos

Crea las migraciones con los nombres y convenciones que ya use el repo (revisa una migración existente antes). Estructura requerida:

### 3.1 `productos` (tabla del catálogo de la empresa)

Si ya existe, **no la recrees**: añade por migración incremental solo lo que falte.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | id | |
| `empresa_id` | foreignId | index, cascade |
| `ean` | string(14) nullable | El código de barras. **String siempre.** |
| `nombre` | string(200) | obligatorio |
| `descripcion` | string(255) nullable | de `generic_name` |
| `marca` | string(100) nullable | |
| `presentacion` | string(50) nullable | de `quantity` |
| `categoria_id` | foreignId nullable | categoría propia de la empresa |
| `imagen_url` | text nullable | |
| `origen` | enum(`manual`,`api`,`csv`) | default `manual` |
| `origen_ref` | string(50) nullable | id/código en la fuente externa |
| `activo` | boolean | default true |
| timestamps, softDeletes | | |

**Índice único obligatorio:** `unique(['empresa_id', 'ean'])` — el mismo EAN puede existir en empresas distintas, nunca dos veces en la misma. Ten en cuenta que `ean` es nullable: en MySQL los NULL no colisionan en un índice único, que es justo lo que queremos para los productos sin código de barras.

> Precio, costo, impuesto y stock **no van aquí** si el proyecto ya los maneja en tablas por sede (`productos_sede`, `inventarios` o como se llamen). Revisa y respeta esa separación.

### 3.2 `importaciones_catalogo` (una fila por corrida)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | id | |
| `empresa_id` | foreignId | |
| `usuario_id` | foreignId | quién la lanzó |
| `fuente` | enum(`openfoodfacts_api`,`csv`) | |
| `criterios` | json | filtros usados (país, marca, categoría, búsqueda) |
| `estado` | enum(`pendiente`,`procesando`,`completada`,`fallida`,`cancelada`) | default `pendiente` |
| `total_detectados` | unsignedInteger default 0 | |
| `total_importados` | unsignedInteger default 0 | |
| `total_actualizados` | unsignedInteger default 0 | |
| `total_omitidos` | unsignedInteger default 0 | |
| `total_fallidos` | unsignedInteger default 0 | |
| `mensaje_error` | text nullable | |
| `iniciada_en` / `finalizada_en` | timestamp nullable | |
| timestamps | | |

### 3.3 `importaciones_catalogo_items` (trazabilidad por artículo)

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `importacion_id` (cascade), `ean` string(14) nullable | | |
| `payload` | json | lo que devolvió la API, tal cual |
| `resultado` | enum(`importado`,`actualizado`,`omitido`,`fallido`) | |
| `motivo` | string(255) nullable | por qué se omitió o falló |
| `producto_id` | foreignId nullable | |
| timestamps | | |

Índice en `['importacion_id', 'resultado']`.

---

## 4. Capa de servicio (backend)

### 4.1 Cliente de la API — `app/Services/Catalogo/OpenFoodFactsClient.php`

Responsabilidad única: hablar con la API. No toca base de datos.

```php
interface CatalogoExternoClient
{
    /** @return array|null null si no existe (status 0) */
    public function buscarPorEan(string $ean): ?array;

    /** @return array{count:int, page:int, page_size:int, products:array} */
    public function buscar(CriteriosBusqueda $criterios, int $pagina = 1, int $porPagina = 100): array;
}
```

Requisitos:

- Usa `Http::withHeaders(['User-Agent' => config('catalogo.user_agent')])->timeout(15)->retry(3, 1000, throw: false)`.
- Base URL y User-Agent en `config/catalogo.php`, alimentados por `.env` (`CATALOGO_OFF_BASE_URL`, `CATALOGO_OFF_USER_AGENT`). **Nunca hardcodeados.**
- `buscarPorEan()` cachea el resultado 24 h con `Cache::remember("off:ean:{$ean}", ...)`, incluidos los negativos (cachea 1 h el "no existe" para no martillar la API con el mismo código inválido).
- Si la respuesta no es 2xx o el JSON no tiene la forma esperada, lanza `CatalogoExternoException` con el contexto. No devuelvas arrays a medias.
- Limita a **1 petición por segundo** (`usleep` entre páginas o un rate limiter de Laravel).

### 4.2 Normalizador — `app/Services/Catalogo/NormalizadorProducto.php`

Convierte el payload crudo de la API en un DTO limpio. Implementa exactamente:

- `ean`: string, trim, quita comillas simples iniciales, quita espacios. Si no es numérico de 8/12/13/14 dígitos → inválido.
- **Valida el dígito de verificación** GTIN (algoritmo estándar: desde la derecha, pesos 3 y 1 alternados; el verificador es `(10 - suma % 10) % 10`). Código con dígito inválido → se omite con motivo `ean_invalido`.
- Normaliza GTIN-12 (UPC) a 13 dígitos anteponiendo un `0`, para que el escáner de caja siempre encuentre el mismo formato.
- `nombre`: `product_name` o, si está vacío, `generic_name`. Si ambos están vacíos → omitido con motivo `sin_nombre`. Colapsa espacios, corta a 200.
- `marca`: primera de `brands` separada por coma, trim, corta a 100.
- `presentacion`: `quantity` limpio, corta a 50.
- `categoria_externa`: primer elemento de `categories_tags`, sin el prefijo `xx:`, guiones a espacios.
- `imagen_url`: `image_front_small_url` si es una URL https válida; si no, null.

Escribe este normalizador como **clase pura, sin dependencias de Laravel**, para poder testearlo con tests unitarios rápidos.

### 4.3 Servicio de importación — `app/Services/Catalogo/ImportadorCatalogo.php`

- Recibe `empresa_id`, `usuario_id` y los criterios. Crea la fila en `importaciones_catalogo` en estado `pendiente` y despacha el job.
- Por cada producto normalizado:
  - `upsert` sobre `productos` por `['empresa_id', 'ean']`.
  - **Al actualizar un producto que ya existía, NO pises nunca** `categoria_id`, `activo`, ni ningún campo comercial que el administrador haya podido editar. Solo completa los campos que estén vacíos. Esta regla es crítica: una segunda importación no puede deshacer el trabajo manual del comerciante.
  - Cuenta el resultado y graba el item de trazabilidad.
- Todo el lote va en transacción por bloques de 200 (`chunk`), no una transacción gigante de 15.000 filas.

### 4.4 Job en cola — `app/Jobs/ImportarCatalogoJob.php`

- `ShouldQueue`, cola `importaciones`, `$timeout = 900`, `$tries = 3`, `backoff [10, 60, 180]`.
- Implementa `ShouldBeUnique` con clave `empresa_id`: **una empresa no puede tener dos importaciones corriendo a la vez**.
- Actualiza `estado` a `procesando` al empezar y a `completada`/`fallida` al terminar, siempre — usa `failed()` para marcar la fila como fallida con el mensaje de la excepción.
- Va persistiendo los contadores cada bloque para que la UI muestre avance real.

---

## 5. HTTP: rutas, permisos y controladores

### Permiso nuevo

`catalogo.importar` — créalo con el mismo mecanismo de permisos del proyecto y asígnalo al rol de administrador de empresa en el seeder correspondiente. **Todas** las rutas de abajo lo exigen, además del middleware de empresa/sede que ya usa el proyecto.

### Rutas (`routes/web.php`, dentro del grupo autenticado + empresa)

| Método | URI | Acción |
|---|---|---|
| GET | `/catalogo/importar` | pantalla del asistente |
| POST | `/catalogo/importar/previsualizar` | trae una página de resultados de la API (no guarda nada) |
| POST | `/catalogo/importar` | crea la importación y despacha el job |
| GET | `/catalogo/importar/{importacion}` | estado y resumen (para el polling) |
| GET | `/catalogo/importar/{importacion}/items` | listado paginado de items con su resultado |
| POST | `/catalogo/importar/{importacion}/cancelar` | marca cancelada; el job la respeta y se detiene |

Reglas:

- **Autorización por política**, no por `if` sueltos: `ImportacionCatalogoPolicy`, verificando que `importacion.empresa_id === usuario.empresa_activa_id`. Un administrador de la empresa A no puede ver ni cancelar una importación de la empresa B. Escribe un test que lo demuestre (debe dar 403).
- Validación con **FormRequest** dedicados. Los criterios permitidos son: `pais` (default `Colombia`), `marca`, `categoria`, `busqueda`, `solo_con_imagen` (bool), `limite` (int, 1..5000, default 500).
- La previsualización tiene **rate limit** (`throttle:20,1`) para no convertir tu app en un proxy de la API pública.

---

## 6. Frontend (Inertia + React + TypeScript)

Pantalla `resources/js/Pages/Catalogo/Importar.tsx` — asistente de 3 pasos. Usa los componentes UI que ya tenga el proyecto; no metas una librería nueva.

**Paso 1 — Criterios.** País (Colombia por defecto), marca, categoría, búsqueda libre, checkbox "solo con imagen", límite. Botón **Previsualizar**.

**Paso 2 — Previsualización.** Tabla con: checkbox, EAN, nombre, marca, presentación, categoría externa, miniatura. Encabezado con `Se encontraron N productos`. Selección individual y "seleccionar todos". Una columna **Estado** que marque con badge los que **ya existen** en el catálogo de la empresa (consulta previa por los EAN de la página) para que el administrador sepa qué se va a actualizar y qué es nuevo. Botón **Importar seleccionados**.

**Paso 3 — Progreso y resultado.** Barra de avance + contadores en vivo (importados / actualizados / omitidos / fallidos) por polling cada 2 s a la ruta de estado. **Detén el polling** cuando el estado sea final o el componente se desmonte (`useEffect` con su `clearInterval` en el return — el bug clásico es dejar el intervalo vivo y saturar el servidor). Al terminar, tabla de items con su motivo, filtrable por resultado, y botón para exportar el detalle a CSV.

Requisitos de UI:

- Tipa las props con interfaces en `resources/js/types/`. `ean` es `string` en TypeScript también. **Nunca** `parseInt` sobre un EAN.
- Estados vacíos y de error explícitos: sin resultados, API caída, importación fallida.
- Deshabilita el botón de importar mientras haya una importación en curso para esa empresa, con el mensaje correspondiente.
- Todo en español y con formato de números local.

---

## 7. Importación alternativa por CSV (dump completo)

Además de la API, el administrador puede subir un CSV con las columnas `ean,nombre,marca,presentacion,categoria,imagen` (es la salida del script `off_colombia.py` que filtra el dump de Open Food Facts por Colombia).

- Misma tabla de importaciones, `fuente = csv`.
- Lectura en streaming (`SplFileObject` o `LazyCollection`), **nunca** `file()` completo en memoria: el archivo puede traer decenas de miles de filas.
- Mismo normalizador, mismas reglas de upsert y de no pisar datos editados.
- Límite de subida y validación de MIME/extensión explícitos.

---

## 8. Pruebas obligatorias (Pest o PHPUnit, el que use el repo)

Sin estos tests en verde, la entrega no está terminada.

**Unitarias del normalizador**
1. EAN con dígito de verificación válido pasa; inválido se omite con motivo `ean_invalido`.
2. UPC de 12 dígitos se normaliza a 13 con el cero delante.
3. Producto sin `product_name` ni `generic_name` → `sin_nombre`.
4. `brands: "Alpina, Alpina S.A."` → marca `Alpina`.
5. `categories_tags: ["en:dairies"]` → `dairies`.
6. EAN `"07702001139415"` conserva el cero inicial (test anti-regresión del bug de casting).

**De integración (con `Http::fake` y fixtures)**
7. Respuesta con `status: 0` → no crea producto, item `omitido` con motivo `no_encontrado`.
8. Importación de 3 productos nuevos → 3 filas en `productos` con `origen = api` y los contadores correctos.
9. Correr **la misma importación dos veces** → 0 duplicados, `total_actualizados = 3`.
10. Producto existente con `categoria_id` y `activo=false` asignados a mano → tras reimportar, **esos valores siguen intactos**.
11. Dos empresas importan el mismo EAN → dos filas, una por empresa, sin violar el índice único.
12. La API responde 500 → el job reintenta y, agotados los intentos, la importación queda `fallida` con mensaje, nunca colgada en `procesando`.
13. Timeout de la API → mismo comportamiento controlado.

**HTTP / autorización**
14. Usuario sin el permiso `catalogo.importar` → 403 en todas las rutas.
15. Usuario de la empresa A consultando una importación de la empresa B → 403.
16. Lanzar una segunda importación con una en curso → respuesta con el mensaje de bloqueo, sin despachar job.

**Frontend**
17. `npm run build` sin errores de TypeScript ni warnings de ESLint.

---

## 9. Criterios de aceptación

La fase está terminada cuando, en un entorno limpio:

- `php artisan migrate:fresh --seed` corre sin errores, y `migrate:rollback` deshace las migraciones nuevas sin dejar rastro.
- El administrador entra a **Catálogo → Importar**, filtra por país Colombia y marca "Alpina", previsualiza, selecciona 20 productos e importa.
- La barra de progreso avanza y termina con contadores que cuadran: `importados + actualizados + omitidos + fallidos = total_detectados`.
- Los 20 artículos aparecen en el catálogo de **esa** empresa, con EAN intacto, y no aparecen en otra empresa.
- Repetir la importación no duplica nada ni pisa lo que el administrador editó a mano.
- Apagar la API (simulado) deja la importación en `fallida` con mensaje claro, y la aplicación sigue usable.
- `php artisan test` en verde. `npm run build` en verde.

---

## 10. Entrega

Al terminar todas las fases, entrégame:

1. Lista de archivos creados y modificados.
2. Las líneas nuevas de `.env.example` y el `config/catalogo.php` completo.
3. Comandos exactos para probarlo en local, de cero.
4. Los supuestos que tomaste donde el proyecto no era explícito.
5. Lo que quedó **fuera** de alcance y por qué.

---

## Anexo A — Nota legal a incluir en el código y en la pantalla

Los datos provienen de **Open Food Facts** bajo licencia **ODbL**. El uso comercial está permitido. La atribución es obligatoria si se redistribuye la base de datos derivada; el uso interno en el catálogo del comerciante no genera esa obligación. Incluye en la pantalla de importación una línea discreta: *"Datos de catálogo por Open Food Facts (ODbL)"*, enlazando a `https://openfoodfacts.org`.

## Anexo B — Recordatorio de arquitectura

Este módulo es una herramienta de **arranque y mantenimiento del catálogo en el back office**. En el punto de venta, la fuente de verdad es siempre el catálogo propio de la empresa. Si alguna vez se consulta la API desde caja, debe ser un fallback no bloqueante con timeout de 1–2 segundos que solo pre-llene el formulario de alta manual. A los pocos meses de operación, el catálogo propio describe el surtido real mejor que cualquier fuente pública.
