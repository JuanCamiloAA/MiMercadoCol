<?php

namespace Database\Seeders;

use App\Models\BillingCycle;
use App\Models\BusinessCategory;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Models\ProductFamilyImage;
use App\Models\ProductSupplier;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\Store;
use App\Models\StorefrontBlock;
use App\Models\Supplier;
use App\Models\SupplierReturnSettlement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerReturnService;
use App\Services\NumberSequenceService;
use App\Services\OrderService;
use App\Services\PaymentTermService;
use App\Services\Platform\CompanyProvisioner;
use App\Services\ProductAttributeCatalog;
use App\Services\ProductFamilyService;
use App\Services\PromotionService;
use App\Services\PurchaseOrderSplitter;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\StockTransferRequestService;
use App\Services\StockTransferService;
use App\Services\Storefront\StorefrontConfig;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use App\Services\Storefront\StorefrontTemplateService;
use App\Services\SupplierReturnService;
use App\Services\UserPermissionService;
use App\Services\WarehouseService;
use App\Storefront\BlockCatalogue;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\BannerPainter;
use Database\Seeders\Support\GarmentPainter;
use Database\Seeders\Support\StorefrontShowcase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tercera empresa de demostracion: «Moda Aurora», una tienda de ropa en Medellin.
 *
 * Sirve para ver la aplicacion completa con algo que no es mercado: articulos con subarticulos
 * por talla y color, proveedores de confeccion, compras que llegan a una bodega de reserva y por
 * partes, reposicion del piso de venta, traslados entre sedes (uno con faltante, uno en camino),
 * una solicitud de traslado, cuatro semanas de ventas en dos sedes, promociones, pedidos en
 * linea, devoluciones y cambios de talla en caja, y una devolucion a proveedor.
 *
 * Todo por los servicios de siempre y en orden: el reloj se mueve dia por dia mientras se siembra,
 * asi el kardex, las cajas y los informes cuentan la misma historia con sus fechas reales.
 */
class FashionCompanySeeder extends Seeder
{
    public const TAX_ID = '901888777-5';

    /** Cuantos dias de historia. */
    protected const DAYS = 28;

    /** Proveedores: clave => [nombre, NIT, contacto, dias de entrega, dias de visita, dias de plazo]. */
    protected const SUPPLIERS = [
        'denim' => ['Confecciones Denim Medellín', '900321654-1', 'Óscar Patiño', 5, ['lun', 'jue'], 30],
        'valle' => ['Textiles del Valle S.A.S.', '890456123-7', 'Luisa Fernanda Ríos', 3, ['mar', 'vie'], 15],
        'andina' => ['Moda Andina Ltda.', '830789456-2', 'Felipe Cardona', 7, ['mie'], 30],
        'calzado' => ['Calzado Antioqueño', '811234987-3', 'Gloria Henao', 10, ['lun'], 45],
    ];

    /** Categorias: clave => [nombre, icono de la tienda]. */
    protected const CATEGORIES = [
        'mujer' => ['Mujer', 'Dress'],
        'hombre' => ['Hombre', 'TShirt'],
        'jeans' => ['Jeans', 'Pants'],
        'ninos' => ['Niños', 'Hoodie'],
        'calzado' => ['Calzado', 'Sneaker'],
        'accesorios' => ['Accesorios', 'Handbag'],
    ];

    /** Colores que no trae el catalogo de fabrica. */
    protected const COLORS = [
        'Azul oscuro' => '#1B2A4A',
        'Azul claro' => '#7FA7D9',
        'Verde oliva' => '#6B7F3A',
        'Rosado' => '#F4A6C0',
        'Café' => '#6F4E37',
    ];

    /** Tallas numericas, en orden: pantalon de mujer y de niño, pantalon de hombre y calzado. */
    protected const NUMERIC_SIZES = ['2', '4', '6', '8', '10', '12', '14', '16', '28', '30', '32', '34', '35', '36', '37', '38', '39', '40', '41', '42'];

    /** Codigo corto de cada color en el SKU. */
    protected const COLOR_CODES = [
        'Negro' => 'NEG', 'Blanco' => 'BLA', 'Azul' => 'AZU', 'Rojo' => 'ROJ', 'Gris' => 'GRI', 'Beige' => 'BEI',
        'Azul oscuro' => 'AZO', 'Azul claro' => 'AZC', 'Verde oliva' => 'VOL', 'Rosado' => 'ROS', 'Café' => 'CAF',
    ];

    /**
     * Los articulos con subarticulos. `sizes` nulo: solo se escoge el color.
     *
     * @var list<array<string, mixed>>
     */
    protected const FAMILIES = [
        ['key' => 'jean_mujer', 'code' => 'JSM', 'name' => 'Jean skinny tono oscuro para mujer', 'category' => 'jeans', 'brand' => 'Aurora Denim', 'supplier' => 'denim', 'shape' => 'pants',
            'price' => 119900, 'cost' => 52000, 'min' => 2, 'sizes' => ['6', '8', '10', '12', '14', '16'], 'colors' => ['Azul oscuro', 'Negro'],
            'description' => 'Jean tiro alto, elástico y de bota tubo. Tela denim de 12 onzas con 2 % de elastano.'],
        ['key' => 'jean_hombre', 'code' => 'JCH', 'name' => 'Jean clásico para hombre', 'category' => 'jeans', 'brand' => 'Aurora Denim', 'supplier' => 'denim', 'shape' => 'pants',
            'price' => 129900, 'cost' => 56000, 'min' => 2, 'sizes' => ['28', '30', '32', '34', '36'], 'colors' => ['Azul oscuro', 'Azul claro'],
            'description' => 'Corte recto, cinco bolsillos, 100 % algodón.'],
        ['key' => 'camiseta', 'code' => 'CAM', 'name' => 'Camiseta básica de algodón', 'category' => 'hombre', 'brand' => 'Aurora Basics', 'supplier' => 'valle', 'shape' => 'shirt',
            'price' => 34900, 'cost' => 13500, 'min' => 3, 'sizes' => ['S', 'M', 'L', 'XL'], 'colors' => ['Blanco', 'Negro', 'Gris'],
            'description' => 'Cuello redondo, algodón peinado de 180 gramos. No se encoge.'],
        ['key' => 'blusa', 'code' => 'BLU', 'name' => 'Blusa manga corta', 'category' => 'mujer', 'brand' => 'Aurora', 'supplier' => 'valle', 'shape' => 'shirt',
            'price' => 59900, 'cost' => 24000, 'min' => 2, 'sizes' => ['XS', 'S', 'M', 'L'], 'colors' => ['Blanco', 'Rosado', 'Beige'],
            'description' => 'Blusa fresca en lino y viscosa, ideal para el clima de la ciudad.'],
        ['key' => 'vestido', 'code' => 'VES', 'name' => 'Vestido casual midi', 'category' => 'mujer', 'brand' => 'Aurora', 'supplier' => 'andina', 'shape' => 'dress',
            'price' => 149900, 'cost' => 62000, 'min' => 1, 'sizes' => ['S', 'M', 'L'], 'colors' => ['Negro', 'Verde oliva'],
            'description' => 'Vestido midi con cinturón en la misma tela. Va de día y de noche.'],
        ['key' => 'camisa', 'code' => 'COX', 'name' => 'Camisa oxford para hombre', 'category' => 'hombre', 'brand' => 'Aurora', 'supplier' => 'andina', 'shape' => 'shirt',
            'price' => 89900, 'cost' => 36000, 'min' => 2, 'sizes' => ['S', 'M', 'L', 'XL'], 'colors' => ['Blanco', 'Azul claro'],
            'description' => 'Manga larga, tela oxford de algodón. Lista para la oficina.'],
        ['key' => 'conjunto', 'code' => 'CNI', 'name' => 'Conjunto deportivo para niño', 'category' => 'ninos', 'brand' => 'Aurora Kids', 'supplier' => 'valle', 'shape' => 'shirt',
            'price' => 69900, 'cost' => 28000, 'min' => 2, 'sizes' => ['2', '4', '6', '8'], 'colors' => ['Azul', 'Rojo'],
            'description' => 'Sudadera y camiseta en algodón perchado.'],
        ['key' => 'tenis', 'code' => 'TEN', 'name' => 'Tenis urbanos', 'category' => 'calzado', 'brand' => 'Paso Firme', 'supplier' => 'calzado', 'shape' => 'shoe',
            'price' => 189900, 'cost' => 85000, 'min' => 1, 'sizes' => ['36', '37', '38', '39', '40', '41', '42'], 'colors' => ['Blanco', 'Negro'],
            'description' => 'Capellada en cuero, suela de caucho cosida. Hechos en Antioquia.'],
        ['key' => 'medias', 'code' => 'MED', 'name' => 'Medias tobilleras', 'category' => 'accesorios', 'brand' => 'Aurora Basics', 'supplier' => 'valle', 'shape' => 'sock',
            'price' => 9900, 'cost' => 3200, 'min' => 6, 'sizes' => null, 'colors' => ['Blanco', 'Negro', 'Gris'],
            'description' => 'Algodón con puntera reforzada. Talla única (35 a 42).'],
    ];

    /** Articulos individuales: clave => [SKU, nombre, categoria, proveedor, costo, precio, minimo, forma, color]. */
    protected const SINGLES = [
        'cinturon' => ['ACC-CIN-CAF', 'Cinturón de cuero café', 'accesorios', 'calzado', 18000, 49900, 3, 'belt', '#6F4E37'],
        'gorra' => ['ACC-GOR-NEG', 'Gorra bordada', 'accesorios', 'andina', 14000, 39900, 3, 'cap', '#1F1F1F'],
        'bolsa' => ['ACC-BOL-BEI', 'Bolsa ecológica de tela', 'accesorios', 'valle', 1800, 5900, 10, 'bag', '#D9C6A5'],
    ];

    /** Personal: [nombre, apellido, correo, rol, sede, cargo, documento, PIN]. */
    protected const STAFF = [
        ['Laura', 'Gómez', 'cajera@modaaurora.co', 'cajero', 'POB', 'Asesora de caja', '1017998877', '1234'],
        ['Santiago', 'Mejía', 'cajero.laureles@modaaurora.co', 'cajero', 'LAU', 'Asesor de caja', '1017665544', '4321'],
        ['Andrés', 'Zuluaga', 'bodega@modaaurora.co', 'bodega', 'POB', 'Auxiliar de bodega', '98112233', null],
        ['Carolina', 'Vélez', 'supervisor@modaaurora.co', 'supervisor', 'POB', 'Supervisora', '43998877', '2468'],
        ['Mateo', 'Arias', 'domicilios@modaaurora.co', 'domiciliario', 'POB', 'Mensajero', '1020334455', null],
    ];

    /** Clientes: [nombre, documento, barrio, telefono, compra en linea, cupo de credito]. */
    protected const CUSTOMERS = [
        ['Mariana Ospina', '1036654321', 'El Poblado', '3104455667', true, 0],
        ['Juliana Restrepo', '43123987', 'Laureles', '3115566778', true, 0],
        ['Camilo Duque', '1037123456', 'Envigado', '3126677889', true, 0],
        ['Daniela Correa', '1152443322', 'Belén', '3137788990', true, 0],
        ['Sebastián Gil', '71987654', 'Estadio', '3148899001', false, 0],
        ['Natalia Uribe', '1020987654', 'El Poblado', '3159900112', false, 0],
        ['Felipe Arango', '98765432', 'Laureles', '3160011223', false, 0],
        ['Isabela Quintero', '1035876543', 'Sabaneta', '3171122334', false, 0],
        ['Colegio Santa María', '900456789-1', 'Laureles', '6044123456', false, 2500000],
    ];

    protected Company $company;

    protected User $admin;

    protected User $warehouseKeeper;

    protected Store $poblado;

    protected Store $laureles;

    protected Warehouse $reserve;

    protected CarbonImmutable $today;

    /** La hora de verdad: hoy no se vende despues de ahora. */
    protected CarbonImmutable $realNow;

    /** El dia que se esta sembrando, relativo a hoy. */
    protected int $currentDay = 0;

    /** @var array<string, Supplier> */
    protected array $suppliers = [];

    /** @var array<string, Category> */
    protected array $categories = [];

    /** @var array<string, ProductFamily> */
    protected array $families = [];

    /** @var array<string, Product> */
    protected array $singles = [];

    /** @var array<string, User> */
    protected array $staff = [];

    /** @var Collection<int, Customer> */
    protected Collection $customers;

    /** @var array<string, PurchaseOrder> */
    protected array $orders = [];

    /** @var list<Sale> Ventas de caja con cliente, para devolverlas y cambiarlas despues. */
    protected array $returnable = [];

    /** @var list<string> Lo que no entro, para decirlo al final. */
    protected array $skipped = [];

    protected int $barcodeSequence = 1;

    public function __construct(
        protected CompanyProvisioner $provisioner,
        protected ProductFamilyService $familyService,
        protected ProductAttributeCatalog $attributes,
        protected StockService $stock,
        protected WarehouseService $warehouses,
        protected NumberSequenceService $numbers,
        protected PurchaseOrderSplitter $splitter,
        protected PaymentTermService $terms,
        protected StockTransferService $transfers,
        protected StockTransferRequestService $transferRequests,
        protected SaleService $sales,
        protected RegisterSessionService $registerSessions,
        protected PromotionService $promotions,
        protected OrderService $orderService,
        protected CustomerReturnService $customerReturns,
        protected SupplierReturnService $supplierReturns,
        protected StorefrontManager $storefronts,
        protected StorefrontPublisher $publisher,
        protected StorefrontTemplateService $templates,
        protected StorefrontConfig $storefrontConfig,
        protected UserPermissionService $permissions,
        protected BannerPainter $banners,
        protected GarmentPainter $garments,
    ) {}

    public function run(): void
    {
        if (Company::query()->where('tax_id', self::TAX_ID)->exists()) {
            return;
        }

        $this->realNow = CarbonImmutable::now('America/Bogota');
        $this->today = $this->realNow->startOfDay();

        try {
            $this->story();
        } finally {
            $this->travelTo(null);
        }

        if ($this->skipped !== [] && $this->command) {
            $this->command->warn('Moda Aurora: '.count($this->skipped).' operaciones de demostración no entraron. La primera: '.$this->skipped[0]);
        }
    }

    /**
     * Cuatro semanas, en orden. Cada dia primero lo que pasa en la bodega y despues lo que se
     * vende: asi una venta nunca sale de algo que todavia no habia llegado.
     */
    protected function story(): void
    {
        $this->at(-self::DAYS, 8);
        $this->provision();
        $this->staffAndStores();
        $this->suppliers();
        $this->catalog();
        $this->customers();

        for ($day = -self::DAYS + 1; $day <= 0; $day++) {
            $this->currentDay = $day;
            $this->warehouseDay($day);

            if ($day >= -22) {
                $this->salesDay($day);
            }
        }

        $this->storefrontShowcase();
    }

    /**
     * Al cierre de hoy, los casos de «Tienda en linea» en El Poblado: una talla publicada que se
     * agoto en el piso, otra que la reserva si tiene, otra con compra en camino al piso y una que
     * se vende en caja sin estar en linea.
     */
    protected function storefrontShowcase(): void
    {
        $this->at(0, 20);

        try {
            app(StorefrontShowcase::class)->seed($this->poblado, $this->admin, $this->reserve);
        } catch (Throwable $exception) {
            $this->skip('Casos de la tienda en línea', $exception);
        }
    }

    // ------------------------------------------------------------------ el reloj

    /**
     * Mueve el reloj a un dia (relativo a hoy) y una hora. Lo de hoy nunca queda en el futuro:
     * si se siembra temprano, se corre a unos minutos antes de ahora.
     */
    protected function at(int $day, int $hour, int $minute = 0): CarbonImmutable
    {
        $moment = $this->today->addDays($day)->setTime($hour, $minute);

        if ($moment->greaterThan($this->realNow->subMinutes(5))) {
            $moment = $this->realNow->subMinutes(5);
        }

        $this->travelTo($moment);

        return $moment;
    }

    protected function travelTo(?CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    // ------------------------------------------------------------------ la empresa

    protected function provision(): void
    {
        $result = $this->provisioner->provision([
            'company' => [
                'name' => 'Moda Aurora',
                'legal_name' => 'Moda Aurora S.A.S.',
                'tax_id' => self::TAX_ID,
                'email' => 'hola@modaaurora.co',
                'phone' => '6045558899',
                'address' => 'Calle 10 # 43D-25',
                'city' => 'Medellín',
                'business_category_id' => BusinessCategory::query()->where('slug', 'textiles-y-confeccion')->value('id'),
            ],
            'store' => [
                'name' => 'El Poblado',
                'code' => 'POB',
                'address' => 'Calle 10 # 43D-25',
                'city' => 'Medellín',
                'phone' => '6045558899',
                'latitude' => 6.2088,
                'longitude' => -75.5686,
            ],
            'admin' => [
                'name' => 'Valentina',
                'last_name' => 'Restrepo',
                'email' => 'admin@modaaurora.co',
                'password_hash' => Hash::make(UserSeeder::PASSWORD),
            ],
            // Varias bodegas por sede: lo trae el plan Cadena.
            'plan_id' => MembershipPlan::query()->where('slug', 'cadena')->value('id'),
            'cycle_id' => BillingCycle::query()->where('code', 'annual')->value('id'),
            'paid' => true,
        ]);

        $this->company = $result->company;
        $this->admin = $result->admin;
        $this->poblado = $result->store;
        $this->poblado->update(['opens_at' => '10:00:00', 'closes_at' => '20:00:00', 'suggested_cash_base' => 200000]);
    }

    protected function staffAndStores(): void
    {
        $this->laureles = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Laureles',
            'code' => 'LAU',
            'address' => 'Circular 4 # 70-10',
            'city' => 'Medellín',
            'phone' => '6045553344',
            'latitude' => 6.2453,
            'longitude' => -75.5916,
            'opens_at' => '10:00:00',
            'closes_at' => '20:00:00',
            'suggested_cash_base' => 150000,
            'is_active' => true,
        ]);

        Register::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => $this->laureles->id,
            'name' => 'Caja 1',
            'code' => 'C1',
            'is_active' => true,
        ]);

        // La reserva de El Poblado recibe las compras; el piso (la «Bodega principal») vende en
        // caja y en linea. Lo que llega se sube al piso con traslados directos.
        $this->reserve = Warehouse::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => $this->poblado->id,
            'name' => 'Bodega de reserva',
            'code' => 'RES',
            'type' => Warehouse::TYPE_STORAGE,
            'is_active' => true,
        ]);
        $this->warehouses->assignFlags($this->reserve, [Warehouse::FLAG_RECEIVING]);

        $stores = ['POB' => $this->poblado, 'LAU' => $this->laureles];
        $this->admin->stores()->syncWithoutDetaching(array_map(fn (Store $store) => $store->id, array_values($stores)));
        $super = User::query()->where('email', 'super@lacanasta.co')->first();

        foreach (self::STAFF as [$name, $lastName, $email, $roleName, $storeCode, $jobTitle, $document, $pin]) {
            $store = $stores[$storeCode];

            $employee = Employee::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'store_id' => $store->id,
                'first_name' => $name,
                'last_name' => $lastName,
                'document_type' => 'CC',
                'document_number' => $document,
                'birth_date' => $this->today->subYears(random_int(22, 45))->subDays(random_int(0, 360))->toDateString(),
                'phone' => '3'.random_int(100000000, 199999999),
                'email' => $email,
                'address' => 'Medellín, Antioquia',
                'emergency_contact_name' => 'Familiar',
                'emergency_contact_phone' => '3'.random_int(100000000, 199999999),
                'position' => $jobTitle,
                'contract_type' => 'indefinido',
                'hired_at' => $this->today->subMonths(random_int(3, 30))->toDateString(),
                'work_schedule' => [
                    'lunes' => ['start' => '09:30', 'end' => '18:30'],
                    'martes' => ['start' => '09:30', 'end' => '18:30'],
                    'miercoles' => ['start' => '09:30', 'end' => '18:30'],
                    'jueves' => ['start' => '09:30', 'end' => '18:30'],
                    'viernes' => ['start' => '11:00', 'end' => '20:00'],
                    'sabado' => ['start' => '11:00', 'end' => '20:00'],
                ],
                'status' => Employee::STATUS_ACTIVE,
            ]);

            $user = User::query()->create([
                'company_id' => $this->company->id,
                'employee_id' => $employee->id,
                'name' => $name,
                'last_name' => $lastName,
                'document' => $document,
                'email' => $email,
                'password' => Hash::make(UserSeeder::PASSWORD),
                'job_title' => $jobTitle,
                'pos_pin' => $pin ? Hash::make($pin) : null,
                'is_active' => true,
                'password_changed_at' => now(),
            ]);

            /** @var Role $role */
            $role = Role::query()->where('company_id', $this->company->id)->where('name', $roleName)->firstOrFail();
            $user->syncRoles([$role]);
            $this->permissions->applyRoleTemplate($user, $role, $super ?? $this->admin);
            $user->stores()->syncWithoutDetaching($roleName === 'supervisor' ? array_map(fn (Store $s) => $s->id, array_values($stores)) : [$store->id]);

            $this->staff[$email] = $user;
        }

        $this->warehouseKeeper = $this->staff['bodega@modaaurora.co'];
    }

    protected function suppliers(): void
    {
        foreach (self::SUPPLIERS as $key => [$name, $taxId, $contact, $lead, $days, $termDays]) {
            $this->suppliers[$key] = Supplier::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'name' => $name,
                'tax_id' => $taxId,
                'contact_name' => $contact,
                'phone' => '604 '.random_int(200, 899).' '.random_int(1000, 9999),
                'email' => Str::slug(Str::before($name, ' S.A.S.'), '.').'@proveedores.co',
                'address' => 'Medellín, Antioquia',
                'lead_time_days' => $lead,
                'delivery_days' => $days,
                'payment_term_id' => $this->terms->forDays($this->company->id, $termDays, true)?->id,
                'is_active' => true,
            ]);
        }
    }

    // ------------------------------------------------------------------ el catalogo

    protected function catalog(): void
    {
        foreach (self::CATEGORIES as $key => [$name, $icon]) {
            $this->categories[$key] = Category::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'icon' => $icon,
                'sort_order' => count($this->categories),
                'is_active' => true,
            ]);
        }

        // La talla de siempre (XS a XXL) mas las numericas, en orden, y los colores que faltan.
        $size = $this->attributes->attributeNamed($this->company->id, 'Talla');
        $color = $this->attributes->attributeNamed($this->company->id, 'Color');

        foreach (self::NUMERIC_SIZES as $value) {
            $this->attributes->addValue($size, $value);
        }

        foreach (self::COLORS as $name => $hex) {
            $this->attributes->addValue($color, $name, colorHex: $hex);
        }

        foreach (self::FAMILIES as $definition) {
            $this->families[$definition['key']] = $this->family($definition, $size, $color);
        }

        foreach (self::SINGLES as $key => [$sku, $name, $category, $supplier, $cost, $price, $min, $shape, $hex]) {
            $photo = $this->garments->paint($shape, $hex, $this->company->id);

            $product = Product::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'category_id' => $this->categories[$category]->id,
                'supplier_id' => $this->suppliers[$supplier]->id,
                'name' => $name,
                'sku' => $sku,
                'barcode' => $this->nextBarcode(),
                'brand' => 'Aurora',
                'unit' => Product::UNIT_UNIT,
                'cost' => $cost,
                'price' => $price,
                'online_price' => $price,
                'wholesale_price' => (int) round($price * 0.9, -2),
                'tax_rate' => 19,
                'min_stock' => $min,
                'is_active' => true,
                'is_online_visible' => true,
                'image_path' => $photo['path'] ?? null,
                'thumb_path' => $photo['thumb_path'] ?? null,
            ]);

            $this->link($product, $this->suppliers[$supplier], primary: true);
            $this->singles[$key] = $product;
        }

        // La camiseta tambien la surte Moda Andina: en compras se resalta con los dos.
        foreach ($this->variants('camiseta') as $variant) {
            $this->link($variant, $this->suppliers['andina'], primary: false, lastCost: 14200);
        }
    }

    /**
     * Un articulo con subarticulos por el unico camino, con una foto por color.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function family(array $definition, ProductAttribute $size, ProductAttribute $color): ProductFamily
    {
        $colors = collect($definition['colors'])->map(fn (string $name) => $this->value($color, $name));
        $sizes = $definition['sizes'] === null ? collect([null]) : collect($definition['sizes'])->map(fn (string $value) => $this->value($size, $value));
        $rows = [];

        foreach ($sizes as $sizeValue) {
            foreach ($colors as $colorValue) {
                $rows[] = [
                    'value_1_id' => $sizeValue ? $sizeValue->id : $colorValue->id,
                    'value_2_id' => $sizeValue ? $colorValue->id : null,
                    'sku' => implode('-', array_filter([$definition['code'], $sizeValue?->value, self::COLOR_CODES[$colorValue->value]])),
                    'barcode' => $this->nextBarcode(),
                    'price' => $definition['price'],
                    'online_price' => $definition['price'],
                    'wholesale_price' => (int) round($definition['price'] * 0.9, -2),
                    'cost' => $definition['cost'],
                    'min_stock' => $definition['min'],
                    'unit' => Product::UNIT_UNIT,
                    'is_active' => true,
                    'is_online_visible' => true,
                    // La mercancia entra por compras, no como existencia inicial.
                    'initial_stocks' => [],
                ];
            }
        }

        $family = $this->familyService->create([
            'name' => $definition['name'],
            'code' => 'FAM-'.$definition['code'],
            'description' => $definition['description'],
            'category_id' => $this->categories[$definition['category']]->id,
            'brand' => $definition['brand'],
            'supplier_id' => $this->suppliers[$definition['supplier']]->id,
            'tax_rate' => 19,
            'attribute_1_id' => $definition['sizes'] === null ? $color->id : $size->id,
            'attribute_2_id' => $definition['sizes'] === null ? null : $color->id,
            // Por defecto, una talla del medio en el primer color.
            'default_index' => intdiv(count($rows), 2) - intdiv(count($rows), 2) % $colors->count(),
            'variants' => $rows,
        ], $this->admin, $this->company->id);

        foreach ($family->variants()->get() as $variant) {
            $this->link($variant, $this->suppliers[$definition['supplier']], primary: true);
        }

        foreach ($colors as $position => $colorValue) {
            $photo = $this->garments->paint($definition['shape'], (string) $colorValue->color_hex, $this->company->id);

            if ($photo) {
                ProductFamilyImage::query()->create([
                    'product_family_id' => $family->id,
                    'path' => $photo['path'],
                    'thumb_path' => $photo['thumb_path'],
                    'attribute_value_id' => $colorValue->id,
                    'sort_order' => $position + 1,
                ]);
            }
        }

        return $family;
    }

    protected function value(ProductAttribute $attribute, string $text): ProductAttributeValue
    {
        return $this->attributes->valueFromText($attribute, $text);
    }

    /** El proveedor de un articulo en `product_suppliers`, como lo dejaria la ficha. */
    protected function link(Product $product, Supplier $supplier, bool $primary, ?int $lastCost = null): void
    {
        ProductSupplier::query()->withoutGlobalScopes()->updateOrCreate(
            ['product_id' => $product->id, 'supplier_id' => $supplier->id],
            // Sin compras todavia, el costo pactado es el de la ficha; la primera recepcion lo pisa.
            ['company_id' => $this->company->id, 'is_primary' => $primary, 'last_cost' => $lastCost ?? (int) $product->cost, 'lead_time_days' => $supplier->lead_time_days],
        );
    }

    /** @return Collection<int, Product> */
    protected function variants(string $family): Collection
    {
        return $this->families[$family]->variants()->orderBy('variant_sort')->get();
    }

    /** Un EAN-13 de prueba con su digito de verificacion: el escaner de caja lo lee. */
    protected function nextBarcode(): string
    {
        $base = '77098'.str_pad((string) $this->barcodeSequence++, 7, '0', STR_PAD_LEFT);
        $sum = 0;

        foreach (str_split($base) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return $base.((10 - $sum % 10) % 10);
    }

    protected function customers(): void
    {
        $this->customers = collect(self::CUSTOMERS)->map(function (array $row) {
            [$name, $document, $neighborhood, $phone, $online, $credit] = $row;
            $business = str_contains($document, '-');

            $customer = Customer::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'document_type' => $business ? 'NIT' : 'CC',
                'document_number' => $business ? Str::before($document, '-') : $document,
                'name' => $name,
                'business_name' => $business ? $name : null,
                'email' => Str::slug($name, '.').'@correo.co',
                'phone' => $phone,
                'address' => 'Calle '.random_int(1, 80).' # '.random_int(10, 99).'-'.random_int(10, 99),
                'neighborhood' => $neighborhood,
                'credit_limit' => $credit,
                'credit_term_days' => $credit > 0 ? 30 : 0,
                'payment_term_id' => $credit > 0 ? $this->terms->forDays($this->company->id, 30, true)?->id : null,
                'credit_enabled' => $credit > 0,
                'credit_blocked' => false,
                'is_online_user' => $online,
                'password' => $online ? Hash::make('Cliente123*') : null,
                'is_active' => true,
            ]);

            CustomerBalance::query()->firstOrCreate(['customer_id' => $customer->id], ['balance' => 0, 'overdue_balance' => 0]);

            return $customer;
        });
    }

    // ------------------------------------------------------------------ la bodega, dia por dia

    /** Lo que pasa en la bodega cada dia: compras, recepciones, traslados y su papeleo. */
    protected function warehouseDay(int $day): void
    {
        $poFloor = $this->warehouses->posSourceFor($this->poblado->id);
        $lauFloor = $this->warehouses->posSourceFor($this->laureles->id);

        match ($day) {
            // Primera semana: se arma el surtido de la temporada.
            -27 => $this->openingPurchases(),
            -26 => $this->receiveShare('valle', 0.7, 'Primera remesa: faltaron cajas'),
            -25 => $this->receiveShare('denim', 1.0),
            -24 => $this->afterFirstArrivals($poFloor),
            -22 => $this->receiveFirst('andina', fn (PurchaseOrderItem $item) => ! str_contains($item->product->name, 'Gorra')),
            -20 => $this->transferToLaureles(),
            -19 => $this->receiveInTransit($lauFloor, short: true),
            -18 => $this->receiveShare('calzado', 1.0),
            -17 => $this->replenishFloor($poFloor, 'Reposición del piso: llegó el calzado'),
            -15 => $this->midSeasonPurchase(),
            -13 => [$this->receiveShare('valle_mid', 1.0), $this->restockLaureles()],
            -12 => [$this->receiveShare('andina', 1.0, 'Llegaron las gorras'), $this->receiveInTransit($lauFloor)],
            -11 => $this->replenishFloor($poFloor, 'Reposición del piso: camisas y gorras'),
            -10 => $this->requestFromLaureles(),
            -9 => $this->fulfillRequest(),
            -8 => $this->supplierReturn(),
            -7 => [$this->receiveInTransit($lauFloor), $this->replenishFloor($poFloor, 'Reposición del piso de la semana')],
            -6 => $this->denimRestock(),
            -3 => [$this->receiveDenimPartially(), $this->replenishFloor($poFloor, 'Subir el jean de mujer que llegó')],
            -1 => [$this->sentPurchase(), $this->replenishFloor($poFloor, 'Reposición para el fin de semana')],
            0 => $this->todayPaperwork($poFloor, $lauFloor),
            default => null,
        };
    }

    protected function openingPurchases(): void
    {
        $this->at(-27, 9);

        $this->orders['denim'] = $this->purchase($this->poblado, 'denim', [
            ...$this->lines('jean_mujer', fn (Product $v) => in_array($this->size($v), ['6', '16'], true) ? 5 : 10),
            ...$this->lines('jean_hombre', fn (Product $v) => in_array($this->size($v), ['28', '36'], true) ? 5 : 10),
        ], PurchaseOrder::STATUS_SENT, 'Surtido de temporada');

        $this->orders['valle'] = $this->purchase($this->poblado, 'valle', [
            ...$this->lines('camiseta', fn (Product $v) => $this->size($v) === 'XL' ? 8 : 14),
            ...$this->lines('blusa', fn () => 8),
            ...$this->lines('conjunto', fn () => 6),
            ...$this->lines('medias', fn () => 48),
            [$this->singles['bolsa'], 60, 1800],
        ], PurchaseOrder::STATUS_SENT, 'Surtido de temporada');

        $this->orders['andina'] = $this->purchase($this->poblado, 'andina', [
            ...$this->lines('vestido', fn () => 6),
            ...$this->lines('camisa', fn () => 8),
            [$this->singles['gorra'], 30, 14000],
        ], PurchaseOrder::STATUS_SENT, 'Colección de temporada');

        $this->orders['calzado'] = $this->purchase($this->poblado, 'calzado', [
            ...$this->lines('tenis', fn (Product $v) => in_array($this->size($v), ['36', '42'], true) ? 3 : 6),
            [$this->singles['cinturon'], 20, 18000],
        ], PurchaseOrder::STATUS_SENT, 'Pedido de calzado');

        // Laureles compra directo lo basico: entra a su propia bodega.
        $this->orders['laureles'] = $this->purchase($this->laureles, 'valle', [
            ...$this->lines('camiseta', fn () => 8),
            ...$this->lines('medias', fn () => 24),
            ...$this->lines('conjunto', fn () => 3),
            [$this->singles['bolsa'], 30, 1800],
        ], PurchaseOrder::STATUS_SENT, 'Básicos para Laureles');
    }

    protected function afterFirstArrivals(Warehouse $poFloor): void
    {
        $this->receiveShare('valle', 1.0, 'Segunda remesa: llegó lo que faltaba');
        $this->receiveOrder($this->orders['laureles'], 1.0);
        $this->replenishFloor($poFloor, 'Montaje del piso de venta');

        $this->at(-24, 16);
        $this->promotionsSetup();
        $this->publishStorefronts();
    }

    // ------------------------------------------------------------------ compras

    /**
     * Los renglones de un articulo con subarticulos: una cantidad por variante.
     *
     * @return list<array{0: Product, 1: float, 2: int}>
     */
    protected function lines(string $family, callable $quantity): array
    {
        return $this->variants($family)
            ->map(fn (Product $variant) => [$variant, (float) $quantity($variant), (int) $variant->cost])
            ->values()
            ->all();
    }

    protected function size(Product $variant): ?string
    {
        return $variant->value1?->value;
    }

    /**
     * Una orden como la arma la pantalla de compras: el proveedor del renglon lo decide quien
     * surte el articulo, y los totales los recalcula el separador.
     *
     * @param  list<array{0: Product, 1: float, 2: int}>  $lines
     */
    protected function purchase(Store $store, string $supplierKey, array $lines, string $status, string $note): PurchaseOrder
    {
        $supplier = $this->suppliers[$supplierKey];

        return DB::transaction(function () use ($store, $supplier, $lines, $status, $note) {
            $order = PurchaseOrder::query()->create([
                'company_id' => $this->company->id,
                'store_id' => $store->id,
                'warehouse_id' => $this->warehouses->receivingDefaultFor($store->id)->id,
                'supplier_id' => $supplier->id,
                'payment_term_id' => $supplier->payment_term_id,
                'code' => $this->numbers->nextPurchaseOrderCode($this->company->id),
                'status' => $status,
                'expected_at' => now()->addDays($supplier->lead_time_days)->toDateString(),
                'subtotal' => 0,
                'tax_total' => 0,
                'total' => 0,
                'suggested_total' => 0,
                'source' => PurchaseOrder::SOURCE_MANUAL,
                'user_id' => $this->admin->id,
                'note' => $note,
            ]);

            foreach ($lines as [$product, $quantity, $cost]) {
                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $product->id,
                    'supplier_id' => $supplier->id,
                    'is_off_supplier' => false,
                    'quantity' => $quantity,
                    'suggested_quantity' => $quantity,
                    'stock_at_order' => $this->stock->availableInStore($product, $store->id),
                    'min_at_order' => (float) $product->min_stock,
                    'origin' => PurchaseOrderItem::ORIGIN_MANUAL,
                    'received_quantity' => 0,
                    'unit_cost' => $cost,
                ]);
            }

            $this->splitter->recalculate($order->refresh());

            return $order->refresh();
        });
    }

    /** Recibe una parte de lo que falta de la orden de un proveedor. */
    protected function receiveShare(string $key, float $share, ?string $note = null): void
    {
        $this->at($this->currentDay, 11);
        $this->receiveOrder($this->orders[$key], $share, note: $note);
    }

    /** Recibe completo solo lo que cumpla la condicion: lo demas sigue pendiente. */
    protected function receiveFirst(string $key, callable $filter): void
    {
        $this->at(-22, 11);
        $order = $this->orders[$key]->load('items.product');
        $this->receive($order, $order->items->filter($filter)->mapWithKeys(fn (PurchaseOrderItem $item) => [$item->id => (float) $item->quantity])->all());
    }

    protected function receiveOrder(PurchaseOrder $order, float $share, ?string $note = null): void
    {
        $order->load('items');

        $quantities = $order->items->mapWithKeys(function (PurchaseOrderItem $item) use ($share) {
            $pending = (float) $item->quantity - (float) $item->received_quantity;

            return [$item->id => $share >= 1 ? $pending : floor($pending * $share)];
        })->filter(fn ($quantity) => $quantity > 0)->all();

        $this->receive($order, $quantities);

        if ($note) {
            $order->refresh()->update(['note' => trim($order->note.'. '.$note)]);
        }
    }

    /**
     * La recepcion, paso por paso como en la pantalla: existencias y kardex por `StockService`,
     * el lote ligado al renglon, el costo promedio y lo que costo con ese proveedor.
     *
     * @param  array<int, float>  $quantities  Lo que llega ahora, por renglon.
     */
    protected function receive(PurchaseOrder $order, array $quantities): void
    {
        $warehouse = Warehouse::query()->withoutGlobalScopes()->findOrFail($order->warehouse_id);

        DB::transaction(function () use ($order, $quantities, $warehouse) {
            $order->load('items.product');

            foreach ($order->items as $item) {
                $delta = (float) ($quantities[$item->id] ?? 0);

                if ($delta <= 0 || ! $item->product) {
                    continue;
                }

                $item->update(['received_quantity' => (float) $item->received_quantity + $delta]);

                $this->stock->move(
                    product: $item->product,
                    warehouse: $warehouse,
                    type: StockMovement::TYPE_PURCHASE,
                    quantity: $delta,
                    userId: $this->warehouseKeeper->id,
                    reference: $order,
                    note: "Recepcion {$order->code}",
                    unitCost: (int) $item->unit_cost,
                );

                $this->stock->receiveLot(
                    product: $item->product,
                    warehouse: $warehouse,
                    quantity: $delta,
                    code: $item->lot_code,
                    expiresAt: null,
                    unitCost: (int) $item->unit_cost,
                    purchaseOrderItemId: $item->id,
                );

                $this->stock->recalculateAverageCost($item->product, $delta, (int) $item->unit_cost);

                ProductSupplier::query()->withoutGlobalScopes()->updateOrCreate(
                    ['product_id' => $item->product_id, 'supplier_id' => $item->supplier_id ?? $order->supplier_id],
                    ['company_id' => $order->company_id, 'last_cost' => (int) $item->unit_cost, 'last_purchased_at' => now()],
                );
            }

            $order->load('items');
            $complete = $order->pendingQuantity() <= 0;

            $order->update([
                'status' => $complete ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIAL,
                'received_at' => $complete ? now() : null,
            ]);

            $order->syncDueAt();
        });
    }

    /** A mitad de temporada los basicos se agotan primero: Textiles del Valle repone. */
    protected function midSeasonPurchase(): void
    {
        $this->at(-15, 9);
        $this->orders['valle_mid'] = $this->purchase($this->poblado, 'valle', [
            ...$this->lines('camiseta', fn (Product $v) => in_array($this->size($v), ['M', 'L'], true) ? 10 : 6),
            ...$this->lines('blusa', fn () => 4),
            ...$this->lines('medias', fn () => 30),
            ...$this->lines('conjunto', fn () => 3),
        ], PurchaseOrder::STATUS_SENT, 'Reposición de mitad de temporada');
    }

    /** Una semana despues de que el denim se movio: se pide otra vez, sobre todo lo negro. */
    protected function denimRestock(): void
    {
        $this->at(-6, 9);
        $this->orders['denim_restock'] = $this->purchase($this->poblado, 'denim', [
            ...$this->lines('jean_mujer', fn (Product $v) => $v->value2?->value === 'Negro' ? 6 : 4),
            ...$this->lines('jean_hombre', fn () => 4),
        ], PurchaseOrder::STATUS_SENT, 'Reposición de jeans');
    }

    /** Llego solo el jean de mujer: la orden queda abierta por lo que falta. */
    protected function receiveDenimPartially(): void
    {
        $this->at(-3, 15);
        $order = $this->orders['denim_restock']->load('items.product');
        $this->receive($order, $order->items
            ->filter(fn (PurchaseOrderItem $item) => $item->product->product_family_id === $this->families['jean_mujer']->id)
            ->mapWithKeys(fn (PurchaseOrderItem $item) => [$item->id => (float) $item->quantity])
            ->all());
        $order->refresh()->update(['note' => 'Reposición de jeans. Llegó el de mujer; el de hombre viene la otra semana.']);
    }

    protected function sentPurchase(): void
    {
        $this->at(-1, 17);
        $this->purchase($this->poblado, 'valle', [
            ...$this->lines('camiseta', fn (Product $v) => $this->size($v) === 'M' ? 8 : 4),
            ...$this->lines('medias', fn () => 24),
        ], PurchaseOrder::STATUS_SENT, 'Reposición semanal de básicos');
    }

    // ------------------------------------------------------------------ traslados

    /**
     * Sube al piso lo que hay en la reserva: un traslado directo dentro de la sede (despachar y
     * recibir en un acto). Deja en la reserva un colchon para Laureles.
     */
    protected function replenishFloor(Warehouse $floor, string $note): void
    {
        $this->at($this->currentDay, 17);

        $items = $this->companyProducts()
            ->map(function (Product $product) use ($floor) {
                $reserve = $this->stock->available($product, $this->reserve);
                $onFloor = $this->stock->available($product, $floor);
                $target = max(2, (int) $product->min_stock * 3);

                return ['product_id' => $product->id, 'quantity' => (float) min(floor($reserve * 0.7), max(0, $target - $onFloor))];
            })
            ->filter(fn (array $item) => $item['quantity'] > 0)
            ->values()
            ->all();

        if ($items === []) {
            return;
        }

        $transfer = $this->transfers->create($this->company->id, $this->reserve, $floor, $items, $this->warehouseKeeper, $note);
        $this->transfers->dispatchAndReceive($transfer, $this->warehouseKeeper);
    }

    /** El primer surtido de Laureles sale de la reserva: se despacha hoy y llega manana. */
    protected function transferToLaureles(): void
    {
        $this->at(-20, 15);
        $items = collect([...$this->variants('jean_mujer'), ...$this->variants('jean_hombre'), ...$this->variants('blusa'), ...$this->variants('vestido')])
            ->map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => (float) min(2, floor($this->stock->available($product, $this->reserve)))])
            ->filter(fn (array $item) => $item['quantity'] > 0)
            ->values()
            ->all();

        $transfer = $this->transfers->create($this->company->id, $this->reserve, $this->warehouses->posSourceFor($this->laureles->id), $items, $this->warehouseKeeper, 'Surtido inicial de Laureles');
        $this->transfers->dispatch($transfer, $this->warehouseKeeper);
    }

    /** A media temporada Laureles se queda corta de basicos: sale otro surtido de la reserva. */
    protected function restockLaureles(): void
    {
        $this->at(-13, 16);
        $items = collect([...$this->variants('camiseta'), ...$this->variants('conjunto'), ...$this->variants('medias'), ...$this->variants('camisa')])
            ->map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => (float) min(3, floor($this->stock->available($product, $this->reserve) * 0.5))])
            ->filter(fn (array $item) => $item['quantity'] > 0)
            ->values()
            ->all();

        if ($items === []) {
            return;
        }

        $transfer = $this->transfers->create($this->company->id, $this->reserve, $this->warehouses->posSourceFor($this->laureles->id), $items, $this->warehouseKeeper, 'Reposición de básicos para Laureles');
        $this->transfers->dispatch($transfer, $this->warehouseKeeper);
    }

    /**
     * Recibe lo que este en camino hacia Laureles. Con `short`, una blusa no aparecio en la caja:
     * entra lo demas y esa se da de baja como averia, con el numero del traslado.
     */
    protected function receiveInTransit(Warehouse $destination, bool $short = false): void
    {
        $this->at($this->currentDay, 11);

        $pending = StockTransfer::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('to_warehouse_id', $destination->id)
            ->where('status', StockTransfer::STATUS_IN_TRANSIT)
            ->with('items.product')
            ->get();

        foreach ($pending as $transfer) {
            $missing = $short ? $transfer->items->first(fn ($item) => str_contains((string) $item->product?->name, 'Blusa')) : null;

            $this->transfers->receive(
                $transfer,
                $transfer->items->mapWithKeys(fn ($item) => [$item->id => (float) $item->quantity - ($missing && $item->id === $missing->id ? 1 : 0)])->all(),
                $this->staff['cajero.laureles@modaaurora.co'],
            );
        }
    }

    /** Laureles pide tenis y camisas: pedir no mueve nada, lo mueve quien surte. */
    protected function requestFromLaureles(): void
    {
        $this->at(-10, 12);
        $items = collect([...$this->variants('tenis')->filter(fn (Product $v) => in_array($this->size($v), ['37', '38', '40'], true)), ...$this->variants('camisa')->take(4)])
            ->map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => 2.0])
            ->values()
            ->all();

        $this->transferRequests->create(
            $this->company->id,
            $this->reserve,
            $this->warehouses->posSourceFor($this->laureles->id),
            $items,
            $this->staff['cajero.laureles@modaaurora.co'],
            'Nos piden tenis en tallas medias y camisas para oficina',
        );
    }

    /** Bodega surte la solicitud con lo que alcanza: queda una parte pendiente. */
    protected function fulfillRequest(): void
    {
        $this->at(-9, 10);
        $request = StockTransferRequest::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->latest('id')
            ->with('items.product')
            ->first();

        if (! $request) {
            return;
        }

        $quantities = $request->items->mapWithKeys(fn ($item) => [
            $item->id => (float) min((float) $item->quantity, max(0, floor($this->stock->available($item->product, $this->reserve)))),
        ])->all();

        try {
            $this->transferRequests->fulfill($request, $quantities, $this->warehouseKeeper, dispatch: true);
        } catch (Throwable $exception) {
            $this->skip('surtir la solicitud', $exception);
        }
    }

    /** Hoy: un traslado en camino hacia Laureles y uno por despachar al piso. */
    protected function todayPaperwork(Warehouse $poFloor, Warehouse $lauFloor): void
    {
        $this->at(0, 9);

        $toLaureles = collect([...$this->variants('camiseta'), ...$this->variants('medias')])
            ->map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => (float) min(3, floor($this->stock->available($product, $this->reserve)))])
            ->filter(fn (array $item) => $item['quantity'] > 0)
            ->values()
            ->all();

        if ($toLaureles !== []) {
            $transfer = $this->transfers->create($this->company->id, $this->reserve, $lauFloor, $toLaureles, $this->warehouseKeeper, 'Básicos para el fin de semana');
            $this->transfers->dispatch($transfer, $this->warehouseKeeper);
        }

        $toFloor = collect([...$this->variants('vestido'), ...$this->variants('blusa')])
            ->map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => (float) min(1, floor($this->stock->available($product, $this->reserve)))])
            ->filter(fn (array $item) => $item['quantity'] > 0)
            ->values()
            ->all();

        if ($toFloor !== []) {
            $this->transfers->create($this->company->id, $this->reserve, $poFloor, $toFloor, $this->warehouseKeeper, 'Vestidos y blusas para la vitrina del sábado');
        }

        // Y una orden en borrador para la proxima semana.
        $this->purchase($this->poblado, 'andina', [
            ...$this->lines('vestido', fn () => 3),
            [$this->singles['gorra'], 12, 14000],
        ], PurchaseOrder::STATUS_DRAFT, 'Para revisar con Felipe el jueves');
    }

    /**
     * Dos jeans aparecieron en el piso con la costura abierta: vuelven al proveedor, ligados a la
     * orden con que entraron, y el proveedor responde con nota credito.
     */
    protected function supplierReturn(): void
    {
        $this->at(-8, 14);
        $order = $this->orders['denim']->refresh()->load('items.product');
        $warehouse = $this->warehouses->posSourceFor($this->poblado->id);

        $lines = $order->items
            ->filter(fn (PurchaseOrderItem $item) => $item->product && $this->stock->available($item->product, $warehouse) >= 2)
            ->take(2)
            ->map(fn (PurchaseOrderItem $item) => ['purchase_order_item_id' => $item->id, 'quantity' => 1, 'reason' => 'quality'])
            ->values()
            ->all();

        if ($lines === []) {
            return;
        }

        try {
            $return = $this->supplierReturns->create([
                'company_id' => $this->company->id,
                'store_id' => $order->store_id,
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'shipping_reference' => 'Coordinadora 4455667788',
                'note' => 'Costura de la entrepierna abierta en dos unidades.',
                'items' => $lines,
            ], $this->warehouseKeeper);

            $this->supplierReturns->dispatch($return, $this->warehouseKeeper);

            $this->at(-5, 10);
            $this->supplierReturns->settle($return->refresh(), [
                'type' => SupplierReturnSettlement::TYPE_CREDIT_NOTE,
                'document_number' => 'NC-'.random_int(1000, 9999),
                'amount' => (int) $return->total,
                'received_at' => now(),
            ], $this->admin);
        } catch (Throwable $exception) {
            $this->skip('la devolución a proveedor', $exception);
        }
    }

    /** @return Collection<int, Product> */
    protected function companyProducts(): Collection
    {
        return Product::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_active', true)->orderBy('id')->get();
    }

    // ------------------------------------------------------------------ promociones y tienda

    protected function promotionsSetup(): void
    {
        $denim = $this->promotion('Temporada denim', Promotion::TYPE_PERCENT_OFF, ['percent' => 20], -10, 5, '-20%');
        $this->target($denim, PromotionTarget::TYPE_FAMILY, $this->families['jean_mujer']->id);
        $this->target($denim, PromotionTarget::TYPE_FAMILY, $this->families['jean_hombre']->id);

        // Lleva 3 paga 2 en medias: las de un mismo articulo se juntan aunque sean de colores distintos.
        $socks = $this->promotion('Medias: lleva 3, paga 2', Promotion::TYPE_N_FOR_M, ['buy' => 3, 'pay' => 2], -24, 30, '3x2');
        $this->target($socks, PromotionTarget::TYPE_FAMILY, $this->families['medias']->id);

        $cap = $this->promotion('Gorra a precio especial', Promotion::TYPE_SPECIAL_PRICE, [], -3, 4, 'Precio especial', priority: 5);
        $this->target($cap, PromotionTarget::TYPE_PRODUCT, $this->singles['gorra']->id, 32900);
    }

    /** @param  array<string, mixed>  $config */
    protected function promotion(string $name, string $type, array $config, int $fromDay, int $toDay, ?string $label, int $priority = 1): Promotion
    {
        return Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => $name,
            'type' => $type,
            'label' => $label,
            'starts_at' => $this->today->addDays($fromDay),
            'ends_at' => $this->today->addDays($toDay)->endOfDay(),
            'applies_online' => true,
            'applies_pos' => true,
            'priority' => $priority,
            'config' => $config,
            'is_active' => true,
        ]);
    }

    protected function target(Promotion $promotion, string $type, int $id, ?int $value = null): void
    {
        PromotionTarget::query()->create(['promotion_id' => $promotion->id, 'target_type' => $type, 'target_id' => $id, 'value' => $value, 'quantity' => 1]);
    }

    /** La tienda en linea de cada sede, con portada propia de ropa (la plantilla es de mercado). */
    protected function publishStorefronts(): void
    {
        $media = [
            'hero' => $this->banners->paint('Colección de temporada de Moda Aurora', 1600, 600, ['#4C1D95', '#BE185D'], ['#F9A8D4', '#FDE68A', '#C4B5FD', '#FFFFFF'], $this->company->id, 'storefront', 'garments')?->id,
            'hero_mobile' => $this->banners->paint('Colección de temporada de Moda Aurora', 800, 1000, ['#4C1D95', '#BE185D'], ['#F9A8D4', '#FDE68A', '#C4B5FD'], $this->company->id, 'storefront', 'garments')?->id,
            'denim' => $this->banners->paint('Temporada denim con 20 % de descuento', 1600, 600, ['#1B2A4A', '#3B6FB6'], ['#7FA7D9', '#FFFFFF', '#2D4A7A'], $this->company->id, 'storefront', 'garments')?->id,
        ];

        foreach ([$this->poblado, $this->laureles] as $store) {
            $storefront = $this->storefronts->for($store);

            if ($storefront->published_version_id !== null) {
                continue;
            }

            $storefront->zones()->create(['name' => 'Alrededores de '.$store->name, 'fee' => 8000, 'eta_minutes' => 60, 'is_active' => true, 'sort_order' => 0]);
            $storefront->settings()->updateOrCreate(['key' => 'delivery'], ['value' => array_merge(
                $this->storefronts->defaultSettings()['delivery'],
                ['fee' => 8000, 'free_from' => 150000, 'min_order_total' => 40000, 'pickup' => true],
            )]);

            // Laureles es la sede chica y no maneja todo el surtido: su tienda solo muestra lo que
            // tiene. El Poblado deja ver lo agotado, con su aviso.
            if ($store->is($this->laureles)) {
                $storefront->settings()->updateOrCreate(['key' => 'catalog'], ['value' => array_merge(
                    $this->storefronts->defaultSettings()['catalog'],
                    ['show_out_of_stock' => false],
                )]);
            }

            if ($storefront->follows_template) {
                $this->templates->detach($storefront, $this->admin);
                $storefront->refresh();
            }

            $storefront->theme()->update(['display_name' => 'Moda Aurora', 'tagline' => 'Moda para todos los días', 'accent' => '#9D174D']);
            $storefront->blocks()->where('page', StorefrontBlock::PAGE_HOME)->delete();

            foreach ($this->homeBlocks($store, $media) as $order => [$type, $payload]) {
                $definition = BlockCatalogue::for($type);

                StorefrontBlock::create([
                    'storefront_id' => $storefront->id,
                    'uuid' => (string) Str::uuid(),
                    'type' => $type,
                    'page' => StorefrontBlock::PAGE_HOME,
                    'sort_order' => $order,
                    'is_visible' => true,
                    'devices' => StorefrontBlock::DEVICE_ALL,
                    'payload' => $definition->normalize(array_merge($definition->defaults(), $payload)),
                ]);
            }

            $this->publisher->publish($storefront->refresh(), $this->admin, 'Tienda de temporada');
            $storefront->forceFill(['is_online' => true])->save();
        }
    }

    /**
     * @param  array<string, int|null>  $media
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    protected function homeBlocks(Store $store, array $media): array
    {
        $category = fn (string $key) => ['kind' => 'category', 'value' => (string) $this->categories[$key]->id];

        return [
            ['announcement', ['text' => 'Envío gratis desde $ 150.000 · Cambios de talla en cualquier sede', 'tone' => 'accent']],
            ['hero', [
                'layout' => 'full',
                'height' => 'medium',
                'autoplay' => 6,
                'slides' => array_values(array_filter([
                    $media['hero'] ? [
                        'media_id' => $media['hero'], 'media_mobile_id' => $media['hero_mobile'],
                        'title' => 'Nueva temporada', 'text' => 'Jeans, blusas y vestidos para todos los días.',
                        'button_label' => 'Ver mujer', 'target' => $category('mujer'), 'text_color' => 'light', 'overlay' => 15,
                    ] : null,
                    $media['denim'] ? [
                        'media_id' => $media['denim'], 'media_mobile_id' => null,
                        'title' => 'Temporada denim -20 %', 'text' => 'Todos los jeans de mujer y de hombre.',
                        'button_label' => 'Ver jeans', 'target' => $category('jeans'), 'text_color' => 'light', 'overlay' => 15,
                    ] : null,
                ])),
            ]],
            ['categories', ['title' => 'Compra por categoría', 'style' => 'icon_circles', 'source' => 'all', 'limit' => 12]],
            ['product_grid', ['title' => 'En promoción', 'source' => 'on_sale', 'layout' => 'carousel', 'limit' => 12, 'background' => 'soft']],
            ['product_grid', ['title' => 'Jeans', 'source' => 'category', 'value' => (string) $this->categories['jeans']->id, 'layout' => 'carousel', 'limit' => 10]],
            ['product_grid', ['title' => 'Para ella', 'source' => 'category', 'value' => (string) $this->categories['mujer']->id, 'layout' => 'carousel', 'limit' => 10]],
            ['product_grid', ['title' => 'Para él', 'source' => 'category', 'value' => (string) $this->categories['hombre']->id, 'layout' => 'carousel', 'limit' => 10]],
            ['product_grid', ['title' => 'Calzado y accesorios', 'source' => 'category', 'value' => (string) $this->categories['calzado']->id, 'layout' => 'carousel', 'limit' => 10]],
            ['benefits', ['items' => [
                ['icon' => 'Truck', 'title' => 'Envío a tu casa', 'text' => 'En Medellín, el mismo día.'],
                ['icon' => 'ShieldCheck', 'title' => 'Cambios de talla', 'text' => 'Hasta 30 días, en cualquier sede.'],
                ['icon' => 'CreditCard', 'title' => 'Paga como quieras', 'text' => 'Tarjeta, transferencia o contraentrega.'],
            ]]],
            ['faq', [
                'title' => 'Preguntas frecuentes',
                'items' => [
                    ['question' => '¿Puedo cambiar la talla?', 'answer' => 'Sí: en cualquier sede o desde «Mis pedidos», hasta 30 días después de la compra, con la prenda sin usar.'],
                    ['question' => '¿Cuánto cuesta el envío?', 'answer' => 'Depende de tu zona y lo ves antes de confirmar. Desde $ 150.000 es gratis.'],
                    ['question' => '¿Cómo sé mi talla?', 'answer' => 'En cada prenda verás las tallas disponibles; si dudas, escríbenos y te ayudamos.'],
                ],
            ]],
            ['store_info', ['title' => 'Visítanos en '.$store->name]],
        ];
    }

    // ------------------------------------------------------------------ ventas

    /**
     * Un dia de caja en las dos sedes. Los tiquetes de las dos van en el orden en que pasaron:
     * las dos cajas se llaman C1 y comparten consecutivo, que asi sale en orden de hora. Las
     * devoluciones y los cambios pasan por la caja de El Poblado a media tarde.
     */
    protected function salesDay(int $day): void
    {
        $weekend = in_array($this->today->addDays($day)->dayOfWeek, [0, 6], true);
        $lastHour = $day === 0 ? min(19, (int) $this->realNow->format('G') - 1) : 19;

        if ($lastHour >= 10) {
            $shifts = [
                ['store' => $this->poblado, 'cashier' => $this->staff['cajera@modaaurora.co'], 'tickets' => $weekend ? random_int(8, 12) : random_int(4, 8)],
                ['store' => $this->laureles, 'cashier' => $this->staff['cajero.laureles@modaaurora.co'], 'tickets' => $weekend ? random_int(5, 8) : random_int(2, 5)],
            ];

            foreach ($shifts as $index => $shift) {
                $register = Register::query()->withoutGlobalScopes()->where('store_id', $shift['store']->id)->orderBy('id')->firstOrFail();
                $openedAt = $this->at($day, 9, 40);
                $shifts[$index]['session'] = $this->registerSessions->open($register, $shift['cashier'], (int) $shift['store']->suggested_cash_base, ['50000' => 2, '20000' => 3, '10000' => 3, '5000' => 2], 'Base verificada', $openedAt);
            }

            $events = collect();

            foreach ($shifts as $shift) {
                foreach (range(1, $shift['tickets']) as $ignored) {
                    $events->push([random_int(10, $lastHour) * 60 + random_int(0, 59), fn () => $this->ticket($shift['store'], $shift['cashier'], $shift['session'], $day)]);
                }
            }

            if ($day === -5) {
                $events->push([16 * 60, fn () => $this->sizeReturn($shifts[0]['session'])]);
            }

            if ($day === -3) {
                $events->push([17 * 60, fn () => $this->sizeExchange($shifts[0]['session'], $shifts[0]['cashier'])]);
            }

            foreach ($events->sortBy(fn (array $event) => $event[0])->values() as [$minuteOfDay, $event]) {
                $this->at($day, intdiv($minuteOfDay, 60), $minuteOfDay % 60);
                $event();
            }

            foreach ($shifts as $index => $shift) {
                // El turno de hoy en El Poblado sigue abierto: asi se ve una caja en uso.
                if ($day === 0 && $index === 0) {
                    continue;
                }

                $this->at($day, 20, 10);
                $expected = $this->registerSessions->expectedAmount($shift['session']->refresh());
                $difference = $day % 9 === 0 && $index === 0 ? -2000 : 0;
                $this->registerSessions->close($shift['session'], $expected + $difference, [], $difference !== 0 ? 'Faltaron dos mil en el conteo' : null);
            }
        }

        match ($day) {
            -6, -4, -2, 0 => $this->onlineOrders($day),
            default => null,
        };
    }
    /** Un tiquete: una a tres prendas de lo que hay en el piso, a veces con cliente. */
    protected function ticket(Store $store, User $cashier, RegisterSession $session, int $day): void
    {
        $floor = $this->warehouses->posSourceFor($store->id);
        $weights = ['camiseta' => 5, 'jean_mujer' => 4, 'jean_hombre' => 3, 'medias' => 4, 'blusa' => 3, 'vestido' => 1, 'camisa' => 2, 'conjunto' => 2, 'tenis' => 1];
        $pool = collect($weights)->flatMap(fn (int $weight, string $key) => array_fill(0, $weight, $key));
        $items = [];

        foreach (range(1, random_int(1, 3)) as $ignored) {
            $candidates = random_int(1, 10) <= 2
                ? collect($this->singles)->values()
                : $this->variants($pool->random());
            $product = $candidates->shuffle()->first(fn (Product $p) => $this->stock->available($p, $floor) >= 1 && ! isset($items[$p->id]));

            if ($product) {
                // Las medias se llevan de a tres: asi se ve el 3x2.
                $quantity = $product->product_family_id === $this->families['medias']->id && random_int(1, 2) === 1 ? 3 : 1;
                $items[$product->id] = ['product_id' => $product->id, 'quantity' => (float) min($quantity, floor($this->stock->available($product, $floor)))];
            }
        }

        if ($items === []) {
            return;
        }

        $credit = $store->is($this->poblado) && random_int(1, 40) === 1;
        $customer = $credit
            ? $this->customers->firstWhere('credit_enabled', true)
            : (random_int(1, 100) <= 40 ? $this->customers->where('credit_enabled', false)->random() : null);

        $total = $this->quote(array_values($items));
        $method = $credit ? SalePayment::METHOD_CREDIT : $this->paymentMethod();
        $payment = match ($method) {
            SalePayment::METHOD_CASH => [
                'method' => $method,
                'amount' => $this->sales->roundedTotal($total, $this->sales->roundingStep($this->company->id)),
                'received_amount' => (int) (ceil($total / 10000) * 10000),
            ],
            default => ['method' => $method, 'amount' => $total],
        };

        try {
            $sale = $this->sales->create([
                'items' => array_values($items),
                'customer_id' => $customer?->id,
                'channel' => Sale::CHANNEL_POS,
                'payments' => [$payment],
            ], $credit ? $this->admin : $cashier, $store, $session);

            if ($customer && ! $credit && $store->is($this->poblado)) {
                $this->returnable[] = $sale;
            }
        } catch (Throwable $exception) {
            $this->skip('un tiquete del día '.$day, $exception);
        }
    }

    /**
     * El total que va a cobrar el servidor, con las mismas promociones de la caja.
     *
     * @param  list<array{product_id: int, quantity: float}>  $items
     */
    protected function quote(array $items): int
    {
        $result = $this->promotions->apply($items, $this->company->id, PromotionService::CHANNEL_POS);

        return (int) collect($result['lines'])->sum(fn (array $line) => (int) round((float) $line['quantity'] * (int) $line['unit_price']) - (int) ($line['discount'] ?? 0));
    }

    protected function paymentMethod(): string
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 40 => SalePayment::METHOD_CASH,
            $roll <= 85 => SalePayment::METHOD_CARD,
            default => SalePayment::METHOD_TRANSFER,
        };
    }

    // ------------------------------------------------------------------ devoluciones y cambios

    /** Un jean que no le quedo: vuelve al inventario y la clienta queda con saldo a favor. */
    protected function sizeReturn(RegisterSession $session): void
    {
        [$sale, $line] = $this->findReturnableLine(fn (Product $p) => in_array($p->product_family_id, [$this->families['jean_mujer']->id, $this->families['jean_hombre']->id, $this->families['blusa']->id], true));

        if (! $sale) {
            return;
        }

        try {
            $this->customerReturns->registerAtPos($sale, [
                'items' => [['sale_item_id' => $line->id, 'quantity' => 1, 'reason' => 'wrong_size']],
                'resolution' => CustomerReturn::RESOLUTION_STORE_CREDIT,
                'reason' => 'La talla le quedó grande; prefiere elegir con calma',
            ], $this->staff['supervisor@modaaurora.co'], $this->poblado, $session);
        } catch (Throwable $exception) {
            $this->skip('la devolución por talla', $exception);
        }
    }

    /**
     * El cambio de talla en caja: devuelve la camiseta M y se lleva la L del mismo color. El valor
     * devuelto paga la prenda nueva.
     */
    protected function sizeExchange(RegisterSession $session, User $cashier): void
    {
        [$sale, $line] = $this->findReturnableLine(fn (Product $p) => $p->product_family_id === $this->families['camiseta']->id || $p->product_family_id === $this->families['camisa']->id);

        if (! $sale) {
            return;
        }

        $returned = $line->product;
        $floor = $this->warehouses->posSourceFor($this->poblado->id);
        $replacement = $returned->family->variants()
            ->where('variant_value_2_id', $returned->variant_value_2_id)
            ->where('id', '!=', $returned->id)
            ->get()
            ->first(fn (Product $p) => $this->stock->available($p, $floor) >= 1);

        if (! $replacement) {
            return;
        }

        $value = (int) round((int) $line->line_total / max(1, (float) $line->quantity));
        $newTotal = $this->quote([['product_id' => $replacement->id, 'quantity' => 1.0]]);
        $applied = min($value, $newTotal);
        $payments = [['method' => SalePayment::METHOD_EXCHANGE, 'amount' => $applied]];

        if ($newTotal > $applied) {
            $payments[] = ['method' => SalePayment::METHOD_CARD, 'amount' => $newTotal - $applied];
        }

        try {
            $this->customerReturns->exchangeAtPos($sale, [
                'items' => [['sale_item_id' => $line->id, 'quantity' => 1, 'reason' => 'wrong_size']],
                'reason' => 'Cambio de talla',
                'leftover_method' => SalePayment::METHOD_STORE_CREDIT,
            ], [
                'items' => [['product_id' => $replacement->id, 'quantity' => 1]],
                'channel' => Sale::CHANNEL_POS,
                'payments' => $payments,
            ], $cashier, $this->poblado, $session);
        } catch (Throwable $exception) {
            $this->skip('el cambio de talla', $exception);
        }
    }

    /**
     * Una venta con cliente y una linea de prenda que cumpla la condicion, sin devolver aun.
     *
     * @return array{0: ?Sale, 1: ?\App\Models\SaleItem}
     */
    protected function findReturnableLine(callable $filter): array
    {
        foreach (array_reverse($this->returnable) as $index => $sale) {
            $line = $sale->items()->whereNotNull('product_id')->whereNull('parent_sale_item_id')->with('product.family')->get()
                ->first(fn ($item) => $item->product && $filter($item->product) && (float) $item->quantity >= 1);

            if ($line) {
                $this->returnable = array_values(array_filter($this->returnable, fn (Sale $other) => $other->id !== $sale->id));

                return [$sale, $line];
            }
        }

        return [null, null];
    }

    // ------------------------------------------------------------------ pedidos en linea

    /** Pedidos de la tienda en linea de El Poblado, en distintos estados segun el dia. */
    protected function onlineOrders(int $day): void
    {
        $plans = match ($day) {
            -6 => [Order::STATUS_DELIVERED, Order::STATUS_DELIVERED],
            -4 => [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED],
            -2 => [Order::STATUS_DELIVERED],
            default => [Order::STATUS_ON_ROUTE, Order::STATUS_PICKING, Order::STATUS_NEW],
        };

        $online = $this->customers->where('is_online_user', true)->values();
        $source = $this->warehouses->onlineSourceFor($this->poblado->id);
        $courier = $this->staff['domicilios@modaaurora.co'];

        foreach ($plans as $index => $status) {
            $this->at($day, 11 + $index * 2);
            $customer = $online[($day * -1 + $index) % $online->count()];
            $items = collect([...$this->variants('blusa'), ...$this->variants('jean_mujer'), ...$this->variants('medias'), ...$this->variants('vestido'), ...$this->variants('camiseta'), ...$this->variants('camisa')])
                ->shuffle()
                ->filter(fn (Product $p) => $this->stock->available($p, $source) >= 2)
                ->take(random_int(2, 3))
                ->map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => 1])
                ->values()
                ->all();

            if ($items === []) {
                continue;
            }

            try {
                $start = now()->addHours(3)->startOfHour();
                $order = $this->orderService->create([
                    'customer_id' => $customer->id,
                    'items' => $items,
                    'channel' => Order::CHANNEL_APP,
                    'payment_method' => $index % 2 === 0 ? Order::PAYMENT_CASH_ON_DELIVERY : Order::PAYMENT_TRANSFER,
                    'delivery_address' => $customer->address.', '.$customer->neighborhood,
                    'delivery_notes' => 'Portería del edificio',
                    'delivery_slot_start' => $start->toDateTimeString(),
                    'delivery_slot_end' => $start->addHours(2)->toDateTimeString(),
                    'delivery_fee' => $this->storefrontConfig->deliveryFee($this->poblado, 0),
                ], $this->poblado, $this->admin);

                $this->advanceOrder($order, $status, $courier, $day);
            } catch (Throwable $exception) {
                $this->skip('un pedido en línea', $exception);
            }
        }

        // Del pedido entregado mas reciente, la clienta pide cambiar un jean por talla.
        if ($day === -2) {
            $this->onlineReturnRequest();
        }
    }

    protected function advanceOrder(Order $order, string $target, User $courier, int $day): void
    {
        if ($target === Order::STATUS_NEW) {
            return;
        }

        if ($target === Order::STATUS_CANCELLED) {
            $this->orderService->changeStatus($order, Order::STATUS_CANCELLED, $this->admin, 'La clienta prefirió recoger en la tienda');

            return;
        }

        foreach ([Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED] as $step => $status) {
            $this->at($day, min(19, (int) now()->format('G') + 1));

            if ($status === Order::STATUS_PICKING) {
                $order->loadMissing('items');
                $this->orderService->pick($order, $order->items->map(fn ($item) => ['id' => $item->id, 'picked_quantity' => (float) $item->quantity])->all(), $this->warehouseKeeper);
                $order->refresh();
            }

            if ($status === Order::STATUS_ON_ROUTE) {
                $this->orderService->assignCourier($order->refresh(), $courier, $this->admin);
            }

            if ($order->refresh()->status !== $status) {
                $order = $this->orderService->changeStatus($order, $status, $this->admin);
            }

            if ($status === $target) {
                break;
            }
        }

        if ($target === Order::STATUS_DELIVERED && $order->refresh()->payment_status !== 'paid') {
            $this->orderService->collect($order, $courier, [
                ['method' => $order->payment_method === Order::PAYMENT_TRANSFER ? SalePayment::METHOD_TRANSFER : SalePayment::METHOD_CASH, 'amount' => (int) ($order->sale?->total ?? $order->total)],
            ]);
        }
    }

    protected function onlineReturnRequest(): void
    {
        $order = Order::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('status', Order::STATUS_DELIVERED)
            ->whereNotNull('sale_id')
            ->latest('id')
            ->first();
        $sale = $order ? Sale::query()->withoutGlobalScopes()->find($order->sale_id) : null;
        $line = $sale?->items()->whereNotNull('product_id')->whereNull('parent_sale_item_id')->orderBy('id')->first();

        if (! $line || ! $order->customer) {
            return;
        }

        try {
            $this->at(-2, 19, 30);
            $this->customerReturns->requestOnline($order, $order->customer, [
                'items' => [['sale_item_id' => $line->id, 'quantity' => 1, 'reason' => 'wrong_size']],
                'note' => 'Me quedó pequeño; quisiera la talla siguiente.',
            ]);
        } catch (Throwable $exception) {
            $this->skip('la solicitud de devolución en línea', $exception);
        }
    }

    protected function skip(string $what, Throwable $exception): void
    {
        $this->skipped[] = $what.': '.Str::limit($exception->getMessage(), 160);
    }
}
