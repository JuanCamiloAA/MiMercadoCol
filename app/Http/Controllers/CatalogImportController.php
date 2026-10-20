<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreviewCatalogImportRequest;
use App\Http\Requests\StoreCatalogImportRequest;
use App\Jobs\ImportCatalogJob;
use App\Models\CatalogImport;
use App\Models\CatalogImportItem;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CatalogImporter;
use App\Services\Catalog\CatalogSourceException;
use App\Services\Catalog\OpenFoodFactsClient;
use App\Services\Catalog\ProductNormalizer;
use App\Support\TenantContext;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importar articulos desde un catalogo externo.
 *
 * El asistente son tres pasos: filtrar, mirar lo que hay y traerse lo que sirva. La consulta a
 * la fuente se hace aqui solo para previsualizar; traer de verdad es trabajo del job, porque a
 * una peticion por segundo quinientos articulos son ocho minutos y ninguna pantalla aguanta
 * eso abierta.
 */
class CatalogImportController extends Controller
{
    /** Los campos que la fuente puede completar, como se llaman en pantalla. */
    protected const FIELD_LABELS = [
        'description' => 'descripción',
        'brand' => 'marca',
        'content_amount' => 'presentación',
        'content_unit' => 'presentación',
        'image_url' => 'imagen',
        'source_ref' => 'referencia',
    ];

    public function __construct(
        protected OpenFoodFactsClient $client,
        protected ProductNormalizer $normalizer,
        protected CatalogImporter $importer,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CatalogImport::class);

        $companyId = TenantContext::requireCompanyId();

        return Inertia::render('Catalog/Import', [
            'categories' => Category::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
                ->all(),
            'running' => $this->runningPayload($companyId),
            'history' => CatalogImport::query()
                ->where('company_id', $companyId)
                ->with('user:id,name,last_name')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (CatalogImport $import) => $this->summary($import))
                ->all(),
            // Los segmentos de la fuente, en castellano. Antes era un campo de texto libre en
            // el que habia que adivinar el nombre en ingles de la taxonomia.
            'segments' => collect(config('catalog.segments'))
                ->map(fn (string $label, string $tag) => ['value' => $tag, 'label' => $label])
                ->values()
                ->all(),
            'limits' => [
                'default' => (int) config('catalog.import.default_limit'),
                'max' => (int) config('catalog.import.max_limit'),
                'page_size' => (int) config('catalog.openfoodfacts.page_size'),
                'csv_max_kb' => (int) config('catalog.import.csv_max_kb'),
            ],
            'attribution' => config('catalog.attribution'),
            'can' => [
                'run' => $request->user()->can('products.catalog_import.run'),
                'cancel' => $request->user()->can('products.catalog_import.cancel'),
            ],
        ]);
    }

    /**
     * Una pagina de resultados de la fuente. No guarda nada.
     */
    public function preview(PreviewCatalogImportRequest $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();

        try {
            $result = $this->client->search($request->criteria(), $request->page(), (int) config('catalog.openfoodfacts.page_size'));
        } catch (CatalogSourceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        $normalized = array_map(fn (array $raw) => $this->normalizer->normalize($raw), $result['products']);

        $eans = array_values(array_filter(array_map(fn ($product) => $product->ean, $normalized)));

        // Lo que ya esta en el catalogo de esta empresa.
        $known = Product::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('barcode', $eans)
            ->get()
            ->keyBy('barcode');

        $rows = [];

        foreach ($normalized as $product) {
            $match = $product->ean === null ? null : $known->get($product->ean);

            /*
             * Cuatro estados, y la diferencia entre los dos ultimos es la que importa:
             *
             *   new        no esta en el catalogo
             *   outdated   esta, pero la fuente tiene algo que le falta
             *   up_to_date esta y la fuente no le aporta nada: no hay para que volver a traerlo
             *   deleted    esta pero eliminado; no resucita solo
             *
             * Quien decide si «aporta algo» es el importador, con el mismo metodo que usa al
             * importar. Si fueran dos, la pantalla ofreceria articulos que al traerse no
             * cambian nada.
             */
            $changes = $match !== null && ! $match->trashed()
                ? $this->importer->pendingChanges($match, $product)
                : [];

            $status = match (true) {
                $match === null => 'new',
                $match->trashed() => 'deleted',
                $changes !== [] => 'outdated',
                default => 'up_to_date',
            };

            $rows[] = [
                'ean' => $product->ean,
                'name' => $product->name,
                'brand' => $product->brand,
                'packaging' => $product->packaging,
                'category' => $product->externalCategory,
                'image' => $product->imageUrl,
                'usable' => $product->isUsable(),
                'reason' => $product->reason === null ? null : (CatalogImportItem::REASON_LABELS[$product->reason] ?? $product->reason),
                'status' => $status,
                'current_name' => $match?->name,
                // Que le aportaria, en lenguaje de tienda, para que se vea por que vale la pena.
                'adds' => array_values(array_map(
                    fn (string $field) => self::FIELD_LABELS[$field] ?? $field,
                    array_keys($changes)
                )),
            ];
        }

        return response()->json([
            'count' => $result['count'],
            'page' => $result['page'],
            'page_size' => $result['page_size'],
            'products' => $rows,
            // Cuantos de esta pagina valen la pena, para poder decirlo sin que el navegador
            // tenga que contar estados a mano.
            'worth' => count(array_filter($rows, fn (array $row) => $row['usable'] && in_array($row['status'], ['new', 'outdated'], true))),
        ]);
    }

    public function store(StoreCatalogImportRequest $request): RedirectResponse
    {
        $this->authorize('create', CatalogImport::class);

        $companyId = TenantContext::requireCompanyId();

        // Una empresa no puede tener dos corridas a la vez: el job tambien lo impide, pero
        // avisarlo aqui evita que el administrador se quede mirando una pantalla que no avanza.
        $running = CatalogImport::query()->where('company_id', $companyId)->running()->first();

        if ($running) {
            return back()->withErrors([
                'source' => 'Ya hay una importación en curso para tu empresa. Espera a que termine o cancélala.',
            ]);
        }

        $criteria = $request->criteria()->toArray();
        $criteria['eans'] = $request->selectedEans();
        // Queda guardada para poder repetir o ejecutar la corrida sin volver a preguntarla.
        $criteria['category_id'] = (int) $request->validated('category_id');

        $path = null;

        if ($request->isCsv()) {
            // Se guarda en el disco privado: quien la corra lo lee y lo borra al terminar.
            $path = $request->file('file')->store('catalog-imports', Uploads::privateDiskName());
            // Tambien en los criterios: si no hay cola, el boton de ejecutar necesita saber
            // donde quedo el archivo.
            $criteria['csv_path'] = $path;
        }

        $import = CatalogImport::create([
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'source' => $request->isCsv() ? CatalogImport::SOURCE_CSV : CatalogImport::SOURCE_API,
            'criteria' => $criteria,
            'status' => CatalogImport::STATUS_PENDING,
        ]);

        ImportCatalogJob::dispatch($import->id, $criteria['category_id'], $path);

        return redirect()
            ->route('catalog.import.index', ['importacion' => $import->id])
            ->with('success', 'La importación quedó en cola. El avance se actualiza solo.');
    }

    /**
     * Ejecuta la corrida en el acto, sin pasar por la cola.
     *
     * En una instalacion de mostrador no siempre hay un `queue:work` corriendo, y una
     * importacion que se queda «En espera» para siempre no le sirve a nadie. Esto la corre
     * dentro de la peticion: el navegador espera, pero termina.
     */
    public function run(Request $request, CatalogImport $import): RedirectResponse
    {
        $this->authorize('run', $import);

        if ($import->status !== CatalogImport::STATUS_PENDING) {
            return back()->withErrors([
                'status' => $import->isFinal()
                    ? 'Esa importación ya había terminado.'
                    : 'Esa importación ya está corriendo.',
            ]);
        }

        // Otra corriendo al mismo tiempo se pisaria con esta en el catalogo.
        $busy = CatalogImport::query()
            ->where('company_id', $import->company_id)
            ->where('status', CatalogImport::STATUS_PROCESSING)
            ->exists();

        if ($busy) {
            return back()->withErrors(['status' => 'Ya hay otra importación corriendo. Espera a que termine.']);
        }

        // Recorrer una fuente externa a una peticion por segundo tarda mas que el limite de
        // un PHP normal; sin esto la pagina se corta a la mitad y deja la corrida a medias.
        set_time_limit(0);

        $categoryId = $this->categoryFor($import);

        if ($categoryId === null) {
            return back()->withErrors(['status' => 'No hay ninguna categoría donde poner los artículos.']);
        }

        $job = new ImportCatalogJob($import->id, $categoryId, $this->csvPathFor($import));

        try {
            app()->call([$job, 'handle']);
        } catch (\Throwable $exception) {
            // Aqui no hay cola que reintente: lo que falle se cierra en el acto, con su motivo.
            $job->failed($exception);

            return back()->withErrors(['status' => $import->refresh()->error_message ?? $exception->getMessage()]);
        }

        $import->refresh();

        return back()->with(
            'success',
            sprintf(
                'Importación terminada: %d nuevos, %d al día, %d omitidos.',
                $import->total_imported,
                $import->total_updated,
                $import->total_skipped,
            )
        );
    }

    /** Estado de una corrida, para el sondeo de la pantalla. */
    public function show(CatalogImport $import): JsonResponse
    {
        $this->authorize('view', $import);

        return response()->json(['import' => $this->summary($import->load('user:id,name,last_name'))]);
    }

    /** El detalle articulo por articulo. */
    public function items(Request $request, CatalogImport $import): JsonResponse
    {
        $this->authorize('view', $import);

        $result = $request->string('result')->toString();

        $items = $import->items()
            ->with('product:id,name,sku')
            ->when(
                in_array($result, array_keys(CatalogImportItem::RESULT_LABELS), true),
                fn ($query) => $query->where('result', $result)
            )
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (CatalogImportItem $item) => [
                'id' => $item->id,
                'ean' => $item->ean,
                'name' => $item->name,
                'result' => $item->result,
                'result_label' => $item->resultLabel(),
                'reason' => $item->reasonLabel(),
                'product' => $item->product ? ['id' => $item->product->id, 'name' => $item->product->name, 'sku' => $item->product->sku] : null,
            ]);

        return response()->json(['items' => $items, 'counts' => $this->countsByResult($import)]);
    }

    /**
     * El detalle completo en CSV.
     *
     * Va por el servidor y no armando el archivo en el navegador: el listado viene paginado, y
     * exportar solo la pagina que se esta viendo seria un archivo incompleto con nombre de
     * completo. Se recorre por bloques para no cargar cinco mil filas en memoria.
     */
    public function export(Request $request, CatalogImport $import): StreamedResponse
    {
        $this->authorize('view', $import);

        $result = $request->string('result')->toString();
        $filtered = in_array($result, array_keys(CatalogImportItem::RESULT_LABELS), true);

        $query = $import->items()
            ->with('product:id,name,sku')
            ->when($filtered, fn ($builder) => $builder->where('result', $result))
            ->orderBy('id');

        $name = 'importacion-'.$import->id.($filtered ? '-'.$result : '').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');
            // Sin el BOM, Excel se come los acentos.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Codigo de barras', 'Nombre', 'Resultado', 'Motivo', 'SKU'], ';');

            $query->chunk(500, function ($items) use ($handle) {
                foreach ($items as $item) {
                    fputcsv($handle, [
                        // La comilla obliga a Excel a leerlo como texto: sin ella se come el
                        // cero de la izquierda y el codigo deja de servir.
                        $item->ean === null ? '' : "'".$item->ean,
                        $item->name ?? $item->product?->name,
                        $item->resultLabel(),
                        $item->reasonLabel(),
                        $item->product?->sku,
                    ], ';');
                }
            });

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function cancel(CatalogImport $import): RedirectResponse
    {
        $this->authorize('cancel', $import);

        if ($import->isFinal()) {
            return back()->withErrors(['status' => 'Esa importación ya había terminado.']);
        }

        // El job mira este estado entre bloques y se detiene solo; lo ya importado se queda.
        $import->update(['status' => CatalogImport::STATUS_CANCELLED, 'finished_at' => now()]);

        return back()->with('success', 'La importación quedó cancelada. Lo que ya había entrado se conserva.');
    }

    /**
     * La categoria donde caen los articulos nuevos de esta corrida.
     *
     * Las corridas viejas no la guardaban; para esas se toma la primera activa de la empresa
     * antes que dejar el boton sin funcionar.
     */
    protected function categoryFor(CatalogImport $import): ?int
    {
        $stored = $import->criteria['category_id'] ?? null;

        if (is_int($stored) || (is_string($stored) && $stored !== '')) {
            $exists = Category::query()
                ->withoutGlobalScopes()
                ->where('company_id', $import->company_id)
                ->whereKey((int) $stored)
                ->exists();

            if ($exists) {
                return (int) $stored;
            }
        }

        $first = Category::query()
            ->withoutGlobalScopes()
            ->where('company_id', $import->company_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->value('id');

        return $first === null ? null : (int) $first;
    }

    /**
     * El archivo que espera una corrida por CSV.
     *
     * Solo hay uno pendiente por corrida y el job lo borra al terminar, asi que si ya no esta
     * es que otra pasada se lo llevo.
     */
    protected function csvPathFor(CatalogImport $import): ?string
    {
        if ($import->source !== CatalogImport::SOURCE_CSV) {
            return null;
        }

        return $import->criteria['csv_path'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function runningPayload(int $companyId): ?array
    {
        $running = CatalogImport::query()
            ->where('company_id', $companyId)
            ->running()
            ->with('user:id,name,last_name')
            ->latest('id')
            ->first();

        return $running ? $this->summary($running) : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(CatalogImport $import): array
    {
        return [
            'id' => $import->id,
            'source' => $import->source,
            'source_label' => $import->sourceLabel(),
            'status' => $import->status,
            'status_label' => $import->statusLabel(),
            'is_final' => $import->isFinal(),
            'progress' => $import->progress(),
            'criteria_summary' => $import->source === CatalogImport::SOURCE_CSV
                ? 'Archivo CSV'
                : \App\Services\Catalog\SearchCriteria::fromArray($import->criteria ?? [])->summary(),
            'totals' => [
                'detected' => $import->total_detected,
                'imported' => $import->total_imported,
                'updated' => $import->total_updated,
                'skipped' => $import->total_skipped,
                'failed' => $import->total_failed,
            ],
            'error_message' => $import->error_message,
            'user' => $import->user?->full_name,
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'created_at' => $import->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function countsByResult(CatalogImport $import): array
    {
        $counts = $import->items()
            ->selectRaw('result, COUNT(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result')
            ->all();

        return collect(array_keys(CatalogImportItem::RESULT_LABELS))
            ->mapWithKeys(fn (string $result) => [$result => (int) ($counts[$result] ?? 0)])
            ->all();
    }
}
