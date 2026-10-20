<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePosSaleRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Service;
use App\Models\Store;
use App\Services\Payments\PaymentMethodAvailability;
use App\Services\PosCustomerPresenter;
use App\Services\PromotionService;
use App\Services\ReceiptService;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\ServicePricing;
use App\Services\ServiceSaleBuilder;
use App\Services\SettingsService;
use App\Services\WarehouseService;
use App\Settings\SettingsSchema;
use App\Support\StoreContext;
use App\Support\TenantContext;
use App\Support\Uploads;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SaleController extends Controller
{
    public function __construct(
        protected SaleService $sales,
        protected RegisterSessionService $sessions,
        protected SettingsService $settings,
        protected PromotionService $promotions,
        protected WarehouseService $warehouses,
        protected ServiceSaleBuilder $serviceLines,
        protected ServicePricing $pricing,
        protected PaymentMethodAvailability $availability,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();
        $user = $request->user();

        // Se puede vender en una caja que abrio otro: en el mostrador se turnan durante el
        // dia. La eleccion viaja en la direccion para que recargar no la pierda.
        $session = $this->sessions->openSessionFor($user, $storeId, $request->integer('caja') ?: null);

        if (! $session) {
            // Sin ninguna caja abierta no se puede vender: el POS lleva derecho a la apertura.
            return redirect()->route('registers.opening.create')
                ->with('warning', 'Abre la caja antes de empezar a vender.');
        }

        $store = Store::query()->findOrFail($storeId);
        $open = $this->sessions->openSessions($storeId);

        return Inertia::render('Pos/Sale', [
            'session' => [
                'id' => $session->id,
                'register' => $session->register?->name,
                'register_code' => $session->register?->code,
                // Quien abrio el turno, que no siempre es quien esta vendiendo.
                'cashier' => $session->user?->full_name,
                'is_own' => (int) $session->user_id === (int) $user->id,
                'opened_at' => $session->opened_at->toIso8601String(),
            ],
            // Las cajas abiertas de la sede, para poder cambiarse cuando hay varias.
            'openSessions' => $open
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'register' => $item->register?->name,
                    'cashier' => $item->user?->full_name,
                ])
                ->values(),
            // A quien se le puede atribuir la venta. Por defecto, quien abrio la caja.
            'sellers' => $this->sellerOptions($companyId, $storeId),
            'defaultSeller' => $session->user?->employee_id ?? $user->employee_id,
            'store' => ['id' => $store->id, 'name' => $store->name],
            'categories' => Category::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name])
                ->values(),
            'initialProducts' => $this->productPayload($companyId, $storeId, null, null, 60),
            // Una sola busqueda: el cajero no elige «articulo o servicio» antes de buscar.
            'initialServices' => $this->servicePayload($companyId, $storeId, null, 60),
            'permissions' => [
                'discount' => $user->can('pos.sale.discount'),
                'credit' => $user->can('pos.sale.credit'),
                'cancel' => $user->can('pos.sale.cancel'),
                'suspend' => $user->can('pos.sale.suspend'),
                'refund' => $user->can('pos.sale.refund'),
                // Que puede cambiar el cajero en los articulos de un servicio; lo demas se pide con PIN.
                'service_remove' => $user->can('pos.sale.service_remove'),
                'service_reduce' => $user->can('pos.sale.service_reduce'),
                'service_increase' => $user->can('pos.sale.service_increase'),
                'service_add' => $user->can('pos.sale.service_add'),
            ],
            'maxDiscountPercent' => (int) $this->settings->get($companyId, 'pos.allow_discount_percent'),
            'cashRounding' => $this->sales->roundingStep($companyId),
            // Los medios de esta caja a esta hora (Medios de pago). La modal no tiene lista propia.
            'paymentMethods' => $this->availability->forRegister($companyId, $storeId, $session->register_id, $user->can('pos.sale.gateway')),
            'paymentPollSeconds' => 3,
            // Un cobro por QR o datafono que quedo esperando cuando se recargo la pantalla.
            'pendingGatewayReference' => PaymentTransaction::query()
                ->where('register_session_id', $session->id)
                ->where('channel', PaymentTransaction::CHANNEL_POS)
                ->where('status', PaymentTransaction::STATUS_PENDING)
                ->where('created_by_user_id', $user->id)
                ->latest('id')
                ->value('reference'),
            // Como se reparte la pantalla (Ajustes > Caja). Solo esta pantalla lo usa.
            'posLayout' => $this->posLayout($companyId),
        ]);
    }

    /**
     * La disposicion de la caja que eligio la empresa, ya saneada: una clave rara en la base no
     * puede dejar la caja sin boton de cobrar.
     *
     * @return array<string, mixed>
     */
    protected function posLayout(int $companyId): array
    {
        $settings = $this->settings->all($companyId);
        $defaults = SettingsService::DEFAULTS;
        $pick = fn (string $key, array $allowed) => in_array($settings[$key] ?? null, $allowed, true) ? $settings[$key] : $defaults[$key];

        $shortcuts = is_array($settings['pos.shortcuts'] ?? null) ? $settings['pos.shortcuts'] : [];
        $shortcuts = array_merge($defaults['pos.shortcuts'], array_intersect_key($shortcuts, $defaults['pos.shortcuts']));

        return [
            'ticket_side' => $pick('pos.ticket_side', SettingsSchema::TICKET_SIDES),
            'catalog_view' => $pick('pos.catalog_view', SettingsSchema::CATALOG_VIEWS),
            'tile_size' => $pick('pos.tile_size', SettingsSchema::TILE_SIZES),
            'total_size' => $pick('pos.total_size', SettingsSchema::TOTAL_SIZES),
            'rail' => $settings['pos.rail'] ?? $defaults['pos.rail'],
            'shortcuts' => $shortcuts,
            'sounds' => (bool) ($settings['pos.sounds'] ?? false),
        ];
    }

    /**
     * A quien se le puede atribuir una venta.
     *
     * Las fichas activas de la sede, mas las de la empresa sin sede asignada. Un asesor que no
     * tiene cuenta para entrar al sistema igual puede quedar como vendedor: la venta es suya
     * aunque el tiquete lo cobre otro.
     *
     * @return list<array{value: int, label: string, position: string|null}>
     */
    protected function sellerOptions(int $companyId, int $storeId): array
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->where('status', Employee::STATUS_ACTIVE)
            ->where('store_id', $storeId)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'position'])
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => trim($employee->first_name.' '.$employee->last_name),
                'position' => $employee->position,
            ])
            ->all();
    }

    /**
     * Buscador del POS: nombre, SKU o codigo de barras. Devuelve JSON, no Inertia.
     */
    public function products(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $search = $request->string('search')->toString() ?: null;
        // La barra de categorias tiene su propio filtro «Servicios».
        $onlyServices = $request->boolean('services');
        $categoryId = $request->integer('category') ?: null;

        return response()->json([
            'products' => $onlyServices ? [] : $this->productPayload($companyId, $storeId, $search, $categoryId, 40),
            'services' => $this->servicePayload($companyId, $storeId, $search, 40, $onlyServices ? null : $categoryId),
            // El codigo de un articulo con subarticulos no cobra nada: la caja ofrece elegir cual.
            'family' => $search && ! $onlyServices ? $this->familyByCode($companyId, $storeId, $search) : null,
        ]);
    }

    /**
     * Cliente por documento o nombre, con su situacion de credito.
     */
    public function customers(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $search = $request->string('search')->toString();

        $customers = Customer::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($search, fn ($query, $term) => $query->where(function ($inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('document_number', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            }))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json(['customers' => app(PosCustomerPresenter::class)->present($customers)->values()]);
    }

    public function store(StorePosSaleRequest $request): RedirectResponse
    {
        $storeId = StoreContext::requireStoreId();
        $store = Store::query()->findOrFail($storeId);

        // La caja la manda el tiquete: si se estaba vendiendo en la que abrio otro, la plata
        // entra a ese cajon y no al que el servidor elija por su cuenta.
        $session = $this->sessions->openSessionFor(
            $request->user(),
            $storeId,
            $request->integer('register_session_id') ?: null,
        );

        if (! $session) {
            return redirect()->route('registers.opening.create')->with('error', 'No hay ninguna caja abierta en esta sede.');
        }

        $sale = $this->sales->create($request->saleData(), $request->user(), $store, $session);

        // La autorizacion vale para una venta, no para el resto del turno.
        foreach (array_keys(AuthorizationController::ACTIONS) as $action) {
            AuthorizationController::forget($request, $action);
        }

        $cashPayment = $sale->payments->firstWhere('method', SalePayment::METHOD_CASH);

        // El POS necesita el tiquete recien hecho para ofrecer la impresion sin buscarlo.
        return redirect()->route('pos.index')->with('last_sale', [
            'id' => $sale->id,
            'number' => $sale->number,
            'total' => $sale->total,
            'rounding_total' => $sale->rounding_total,
            'due_total' => $sale->due_total,
            'received_amount' => $cashPayment?->amount,
            'change_amount' => $cashPayment?->change_amount,
            // El desglose deja ver de un vistazo con que se pago un cobro repartido.
            'payments' => $sale->payments->sortBy('sequence')->values()->map(fn (SalePayment $payment) => [
                'method' => $payment->method,
                'label' => $payment->methodLabel(),
                'amount' => $payment->netAmount(),
                'reference' => $payment->reference,
            ])->all(),
            'print_url' => route('pos.sales.print', $sale),
        ]);
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        $this->authorizeCompany($sale);

        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:160']],
            ['reason.required' => 'Escribe el motivo de la anulacion.']
        );

        $this->sales->cancel($sale, $request->user(), $validated['reason']);

        return back()->with('success', "La venta {$sale->number} quedo anulada y las existencias se devolvieron.");
    }

    /**
     * Tiquete listo para imprimir: HTML de 80 mm que abre el dialogo de impresion solo.
     *
     * Funciona con cualquier termica instalada como impresora de Windows, sin agente
     * externo ni descargar un archivo en cada venta.
     */
    public function printReceipt(Request $request, Sale $sale, ReceiptService $receipts): HttpResponse
    {
        $this->authorizeCompany($sale);

        $sale->load(['items', 'payments', 'customer', 'store', 'user', 'seller', 'company']);

        // Papel, letra, campos, codigo y copias salen de Ajustes > Tiquete de la sede de la venta.
        return response()->view('pos.receipt-print', $receipts->viewData($sale, $sale->company ?? TenantContext::company()));
    }

    /**
     * Recibo termico de 80 mm en PDF, para archivar o reenviar.
     */
    public function receipt(Request $request, Sale $sale, ReceiptService $receipts): HttpResponse
    {
        $this->authorizeCompany($sale);

        $sale->load(['items', 'payments', 'customer', 'store', 'user', 'seller', 'session.register', 'company']);

        $data = $receipts->viewData($sale, $sale->company ?? TenantContext::company(), forPdf: true);
        $receipt = $data['receipt'];

        // El alto se estima por contenido: el PDF no tiene alto automatico como la termica.
        $height = 150 + ($sale->items->count() * 12) + ($sale->payments->count() * 8)
            + (count($receipt['header']) * 10)
            + ($data['logo'] ? 66 : 0)
            + ($receipt['code'] === 'qr' ? 72 : ($receipt['code'] === 'barcode' ? 38 : 0));

        // 80 mm = 226,77 pt; 58 mm = 164,41 pt.
        $width = round($receipt['paper_width'] * 72 / 25.4, 2);

        $pdf = Pdf::loadView('pdf.receipt', $data)->setPaper([0, 0, $width, $height]);

        return $pdf->stream("tiquete-{$sale->number}.pdf");
    }

    /**
     * Ultimas ventas del turno, para anular o reimprimir.
     */
    public function recent(Request $request): JsonResponse
    {
        $storeId = StoreContext::requireStoreId();
        $session = $this->sessions->openSessionFor($request->user(), $storeId);

        $sales = Sale::query()
            ->where('store_id', $storeId)
            ->when($session, fn ($query) => $query->where('register_session_id', $session->id))
            ->with('customer:id,name')
            ->orderByDesc('sold_at')
            ->limit(20)
            ->get()
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'total' => $sale->total,
                'status' => $sale->status,
                'status_label' => $sale->statusLabel(),
                'customer' => $sale->customer?->name,
                'sold_at' => $sale->sold_at->toIso8601String(),
            ])
            ->values();

        return response()->json(['sales' => $sales]);
    }

    /**
     * Cuanto rebajan las promociones sobre el tiquete que el cajero tiene armado.
     *
     * El 3x2 y los combos no se notan en el precio unitario: solo existen cuando ya hay
     * cantidades. El POS no puede calcularlos por su cuenta sin repetir aqui la aritmetica
     * del servidor, y dos copias de una regla de plata terminan discrepando. Asi que pregunta.
     */
    public function quote(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $validated = $request->validate([
            'items' => ['present', 'array'],
            'items.*.product_id' => ['nullable', 'integer', 'required_without:items.*.service_id'],
            'items.*.service_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'items.*.discount' => ['nullable', 'integer', 'min:0'],
            'items.*.skip_promotion' => ['nullable', 'boolean'],
            'items.*.components' => ['nullable', 'array'],
            'items.*.components.*.product_id' => ['required', 'integer'],
            'items.*.components.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.components.*.discount' => ['nullable', 'integer', 'min:0'],
        ]);

        // Los articulos sueltos pasan por las promociones; los servicios, por el mismo armador que
        // usa la venta, asi el tiquete muestra exactamente lo que se va a cobrar.
        $plain = array_filter($validated['items'], fn (array $line) => empty($line['service_id']));
        $result = $this->promotions->apply(array_values($plain), $companyId, PromotionService::CHANNEL_POS);
        $promoted = array_combine(array_keys($plain), $result['lines']) ?: [];

        $lines = [];

        foreach ($validated['items'] as $index => $line) {
            if (empty($line['service_id'])) {
                $quoted = $promoted[$index];
                $lines[] = [
                    'kind' => 'product',
                    'product_id' => (int) ($quoted['product_id'] ?? 0),
                    'unit_price' => (int) ($quoted['unit_price'] ?? 0),
                    // Lo que rebaja la promocion, sin contar el descuento que puso el cajero.
                    'promo_discount' => max(0, (int) ($quoted['discount'] ?? 0) - (int) ($line['discount'] ?? 0)),
                ];

                continue;
            }

            try {
                $built = $this->serviceLines->build($line, $companyId, PromotionService::CHANNEL_POS);
                $lines[] = [
                    'kind' => 'service',
                    'service_id' => (int) $line['service_id'],
                    'unit_price' => $built['line']['unit_price'],
                    'components' => array_map(fn (array $component) => [
                        'product_id' => $component['product']->id,
                        'unit_price' => $component['unit_price'],
                    ], $built['components']),
                    'error' => null,
                ];
            } catch (ValidationException $exception) {
                $lines[] = [
                    'kind' => 'service',
                    'service_id' => (int) $line['service_id'],
                    'unit_price' => null,
                    'components' => [],
                    'error' => collect($exception->errors())->flatten()->first(),
                ];
            }
        }

        return response()->json(['lines' => $lines, 'applied' => $result['applied']]);
    }

    /**
     * El articulo con subarticulos cuyo codigo de referencia se escaneo, con sus subarticulos
     * activos para escoger.
     *
     * @return array{id: int, name: string, variants: list<array<string, mixed>>}|null
     */
    protected function familyByCode(int $companyId, int $storeId, string $code): ?array
    {
        $family = ProductFamily::query()
            ->where('company_id', $companyId)
            ->where('code', trim($code))
            ->where('is_active', true)
            ->first(['id', 'name']);

        if (! $family) {
            return null;
        }

        return [
            'id' => $family->id,
            'name' => $family->name,
            'variants' => $this->productPayload($companyId, $storeId, null, null, 200, $family->id),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function productPayload(int $companyId, int $storeId, ?string $search, ?int $categoryId, int $limit, ?int $familyId = null): array
    {
        $warehouseId = $this->warehouses->posSourceFor($storeId)->id;

        $rows = Product::query()
            // Lo que hay en las demas bodegas de la sede. No se puede cobrar, pero el cajero
            // necesita saber que existe para mandar a alguien por ello en vez de decir que no hay.
            ->selectSub(
                DB::table('product_stocks')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_stocks.product_id', 'products.id')
                    ->where('product_stocks.store_id', $storeId)
                    ->where('product_stocks.warehouse_id', '!=', $warehouseId),
                'other_stock'
            )
            ->where('products.company_id', $companyId)
            ->where('products.is_active', true)
            ->when($search, fn ($query, $term) => $query->where(function ($inner) use ($term) {
                $inner->where('products.name', 'like', "%{$term}%")
                    ->orWhere('products.sku', 'like', "%{$term}%")
                    ->orWhere('products.barcode', $term)
                    ->orWhere('products.barcode', 'like', "%{$term}%");
            }))
            ->when($categoryId, fn ($query, $category) => $query->where('products.category_id', $category))
            ->when($familyId, fn ($query, $family) => $query->where('products.product_family_id', $family))
            ->leftJoin('product_stocks', function ($join) use ($warehouseId) {
                $join->on('product_stocks.product_id', '=', 'products.id')
                    ->where('product_stocks.warehouse_id', '=', $warehouseId);
            })
            // Los subarticulos de un mismo articulo salen juntos y en el orden de sus valores
            // (500 ml, 900 ml, 2 L), no alfabetico.
            ->leftJoin('product_families', 'product_families.id', '=', 'products.product_family_id')
            ->orderByRaw('COALESCE(product_families.name, products.name)')
            ->orderBy('products.variant_sort')
            ->orderBy('products.name')
            ->limit($limit)
            ->addSelect([
                'products.id',
                'products.name',
                'products.sku',
                'products.barcode',
                'products.unit',
                'products.sold_by_weight',
                'products.price',
                'products.tax_rate',
                'products.category_id',
                'products.allow_negative_stock',
                'products.thumb_path',
                'products.image_path',
                'products.product_family_id',
                'products.variant_label',
                'product_stocks.quantity as stock_quantity',
                'product_stocks.reserved_quantity',
            ])
            ->get();

        // El cajero tiene que ver el mismo precio que va a cobrar el servidor: si el POS
        // arma el tiquete con el precio de lista y la venta se registra con el de promocion,
        // el cobro no cuadra con el total. Y tambien las que dependen de la cantidad, que no
        // se notan en el precio pero hay que poder anunciarlas al seleccionar el articulo.
        $promos = $this->promotions->descriptors($rows, $companyId, PromotionService::CHANNEL_POS);

        return $rows
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'sku' => $row->sku,
                'barcode' => $row->barcode,
                'unit' => $row->unit,
                'sold_by_weight' => (bool) $row->sold_by_weight,
                'price' => (int) ($promos[(int) $row->id]['price'] ?? $row->price),
                'promotion' => $promos[(int) $row->id] ?? null,
                'tax_rate' => (float) $row->tax_rate,
                'category_id' => $row->category_id ? (int) $row->category_id : null,
                'allow_negative_stock' => (bool) $row->allow_negative_stock,
                'thumb' => $row->thumb_path ?: $row->image_path,
                'thumb_url' => Uploads::url($row->thumb_path ?: $row->image_path),
                // «Talla 8 · Azul», «900 ml»: para leerlo rapido en la tarjeta y el tiquete.
                'variant_label' => $row->variant_label,
                'family_id' => $row->product_family_id ? (int) $row->product_family_id : null,
                'stock' => round((float) ($row->stock_quantity ?? 0) - (float) ($row->reserved_quantity ?? 0), 3),
                'other_stock' => round((float) ($row->other_stock ?? 0), 3),
            ])
            ->values()
            ->all();
    }

    /**
     * Los servicios de la caja, con sus articulos, precios del canal y existencia de la bodega de
     * caja. Un servicio con un articulo inactivo sale igual, marcado, para explicar por que no se
     * cobra.
     *
     * @return list<array<string, mixed>>
     */
    protected function servicePayload(int $companyId, int $storeId, ?string $search, int $limit, ?int $categoryId = null): array
    {
        $warehouseId = $this->warehouses->posSourceFor($storeId)->id;

        $services = Service::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($search, fn ($query, $term) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('barcode', $term)))
            ->when($categoryId, fn ($query, $category) => $query->where('category_id', $category))
            ->with(['components.product', 'components.family'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        // Las presentaciones de cada articulo completo, para escoger cual lleva esta venta.
        $familyIds = $services->flatMap(fn (Service $service) => $service->components->pluck('product_family_id'))->filter()->unique();
        $options = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('product_family_id', $familyIds)
            ->where('is_active', true)
            ->orderBy('variant_sort')
            ->get()
            ->groupBy('product_family_id');

        $stock = ProductStock::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $services->flatMap(fn (Service $service) => $service->components->pluck('product_id'))->merge($options->flatten()->pluck('id'))->unique())
            ->get()
            ->keyBy('product_id');

        return $services->map(function (Service $service) use ($stock, $options) {
            $pricing = $this->pricing->breakdown($service, PromotionService::CHANNEL_POS);

            return [
                'id' => $service->id,
                'code' => $service->code,
                'barcode' => $service->barcode,
                'name' => $service->name,
                'thumb' => $service->thumb(),
                'thumb_url' => Uploads::url($service->thumb()),
                'price' => $pricing['value'],
                'total' => $pricing['total'],
                'tax_rate' => (float) $service->tax_rate,
                'pricing_mode' => $service->pricing_mode,
                'open_price' => (bool) $service->open_price,
                'category_id' => $service->category_id,
                'blocked' => $service->blockedComponents(),
                'components' => $service->components->values()->map(function ($component, int $index) use ($stock, $pricing, $service, $options) {
                    $product = $component->product;
                    $row = $stock->get($component->product_id);
                    $available = fn (int $id) => round((float) ($stock->get($id)?->quantity ?? 0) - (float) ($stock->get($id)?->reserved_quantity ?? 0), 3);

                    return [
                        'product_id' => (int) $component->product_id,
                        'name' => $product?->name ?? 'Artículo eliminado',
                        'unit' => $product?->unit ?? 'unit',
                        'sold_by_weight' => (bool) $product?->sold_by_weight,
                        'quantity' => round((float) $component->quantity, 3),
                        'returnable_by_default' => (bool) $component->returnable_by_default,
                        'unit_price' => $service->isItemized() ? $pricing['components'][$index]['unit_price'] : 0,
                        'tax_rate' => (float) ($product?->tax_rate ?? 0),
                        'stock' => round((float) ($row->quantity ?? 0) - (float) ($row->reserved_quantity ?? 0), 3),
                        'allow_negative_stock' => (bool) $product?->allow_negative_stock,
                        'is_active' => (bool) ($product && ! $product->trashed() && $product->is_active),
                        // El articulo completo: el cajero escoge la presentacion sin pedir firma.
                        'family' => $component->isFamily() ? [
                            'id' => (int) $component->product_family_id,
                            'name' => $component->family?->name,
                            'options' => $options->get($component->product_family_id, collect())->map(fn (Product $option) => [
                                'product_id' => $option->id,
                                'name' => $option->name,
                                'label' => $option->variant_label ?? $option->name,
                                'unit' => $option->unit,
                                'sold_by_weight' => (bool) $option->sold_by_weight,
                                'unit_price' => $service->isItemized() ? $this->pricing->componentPrice($option, PromotionService::CHANNEL_POS) : 0,
                                'tax_rate' => (float) $option->tax_rate,
                                'stock' => $available($option->id),
                                'allow_negative_stock' => (bool) $option->allow_negative_stock,
                                'is_active' => true,
                            ])->values()->all(),
                        ] : null,
                    ];
                })->all(),
            ];
        })->values()->all();
    }

    protected function authorizeCompany(Sale $sale): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $sale->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa venta no pertenece a tu empresa.'
        );
    }
}
