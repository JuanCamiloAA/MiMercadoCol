<?php

use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\CashDifferenceController;
use App\Http\Controllers\CatalogImportController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\CustomerReturnController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeAccessController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\Landing\LandingController;
use App\Http\Controllers\Landing\SignupController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentTermController;
use App\Http\Controllers\Platform\ActiveCompanyController;
use App\Http\Controllers\Platform\BillingCycleController;
use App\Http\Controllers\Platform\BusinessCategoryController;
use App\Http\Controllers\Platform\CompanyController;
use App\Http\Controllers\Platform\CompanyEntitlementController;
use App\Http\Controllers\Platform\CompanyGatewayProvidersController;
use App\Http\Controllers\Payments\GatewayAccountController;
use App\Http\Controllers\Payments\PaymentMethodController;
use App\Http\Controllers\Payments\TerminalController;
use App\Http\Controllers\Payments\TransactionController;
use App\Http\Controllers\Pos\GatewayPaymentController as PosGatewayPaymentController;
use App\Http\Controllers\PublicPaymentController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Http\Controllers\Platform\LandingAdminController;
use App\Http\Controllers\Platform\LeadController;
use App\Http\Controllers\Platform\MapSettingsController;
use App\Http\Controllers\Platform\MembershipPlanController;
use App\Http\Controllers\Platform\PaymentGatewayController;
use App\Http\Controllers\Platform\PlatformCompanyUserController;
use App\Http\Controllers\Platform\PlatformImportController;
use App\Http\Controllers\Platform\PlatformMediaController;
use App\Http\Controllers\Platform\PlatformUserController;
use App\Http\Controllers\Platform\StoreTemplateController;
use App\Http\Controllers\Platform\StoreTemplateDraftController;
use App\Http\Controllers\Pos\AuthorizationController as PosAuthorizationController;
use App\Http\Controllers\Pos\HeldSaleController;
use App\Http\Controllers\Pos\ReturnController as PosReturnController;
use App\Http\Controllers\Pos\SaleController as PosSaleController;
use App\Http\Controllers\ProductAttributeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductFamilyController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\Registers\CashMovementController;
use App\Http\Controllers\Registers\ClosingController;
use App\Http\Controllers\Registers\OpeningController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\Settings\AppearanceSettingController;
use App\Http\Controllers\Settings\BrandImageController;
use App\Http\Controllers\Settings\BrandSettingController;
use App\Http\Controllers\Settings\CompanySettingController;
use App\Http\Controllers\Settings\CatalogSettingController;
use App\Http\Controllers\Settings\CreditSettingController;
use App\Http\Controllers\Settings\MembershipSettingController;
use App\Http\Controllers\Settings\NavigationSettingController;
use App\Http\Controllers\Settings\PosRulesSettingController;
use App\Http\Controllers\Settings\PosSettingController;
use App\Http\Controllers\Settings\ReceiptSettingController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StockTransferRequestController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\Storefront\BlockController as EditorBlockController;
use App\Http\Controllers\Storefront\CopyController as EditorCopyController;
use App\Http\Controllers\Storefront\DeliverySlotController;
use App\Http\Controllers\Storefront\DeliveryZoneController;
use App\Http\Controllers\Storefront\EditorController;
use App\Http\Controllers\Storefront\PublishController;
use App\Http\Controllers\Storefront\SettingController as EditorSettingController;
use App\Http\Controllers\Storefront\TemplateLinkController;
use App\Http\Controllers\Storefront\ThemeController;
use App\Http\Controllers\Storefront\VersionController;
use App\Http\Controllers\StorefrontCatalogManagerController;
use App\Http\Controllers\StorefrontNewsletterController;
use App\Http\Controllers\StorefrontOutOfStockController;
use App\Http\Controllers\StorefrontSuggestionController;
use App\Http\Controllers\StoreSwitchController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierReturnController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\Webhooks\WompiWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Paginas publicas de la plataforma: la landing, el buscador de sedes cercanas, los textos
 * legales y la contratacion. Sin `guest`: con sesion de personal se ven igual (y la vuelta del
 * checkout tiene que funcionar con o sin sesion).
 */
Route::get('/', [LandingController::class, 'show'])->name('home');
Route::post('sedes-cercanas', [LandingController::class, 'nearby'])->middleware('throttle:30,1')->name('landing.nearby');
Route::get('terminos', [LandingController::class, 'terms'])->name('legal.terms');
Route::get('privacidad', [LandingController::class, 'privacy'])->name('legal.privacy');

Route::get('contratar', [SignupController::class, 'create'])->name('signup.create');
Route::post('contratar', [SignupController::class, 'store'])->middleware('throttle:10,1')->name('signup.store');
Route::post('contratar/solicitud', [SignupController::class, 'contact'])->middleware('throttle:10,1')->name('signup.contact');
Route::get('contratar/estado/{reference}', [SignupController::class, 'status'])->middleware('throttle:60,1')->where('reference', 'SIG-[A-Z0-9]+')->name('signup.status');

Route::middleware(['auth', 'verified.company'])->group(function () {
    Route::get('cambiar-clave', [PasswordChangeController::class, 'show'])->name('password.change.show');
    Route::put('cambiar-clave', [PasswordChangeController::class, 'update'])->name('password.change.update');
});

Route::middleware(['auth', 'verified.company', 'force.password.change'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware(['permission:dashboard.index.view', 'membership.active'])
        ->name('dashboard');

    Route::put('sede-activa', [StoreSwitchController::class, 'update'])->name('stores.switch');

    // Latido que mantiene viva la sesion del punto de venta durante todo el turno.
    Route::get('sesion-activa', fn () => response()->noContent())->name('session.heartbeat');

    /*
     * Pantallas de una empresa. El super usuario en «Todas las empresas» no tiene ninguna
     * elegida: `company.selected` le pide que elija y lo devuelve a donde iba. `membership.active`
     * deja pasar solo lo necesario para pagar cuando la membresia esta suspendida. Lo que incluye
     * el plan no lleva middleware propio: `permission:` ya responde con el techo del plan
     * (`EffectivePermissionService`).
     */
    Route::middleware(['company.selected', 'membership.active'])->group(function () {
        // Roles
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.index.view')->name('roles.index');
        Route::get('roles/crear', [RoleController::class, 'create'])->middleware('permission:roles.index.create')->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.index.create')->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.index.view')->name('roles.show');
        Route::get('roles/{role}/editar', [RoleController::class, 'edit'])->middleware('permission:roles.index.edit')->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.index.edit')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.index.delete')->name('roles.destroy');
        Route::get('roles/{role}/propagar', [RoleController::class, 'pending'])->middleware('permission:roles.edit.propagate')->name('roles.propagate.show');
        Route::post('roles/{role}/propagar', [RoleController::class, 'propagate'])->middleware('permission:roles.edit.propagate')->name('roles.propagate');

        // Catalogo
        Route::get('productos', [ProductController::class, 'index'])->middleware('permission:products.index.view')->name('products.index');
        Route::get('productos/importar', [ImportController::class, 'form'])->defaults('module', 'products')->middleware('permission:products.index.import')->name('products.import.form');
        Route::post('productos/importar', [ImportController::class, 'store'])->defaults('module', 'products')->middleware('permission:products.index.import')->name('products.import');
        Route::get('productos/exportar', [ProductController::class, 'export'])->middleware('permission:products.index.export')->name('products.export');
        Route::post('productos/etiquetas', [ProductController::class, 'labels'])->middleware('permission:products.index.print_labels')->name('products.labels');
        Route::post('productos/lote', [ProductController::class, 'bulk'])->middleware('permission:products.index.edit')->name('products.bulk');
        Route::get('productos/crear', [ProductController::class, 'create'])->middleware('permission:products.index.create')->name('products.create');
        Route::post('productos', [ProductController::class, 'store'])->middleware('permission:products.index.create')->name('products.store');

        // Articulos con subarticulos. Antes de las que llevan `{product}`, o `{product}` se traga
        // «familia» y «agrupar». Crearlos y editarlos usa los permisos de articulos de siempre.
        Route::get('productos/familia/crear', [ProductFamilyController::class, 'create'])->middleware('permission:products.index.create')->name('products.families.create');
        Route::post('productos/familia', [ProductFamilyController::class, 'store'])->middleware('permission:products.index.create')->name('products.families.store');
        Route::get('productos/familia/{family}', [ProductFamilyController::class, 'show'])->whereNumber('family')->middleware('permission:products.show.view')->name('products.families.show');
        Route::get('productos/familia/{family}/editar', [ProductFamilyController::class, 'edit'])->whereNumber('family')->middleware('permission:products.index.edit')->name('products.families.edit');
        Route::put('productos/familia/{family}', [ProductFamilyController::class, 'update'])->whereNumber('family')->middleware('permission:products.index.edit')->name('products.families.update');
        Route::post('productos/familia/{family}/deshacer', [ProductFamilyController::class, 'ungroup'])->whereNumber('family')->middleware('permission:products.index.edit')->name('products.families.ungroup');
        Route::delete('productos/familia/{family}/fotos/{image}', [ProductFamilyController::class, 'destroyImage'])->whereNumber('family')->middleware('permission:products.index.edit')->name('products.families.images.destroy');
        Route::patch('productos/familia/{family}/fotos/{image}/portada', [ProductFamilyController::class, 'coverImage'])->whereNumber('family')->middleware('permission:products.index.edit')->name('products.families.images.cover');
        Route::get('productos/agrupar', [ProductFamilyController::class, 'groupForm'])->middleware('permission:products.index.edit')->name('products.families.group.form');
        Route::post('productos/agrupar', [ProductFamilyController::class, 'group'])->middleware('permission:products.index.edit')->name('products.families.group');
        Route::get('productos/{product}', [ProductController::class, 'show'])->middleware('permission:products.show.view')->name('products.show');
        Route::get('productos/{product}/editar', [ProductController::class, 'edit'])->middleware('permission:products.index.edit')->name('products.edit');
        Route::put('productos/{product}', [ProductController::class, 'update'])->middleware('permission:products.index.edit')->name('products.update');
        Route::delete('productos/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.index.delete')->name('products.destroy');
        Route::post('productos/{product}/precio', [ProductController::class, 'updatePrice'])->middleware('permission:products.show.edit_price')->name('products.price');
        Route::delete('productos/{product}/imagenes/{image}', [ProductController::class, 'destroyImage'])->middleware('permission:products.index.edit')->name('products.images.destroy');
        Route::patch('productos/{product}/imagenes/{image}/portada', [ProductController::class, 'coverImage'])->middleware('permission:products.index.edit')->name('products.images.cover');
        Route::get('productos/{product}/convertir', [ProductFamilyController::class, 'convertForm'])->middleware('permission:products.index.edit')->name('products.convert.form');
        Route::post('productos/{product}/convertir', [ProductFamilyController::class, 'convert'])->middleware('permission:products.index.edit')->name('products.convert');
        Route::post('productos/{product}/separar', [ProductFamilyController::class, 'detach'])->middleware('permission:products.index.edit')->name('products.detach');

        // Atributos: el catalogo de caracteristicas con que se arman los subarticulos.
        Route::get('atributos', [ProductAttributeController::class, 'index'])->middleware('permission:products.attributes.view')->name('attributes.index');
        Route::post('atributos', [ProductAttributeController::class, 'store'])->middleware('permission:products.attributes.manage')->name('attributes.store');
        Route::put('atributos/valores/{value}', [ProductAttributeController::class, 'updateValue'])->middleware('permission:products.attributes.manage')->name('attributes.values.update');
        Route::delete('atributos/valores/{value}', [ProductAttributeController::class, 'destroyValue'])->middleware('permission:products.attributes.manage')->name('attributes.values.destroy');
        Route::put('atributos/{attribute}', [ProductAttributeController::class, 'update'])->middleware('permission:products.attributes.manage')->name('attributes.update');
        Route::delete('atributos/{attribute}', [ProductAttributeController::class, 'destroy'])->middleware('permission:products.attributes.manage')->name('attributes.destroy');
        Route::post('atributos/{attribute}/valores', [ProductAttributeController::class, 'storeValue'])->middleware('permission:products.attributes.manage')->name('attributes.values.store');
        Route::post('atributos/{attribute}/orden', [ProductAttributeController::class, 'reorderValues'])->middleware('permission:products.attributes.manage')->name('attributes.values.reorder');

        // Servicios. Las fijas antes de las que llevan `{service}`, o `{service}` se traga
        // «crear», «exportar», «importar» y «articulos».
        Route::get('servicios', [ServiceController::class, 'index'])->middleware('permission:services.index.view')->name('services.index');
        Route::get('servicios/exportar', [ServiceController::class, 'export'])->middleware('permission:services.index.export')->name('services.export');
        Route::get('servicios/importar', [ImportController::class, 'form'])->defaults('module', 'services')->middleware('permission:services.index.import')->name('services.import.form');
        Route::post('servicios/importar', [ImportController::class, 'store'])->defaults('module', 'services')->middleware('permission:services.index.import')->name('services.import');
        Route::get('servicios/articulos', [ServiceController::class, 'products'])->middleware('permission:services.index.create|services.index.edit')->name('services.products');
        Route::get('servicios/crear', [ServiceController::class, 'create'])->middleware('permission:services.index.create')->name('services.create');
        Route::post('servicios', [ServiceController::class, 'store'])->middleware('permission:services.index.create')->name('services.store');
        Route::get('servicios/{service}', [ServiceController::class, 'show'])->middleware('permission:services.show.view')->name('services.show');
        Route::get('servicios/{service}/editar', [ServiceController::class, 'edit'])->middleware('permission:services.index.edit')->name('services.edit');
        Route::put('servicios/{service}', [ServiceController::class, 'update'])->middleware('permission:services.index.edit')->name('services.update');
        Route::patch('servicios/{service}/en-linea', [ServiceController::class, 'online'])->middleware('permission:services.index.edit')->name('services.online');
        Route::delete('servicios/{service}/fotos/{image}', [ServiceController::class, 'destroyImage'])->middleware('permission:services.index.edit')->name('services.images.destroy');
        Route::patch('servicios/{service}/fotos/{image}/portada', [ServiceController::class, 'coverImage'])->middleware('permission:services.index.edit')->name('services.images.cover');
        Route::post('servicios/{service}/duplicar', [ServiceController::class, 'duplicate'])->middleware('permission:services.index.create')->name('services.duplicate');
        Route::delete('servicios/{service}', [ServiceController::class, 'destroy'])->middleware('permission:services.index.delete')->name('services.destroy');

        // Importacion de articulos desde un catalogo externo.
        Route::get('catalogo/importar', [CatalogImportController::class, 'index'])->middleware('permission:products.catalog_import.view')->name('catalog.import.index');
        // La previsualizacion consulta una API publica: sin tope, la aplicacion se vuelve su proxy.
        Route::post('catalogo/importar/previsualizar', [CatalogImportController::class, 'preview'])->middleware(['permission:products.catalog_import.view', 'throttle:20,1'])->name('catalog.import.preview');
        Route::post('catalogo/importar', [CatalogImportController::class, 'store'])->middleware('permission:products.catalog_import.run')->name('catalog.import.store');
        Route::get('catalogo/importar/{import}', [CatalogImportController::class, 'show'])->middleware('permission:products.catalog_import.view')->name('catalog.import.show');
        Route::get('catalogo/importar/{import}/articulos', [CatalogImportController::class, 'items'])->middleware('permission:products.catalog_import.view')->name('catalog.import.items');
        Route::get('catalogo/importar/{import}/exportar', [CatalogImportController::class, 'export'])->middleware('permission:products.catalog_import.view')->name('catalog.import.export');
        Route::post('catalogo/importar/{import}/ejecutar', [CatalogImportController::class, 'run'])->middleware('permission:products.catalog_import.run')->name('catalog.import.run');
        Route::post('catalogo/importar/{import}/cancelar', [CatalogImportController::class, 'cancel'])->middleware('permission:products.catalog_import.cancel')->name('catalog.import.cancel');

        Route::get('categorias', [CategoryController::class, 'index'])->middleware('permission:categories.index.view')->name('categories.index');
        Route::post('categorias', [CategoryController::class, 'store'])->middleware('permission:categories.index.create')->name('categories.store');
        Route::get('categorias/importar', [ImportController::class, 'form'])->defaults('module', 'categories')->middleware('permission:categories.index.import')->name('categories.import.form');
        Route::post('categorias/importar', [ImportController::class, 'store'])->defaults('module', 'categories')->middleware('permission:categories.index.import')->name('categories.import');
        Route::put('categorias/{category}', [CategoryController::class, 'update'])->middleware('permission:categories.index.edit')->name('categories.update');
        Route::delete('categorias/{category}', [CategoryController::class, 'destroy'])->middleware('permission:categories.index.delete')->name('categories.destroy');

        Route::get('proveedores', [SupplierController::class, 'index'])->middleware('permission:suppliers.index.view')->name('suppliers.index');
        Route::post('proveedores', [SupplierController::class, 'store'])->middleware('permission:suppliers.index.create')->name('suppliers.store');
        Route::get('proveedores/importar', [ImportController::class, 'form'])->defaults('module', 'suppliers')->middleware('permission:suppliers.index.import')->name('suppliers.import.form');
        Route::post('proveedores/importar', [ImportController::class, 'store'])->defaults('module', 'suppliers')->middleware('permission:suppliers.index.import')->name('suppliers.import');
        Route::put('proveedores/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.index.edit')->name('suppliers.update');
        Route::delete('proveedores/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers.index.delete')->name('suppliers.destroy');

        // Punto de venta
        Route::get('pos', [PosSaleController::class, 'index'])->middleware('permission:pos.sale.view')->name('pos.index');
        Route::get('pos/productos', [PosSaleController::class, 'products'])->middleware('permission:pos.sale.view')->name('pos.products');
        Route::get('pos/clientes', [PosSaleController::class, 'customers'])->middleware('permission:pos.sale.view')->name('pos.customers');
        Route::post('pos/cotizar', [PosSaleController::class, 'quote'])->middleware('permission:pos.sale.view')->name('pos.quote');
        Route::get('pos/ventas-recientes', [PosSaleController::class, 'recent'])->middleware('permission:pos.sale.view')->name('pos.sales.recent');
        Route::post('pos/ventas', [PosSaleController::class, 'store'])->middleware('permission:pos.sale.create')->name('pos.sales.store');
        Route::post('pos/autorizacion', [PosAuthorizationController::class, 'store'])->middleware('permission:pos.sale.view')->name('pos.authorization');
        Route::post('pos/ventas/{sale}/anular', [PosSaleController::class, 'cancel'])->middleware('permission:pos.sale.cancel')->name('pos.sales.cancel');
        Route::get('pos/ventas/{sale}/tiquete', [PosSaleController::class, 'receipt'])->middleware('permission:pos.sale.view')->name('pos.sales.receipt');
        Route::get('pos/ventas/{sale}/imprimir', [PosSaleController::class, 'printReceipt'])->middleware('permission:pos.sale.view')->name('pos.sales.print');
        // Devoluciones en caja: de cualquier dia, buscando la venta por su numero.
        Route::get('pos/ventas/buscar', [PosReturnController::class, 'lookup'])->middleware('permission:pos.sale.refund')->name('pos.sales.lookup');
        Route::get('pos/ventas/{sale}/detalle', [PosReturnController::class, 'detail'])->middleware('permission:pos.sale.refund')->name('pos.sales.detail');
        Route::post('pos/ventas/{sale}/devolucion', [PosReturnController::class, 'store'])->middleware('permission:pos.sale.refund')->name('pos.sales.refund');
        Route::post('pos/ventas/{sale}/cambio', [PosReturnController::class, 'exchange'])->middleware('permission:pos.sale.refund')->name('pos.sales.exchange');
        Route::get('pos/devoluciones/{customerReturn}/imprimir', [PosReturnController::class, 'print'])->middleware('permission:pos.sale.refund')->name('pos.returns.print');
        // Cobro con QR, link o datafono de la pasarela: la venta nace cuando la pasarela aprueba.
        Route::post('pos/pagos', [PosGatewayPaymentController::class, 'store'])->middleware(['permission:pos.sale.gateway', 'throttle:30,1'])->name('pos.gateway.store');
        Route::get('pos/pagos/{reference}', [PosGatewayPaymentController::class, 'show'])->where('reference', 'PAY-[A-Z0-9]+')->middleware(['permission:pos.sale.view', 'throttle:120,1'])->name('pos.gateway.show');
        Route::post('pos/pagos/{reference}/cancelar', [PosGatewayPaymentController::class, 'cancel'])->where('reference', 'PAY-[A-Z0-9]+')->middleware('permission:pos.sale.gateway')->name('pos.gateway.cancel');
        Route::get('pos/suspendidos', [HeldSaleController::class, 'index'])->middleware('permission:pos.sale.suspend')->name('pos.hold.index');
        Route::post('pos/suspendidos', [HeldSaleController::class, 'store'])->middleware('permission:pos.sale.suspend')->name('pos.hold.store');
        Route::delete('pos/suspendidos/{held}', [HeldSaleController::class, 'destroy'])->middleware('permission:pos.sale.suspend')->name('pos.hold.destroy');

        // Caja
        // Historial de ventas del panel: lo cobrado en mostrador y en linea, sin abrir una caja.
        Route::get('ventas', [SaleController::class, 'index'])->middleware('permission:sales.index.view')->name('sales.index');
        // Devoluciones de venta. Antes de `ventas/{sale}`, o `{sale}` se traga «devoluciones».
        Route::get('ventas/devoluciones', [CustomerReturnController::class, 'index'])->middleware('permission:sales.returns.view')->name('customer_returns.index');
        Route::get('ventas/devoluciones/exportar', [CustomerReturnController::class, 'export'])->middleware('permission:sales.returns.view')->name('customer_returns.export');
        Route::get('ventas/devoluciones/crear', [CustomerReturnController::class, 'create'])->middleware('permission:sales.returns.create')->name('customer_returns.create');
        Route::post('ventas/devoluciones', [CustomerReturnController::class, 'store'])->middleware('permission:sales.returns.create')->name('customer_returns.store');
        Route::get('ventas/devoluciones/{customerReturn}', [CustomerReturnController::class, 'show'])->middleware('permission:sales.returns.view')->name('customer_returns.show');
        Route::get('ventas/devoluciones/{customerReturn}/documento', [CustomerReturnController::class, 'document'])->middleware('permission:sales.returns.view')->name('customer_returns.document');
        Route::get('ventas/devoluciones/{customerReturn}/fotos/{index}', [CustomerReturnController::class, 'photo'])->whereNumber('index')->middleware('permission:sales.returns.view')->name('customer_returns.photo');
        Route::post('ventas/devoluciones/{customerReturn}/aprobar', [CustomerReturnController::class, 'approve'])->middleware('permission:sales.returns.approve')->name('customer_returns.approve');
        Route::post('ventas/devoluciones/{customerReturn}/rechazar', [CustomerReturnController::class, 'reject'])->middleware('permission:sales.returns.approve')->name('customer_returns.reject');
        Route::post('ventas/devoluciones/{customerReturn}/recibir', [CustomerReturnController::class, 'receive'])->middleware('permission:sales.returns.receive')->name('customer_returns.receive');
        Route::post('ventas/devoluciones/{customerReturn}/resolver', [CustomerReturnController::class, 'resolve'])->middleware('permission:sales.returns.resolve')->name('customer_returns.resolve');
        Route::post('ventas/devoluciones/{customerReturn}/anular', [CustomerReturnController::class, 'cancel'])->middleware('permission:sales.returns.cancel')->name('customer_returns.cancel');
        Route::get('ventas/{sale}', [SaleController::class, 'show'])->middleware('permission:sales.index.view')->name('sales.show');
        Route::get('ventas/{sale}/imprimir', [SaleController::class, 'print'])->middleware('permission:sales.index.view')->name('sales.print');

        Route::get('cajas', [RegisterController::class, 'index'])->middleware('permission:registers.index.view')->name('registers.index');
        Route::post('cajas', [RegisterController::class, 'store'])->middleware('permission:registers.index.manage')->name('registers.store');
        Route::get('cajas/importar', [ImportController::class, 'form'])->defaults('module', 'registers')->middleware('permission:registers.index.import')->name('registers.import.form');
        Route::post('cajas/importar', [ImportController::class, 'store'])->defaults('module', 'registers')->middleware('permission:registers.index.import')->name('registers.import');
        Route::put('cajas/{register}', [RegisterController::class, 'update'])->middleware('permission:registers.index.manage')->name('registers.update');
        Route::delete('cajas/{register}', [RegisterController::class, 'destroy'])->middleware('permission:registers.index.manage')->name('registers.destroy');

        // Diferencias de caja: los turnos que no cuadraron y que alguien tiene que cerrar.
        Route::get('caja/diferencias', [CashDifferenceController::class, 'index'])->middleware('permission:registers.differences.view')->name('registers.differences.index');
        Route::post('caja/diferencias/{session}', [CashDifferenceController::class, 'resolve'])->middleware('permission:registers.differences.resolve')->name('registers.differences.resolve');
        Route::post('caja/diferencias/{session}/reabrir', [CashDifferenceController::class, 'reopen'])->middleware('permission:registers.differences.resolve')->name('registers.differences.reopen');

        Route::get('caja/apertura', [OpeningController::class, 'create'])->middleware('permission:registers.open.view')->name('registers.opening.create');
        Route::post('caja/apertura', [OpeningController::class, 'store'])->middleware('permission:registers.open.create')->name('registers.opening.store');
        Route::get('caja/cierre', [ClosingController::class, 'create'])->middleware('permission:registers.close.view')->name('registers.closing.create');
        Route::post('caja/cierre', [ClosingController::class, 'store'])->middleware('permission:registers.close.create')->name('registers.closing.store');
        Route::get('caja/cierre/{session}/informe', [ClosingController::class, 'report'])->middleware('permission:registers.close.view')->name('registers.closing.report');
        Route::post('caja/movimientos', [CashMovementController::class, 'store'])->middleware('permission:registers.movements.create')->name('registers.movements.store');

        // Inventario
        Route::get('inventario', [InventoryController::class, 'index'])->middleware('permission:inventory.index.view')->name('inventory.index');
        Route::post('inventario/ajustes', [InventoryController::class, 'adjust'])->middleware('permission:inventory.index.adjust')->name('inventory.adjust');
        // Entradas y salidas de bodega con varios articulos. Van antes que la del kardex por si
        // manana alguna lleva parametro: {product} se tragaria la palabra suelta.
        Route::get('inventario/entradas', [StockMovementController::class, 'createEntry'])->middleware('permission:inventory.index.adjust')->name('inventory.entry.create');
        Route::get('inventario/salidas', [StockMovementController::class, 'createExit'])->middleware('permission:inventory.index.adjust')->name('inventory.exit.create');
        Route::get('inventario/articulos', [StockMovementController::class, 'products'])->middleware('permission:inventory.index.adjust')->name('inventory.products');
        Route::post('inventario/movimientos', [StockMovementController::class, 'store'])->middleware('permission:inventory.index.adjust')->name('inventory.movement.store');
        Route::get('inventario/existencias/{product}', [InventoryController::class, 'stock'])->middleware('permission:inventory.index.view')->name('inventory.stock');
        Route::get('inventario/kardex/{product}', [InventoryController::class, 'movements'])->middleware('permission:inventory.movements.view')->name('inventory.movements');

        // Traslados entre bodegas. «crear» va antes que «{transfer}» o se traga la palabra.
        Route::get('traslados', [StockTransferController::class, 'index'])->middleware('permission:inventory.transfers.view')->name('transfers.index');
        Route::get('traslados/crear', [StockTransferController::class, 'create'])->middleware('permission:inventory.transfers.create')->name('transfers.create');
        Route::post('traslados', [StockTransferController::class, 'store'])->middleware('permission:inventory.transfers.create')->name('transfers.store');
        Route::post('traslados/reposicion', [StockTransferController::class, 'replenish'])->middleware('permission:inventory.transfers.create')->name('transfers.replenish');

        // Solicitudes de traslado. Van antes que «traslados/{transfer}» o se traga «solicitudes».
        Route::get('traslados/solicitudes', [StockTransferRequestController::class, 'index'])->middleware('permission:inventory.transfer_requests.view')->name('transfer_requests.index');
        Route::get('traslados/solicitudes/crear', [StockTransferRequestController::class, 'create'])->middleware('permission:inventory.transfer_requests.create')->name('transfer_requests.create');
        Route::get('traslados/solicitudes/articulos', [StockTransferRequestController::class, 'products'])->middleware('permission:inventory.transfer_requests.create')->name('transfer_requests.products');
        Route::post('traslados/solicitudes', [StockTransferRequestController::class, 'store'])->middleware('permission:inventory.transfer_requests.create')->name('transfer_requests.store');
        Route::get('traslados/solicitudes/{transferRequest}', [StockTransferRequestController::class, 'show'])->middleware('permission:inventory.transfer_requests.view')->name('transfer_requests.show');
        Route::post('traslados/solicitudes/{transferRequest}/atender', [StockTransferRequestController::class, 'fulfill'])->middleware('permission:inventory.transfer_requests.fulfill')->name('transfer_requests.fulfill');
        Route::post('traslados/solicitudes/{transferRequest}/rechazar', [StockTransferRequestController::class, 'reject'])->middleware('permission:inventory.transfer_requests.fulfill')->name('transfer_requests.reject');
        Route::post('traslados/solicitudes/{transferRequest}/cerrar', [StockTransferRequestController::class, 'close'])->middleware('permission:inventory.transfer_requests.fulfill')->name('transfer_requests.close');
        Route::post('traslados/solicitudes/{transferRequest}/anular', [StockTransferRequestController::class, 'cancel'])->middleware('permission:inventory.transfer_requests.cancel')->name('transfer_requests.cancel');

        Route::get('traslados/{transfer}', [StockTransferController::class, 'show'])->middleware('permission:inventory.transfers.view')->name('transfers.show');
        Route::post('traslados/{transfer}/despachar', [StockTransferController::class, 'dispatch'])->middleware('permission:inventory.transfers.dispatch')->name('transfers.dispatch');
        Route::post('traslados/{transfer}/directo', [StockTransferController::class, 'direct'])->middleware('permission:inventory.transfers.direct')->name('transfers.direct');
        Route::post('traslados/{transfer}/recibir', [StockTransferController::class, 'receive'])->middleware('permission:inventory.transfers.receive')->name('transfers.receive');
        Route::post('traslados/{transfer}/anular', [StockTransferController::class, 'cancel'])->middleware('permission:inventory.transfers.cancel')->name('transfers.cancel');

        // Compras
        Route::get('compras', [PurchaseOrderController::class, 'index'])->middleware('permission:purchases.index.view')->name('purchases.index');
        Route::get('compras/crear', [PurchaseOrderController::class, 'create'])->middleware('permission:purchases.index.create')->name('purchases.create');
        // Antes de la que lleva parametro, o `{order}` se traga «articulos» y «sugerencias».
        Route::get('compras/articulos', [PurchaseOrderController::class, 'products'])->middleware('permission:purchases.index.create')->name('purchases.products');
        Route::get('compras/sugerencias', [PurchaseOrderController::class, 'suggestions'])->middleware('permission:purchases.index.view')->name('purchases.suggestions');
        Route::get('compras/familia/{family}', [PurchaseOrderController::class, 'familyMatrix'])->whereNumber('family')->middleware('permission:purchases.index.create')->name('purchases.family');
        Route::post('compras', [PurchaseOrderController::class, 'store'])->middleware('permission:purchases.index.create')->name('purchases.store');

        // Devoluciones a proveedor. Antes de `compras/{order}`, o `{order}` se traga «devoluciones»;
        // y las fijas antes de las que llevan `{supplierReturn}`.
        Route::get('compras/devoluciones', [SupplierReturnController::class, 'index'])->middleware('permission:purchases.returns.view')->name('supplier_returns.index');
        Route::get('compras/devoluciones/exportar', [SupplierReturnController::class, 'export'])->middleware('permission:purchases.returns.view')->name('supplier_returns.export');
        Route::get('compras/devoluciones/articulos', [SupplierReturnController::class, 'products'])->middleware('permission:purchases.returns.create')->name('supplier_returns.products');
        Route::get('compras/devoluciones/crear', [SupplierReturnController::class, 'create'])->middleware('permission:purchases.returns.create')->name('supplier_returns.create');
        Route::post('compras/devoluciones', [SupplierReturnController::class, 'store'])->middleware('permission:purchases.returns.create')->name('supplier_returns.store');
        Route::get('compras/devoluciones/{supplierReturn}', [SupplierReturnController::class, 'show'])->middleware('permission:purchases.returns.view')->name('supplier_returns.show');
        Route::get('compras/devoluciones/{supplierReturn}/editar', [SupplierReturnController::class, 'edit'])->middleware('permission:purchases.returns.create')->name('supplier_returns.edit');
        Route::put('compras/devoluciones/{supplierReturn}', [SupplierReturnController::class, 'update'])->middleware('permission:purchases.returns.create')->name('supplier_returns.update');
        Route::post('compras/devoluciones/{supplierReturn}/despachar', [SupplierReturnController::class, 'dispatch'])->middleware('permission:purchases.returns.dispatch')->name('supplier_returns.dispatch');
        Route::post('compras/devoluciones/{supplierReturn}/rechazo', [SupplierReturnController::class, 'reject'])->middleware('permission:purchases.returns.respond')->name('supplier_returns.reject');
        Route::post('compras/devoluciones/{supplierReturn}/respuestas', [SupplierReturnController::class, 'settle'])->middleware('permission:purchases.returns.respond')->name('supplier_returns.settle');
        Route::post('compras/devoluciones/{supplierReturn}/reposicion', [SupplierReturnController::class, 'replacement'])->middleware('permission:purchases.returns.respond')->name('supplier_returns.replacement');
        Route::post('compras/devoluciones/{supplierReturn}/anular', [SupplierReturnController::class, 'cancel'])->middleware('permission:purchases.returns.cancel')->name('supplier_returns.cancel');
        Route::get('compras/devoluciones/{supplierReturn}/documento', [SupplierReturnController::class, 'document'])->middleware('permission:purchases.returns.view')->name('supplier_returns.document');

        Route::get('compras/{order}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchases.index.view')->name('purchases.show');
        Route::post('compras/{order}/recibir', [PurchaseOrderController::class, 'receive'])->middleware('permission:purchases.index.receive')->name('purchases.receive');
        Route::post('compras/{order}/enviar', [PurchaseOrderController::class, 'send'])->middleware('permission:purchases.index.create')->name('purchases.send');
        Route::post('compras/{order}/separar', [PurchaseOrderController::class, 'split'])->middleware('permission:purchases.index.create')->name('purchases.split');
        // Cerrar una orden a la que ya no le va a llegar el resto: no mueve inventario.
        Route::post('compras/{order}/cerrar', [PurchaseOrderController::class, 'close'])->middleware('permission:purchases.index.receive')->name('purchases.close');
        Route::patch('compras/{order}/plazo', [PurchaseOrderController::class, 'updateTerm'])->middleware('permission:purchases.index.create')->name('purchases.term');
        Route::post('compras/{order}/anular', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchases.index.cancel')->name('purchases.cancel');

        // Pedidos
        // Promociones de la tienda: cuatro tipos, una sola pantalla.
        Route::get('promociones', [PromotionController::class, 'index'])->middleware('permission:promotions.index.view')->name('promotions.index');
        Route::get('promociones/articulos', [PromotionController::class, 'products'])->middleware('permission:promotions.index.view')->name('promotions.products');
        Route::post('promociones', [PromotionController::class, 'store'])->middleware('permission:promotions.index.create')->name('promotions.store');
        Route::put('promociones/{promotion}', [PromotionController::class, 'update'])->middleware('permission:promotions.index.edit')->name('promotions.update');
        Route::patch('promociones/{promotion}/estado', [PromotionController::class, 'toggle'])->middleware('permission:promotions.index.edit')->name('promotions.toggle');
        Route::delete('promociones/{promotion}', [PromotionController::class, 'destroy'])->middleware('permission:promotions.index.delete')->name('promotions.destroy');

        Route::get('pedidos', [OrderController::class, 'index'])->middleware('permission:orders.index.view')->name('orders.index');
        Route::get('pedidos/crear', [OrderController::class, 'create'])->middleware('permission:orders.index.create')->name('orders.create');
        Route::post('pedidos', [OrderController::class, 'store'])->middleware('permission:orders.index.create')->name('orders.store');
        Route::get('pedidos/{order}', [OrderController::class, 'show'])->middleware('permission:orders.show.view')->name('orders.show');
        Route::patch('pedidos/{order}/estado', [OrderController::class, 'updateStatus'])->middleware('permission:orders.show.status')->name('orders.status');
        Route::post('pedidos/{order}/alistar', [OrderController::class, 'pick'])->middleware('permission:orders.show.pick')->name('orders.pick');
        Route::post('pedidos/{order}/domiciliario', [OrderController::class, 'assignCourier'])->middleware('permission:orders.show.assign_courier')->name('orders.courier');
        Route::post('pedidos/{order}/cobro', [OrderController::class, 'collect'])->middleware('permission:orders.show.collect')->name('orders.collect');

        // Clientes
        Route::get('clientes', [CustomerController::class, 'index'])->middleware('permission:customers.index.view')->name('customers.index');
        Route::post('clientes', [CustomerController::class, 'store'])->middleware('permission:customers.index.create')->name('customers.store');
        Route::get('clientes/importar', [ImportController::class, 'form'])->defaults('module', 'customers')->middleware('permission:customers.index.import')->name('customers.import.form');
        Route::post('clientes/importar', [ImportController::class, 'store'])->defaults('module', 'customers')->middleware('permission:customers.index.import')->name('customers.import');
        Route::get('clientes/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.show.view')->name('customers.show');
        Route::put('clientes/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.index.edit')->name('customers.update');
        Route::delete('clientes/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.index.delete')->name('customers.destroy');

        // Cartera
        Route::get('cartera', [CreditController::class, 'index'])->middleware('permission:credit.index.view')->name('credit.index');
        Route::get('cartera/exportar', [CreditController::class, 'export'])->middleware('permission:credit.index.export')->name('credit.export');
        Route::get('cartera/{customer}', [CreditController::class, 'show'])->middleware('permission:credit.index.view')->name('credit.show');
        Route::post('cartera/{customer}/cupo', [CreditController::class, 'setLimit'])->middleware('permission:credit.index.set_limit')->name('credit.limit');
        Route::post('cartera/{customer}/bloqueo', [CreditController::class, 'toggleBlock'])->middleware('permission:credit.index.block')->name('credit.block');
        Route::post('cartera/{customer}/abonos', [CustomerPaymentController::class, 'store'])->middleware('permission:credit.payments.create')->name('credit.payments.store');

        // Informes
        Route::get('informes', [ReportController::class, 'index'])->middleware('permission:reports.sales.view')->name('reports.index');
        Route::get('informes/ventas/exportar', [ReportController::class, 'exportSales'])->middleware('permission:reports.sales.export')->name('reports.sales.export');
        Route::get('informes/ventas/pdf', [ReportController::class, 'exportSalesPdf'])->middleware('permission:reports.sales.export')->name('reports.sales.pdf');

        // Tienda en linea (administracion del surtido)
        Route::get('tienda-en-linea', [StorefrontCatalogManagerController::class, 'index'])->middleware('permission:storefront.catalog.manage')->name('storefront.catalog.index');
        Route::post('tienda-en-linea', [StorefrontCatalogManagerController::class, 'update'])->middleware('permission:storefront.catalog.manage')->name('storefront.catalog.update');
        // Todo es parte de decidir el surtido: no hay permiso nuevo que repartir.
        Route::get('tienda-en-linea/sin-stock', [StorefrontOutOfStockController::class, 'index'])->middleware('permission:storefront.catalog.manage')->name('storefront.catalog.out_of_stock');
        Route::get('tienda-en-linea/sugerencias', [StorefrontSuggestionController::class, 'index'])->middleware('permission:storefront.catalog.manage')->name('storefront.catalog.suggestions');
        Route::post('tienda-en-linea/sugerencias/descartar', [StorefrontSuggestionController::class, 'dismiss'])->middleware('permission:storefront.catalog.manage')->name('storefront.catalog.suggestions.dismiss');
        Route::delete('tienda-en-linea/sugerencias/descartar', [StorefrontSuggestionController::class, 'restore'])->middleware('permission:storefront.catalog.manage')->name('storefront.catalog.suggestions.restore');
        Route::get('tienda-en-linea/exportar', [StorefrontCatalogManagerController::class, 'export'])->middleware('permission:storefront.catalog.manage')->name('storefront.catalog.export');
        // Los correos que dejan los clientes en el pie (si la sede encendió el formulario).
        Route::get('tienda-en-linea/suscriptores', [StorefrontNewsletterController::class, 'index'])->middleware('permission:storefront.newsletter.view')->name('storefront.newsletter.index');
        Route::get('tienda-en-linea/suscriptores/exportar', [StorefrontNewsletterController::class, 'export'])->middleware('permission:storefront.newsletter.view')->name('storefront.newsletter.export');

        // Sedes
        // Editor de la tienda: borrador por sede, publicacion e historial.
        Route::get('tienda/editor', [EditorController::class, 'index'])->middleware('permission:storefront.editor.view')->name('storefront.editor.index');
        Route::get('tienda/editor/articulos', [EditorController::class, 'products'])->middleware('permission:storefront.editor.view')->name('storefront.editor.products');
        Route::patch('tienda/editor/tema', [ThemeController::class, 'update'])->middleware('permission:storefront.editor.appearance')->name('storefront.editor.theme');
        Route::post('tienda/editor/bloques', [EditorBlockController::class, 'store'])->middleware('permission:storefront.editor.content')->name('storefront.editor.blocks.store');
        Route::patch('tienda/editor/bloques/{block}', [EditorBlockController::class, 'update'])->middleware('permission:storefront.editor.content')->name('storefront.editor.blocks.update');
        Route::post('tienda/editor/bloques/{block}/duplicar', [EditorBlockController::class, 'duplicate'])->middleware('permission:storefront.editor.content')->name('storefront.editor.blocks.duplicate');
        Route::delete('tienda/editor/bloques/{block}', [EditorBlockController::class, 'destroy'])->middleware('permission:storefront.editor.content')->name('storefront.editor.blocks.destroy');
        Route::post('tienda/editor/bloques/orden', [EditorBlockController::class, 'reorder'])->middleware('permission:storefront.editor.content')->name('storefront.editor.blocks.reorder');
        // Los ajustes van por el permiso de la pestaña donde se editan: lo operativo en Funciones,
        // la estructura global en Contenido y la tarjeta de producto en Aspecto.
        Route::patch('tienda/editor/funciones/{key}', [EditorSettingController::class, 'update'])->where('key', 'delivery|coverage|slots|texts|seo|catalog|category_page|product_page|search')->middleware('permission:storefront.editor.settings')->name('storefront.editor.settings');
        Route::patch('tienda/editor/estructura/{key}', [EditorSettingController::class, 'update'])->where('key', 'topbar|header|nav|mobile_nav|cart_bar|footer|floating')->middleware('permission:storefront.editor.content')->name('storefront.editor.structure');
        Route::patch('tienda/editor/aspecto/{key}', [EditorSettingController::class, 'update'])->where('key', 'card')->middleware('permission:storefront.editor.appearance')->name('storefront.editor.look');
        // La plantilla base: personalizar (dejar de seguirla) y volver a ella o aplicar otra.
        Route::post('tienda/editor/plantilla/personalizar', [TemplateLinkController::class, 'detach'])->middleware('permission:storefront.editor.content')->name('storefront.editor.template.detach');
        Route::post('tienda/editor/plantilla/volver', [TemplateLinkController::class, 'reattach'])->middleware('permission:storefront.editor.content')->name('storefront.editor.template.reattach');

        Route::post('tienda/editor/zonas', [DeliveryZoneController::class, 'store'])->middleware('permission:storefront.editor.settings')->name('storefront.editor.zones.store');
        Route::patch('tienda/editor/zonas/{zone}', [DeliveryZoneController::class, 'update'])->middleware('permission:storefront.editor.settings')->name('storefront.editor.zones.update');
        Route::delete('tienda/editor/zonas/{zone}', [DeliveryZoneController::class, 'destroy'])->middleware('permission:storefront.editor.settings')->name('storefront.editor.zones.destroy');
        Route::post('tienda/editor/franjas', [DeliverySlotController::class, 'store'])->middleware('permission:storefront.editor.settings')->name('storefront.editor.slots.store');
        Route::patch('tienda/editor/franjas/{slot}', [DeliverySlotController::class, 'update'])->middleware('permission:storefront.editor.settings')->name('storefront.editor.slots.update');
        Route::delete('tienda/editor/franjas/{slot}', [DeliverySlotController::class, 'destroy'])->middleware('permission:storefront.editor.settings')->name('storefront.editor.slots.destroy');

        Route::post('tienda/editor/publicar', [PublishController::class, 'store'])->middleware('permission:storefront.editor.publish')->name('storefront.editor.publish');
        Route::patch('tienda/editor/en-linea', [PublishController::class, 'online'])->middleware('permission:storefront.editor.publish')->name('storefront.editor.online');
        Route::get('tienda/editor/historial', [VersionController::class, 'index'])->middleware('permission:storefront.editor.history')->name('storefront.editor.history');
        Route::post('tienda/editor/historial/{version}/revertir', [VersionController::class, 'restore'])->middleware('permission:storefront.editor.history')->name('storefront.editor.restore');
        Route::post('tienda/editor/copiar', [EditorCopyController::class, 'store'])->middleware('permission:storefront.editor.copy_store')->name('storefront.editor.copy');

        // Biblioteca de imagenes del editor.
        Route::get('medios', [MediaController::class, 'index'])->middleware('permission:storefront.editor.content')->name('media.index');
        Route::post('medios', [MediaController::class, 'store'])->middleware('permission:storefront.editor.content')->name('media.store');
        Route::post('medios/videos', [MediaController::class, 'storeVideo'])->middleware(['permission:storefront.editor.content', 'throttle:10,1'])->name('media.store-video');
        Route::patch('medios/{media}', [MediaController::class, 'update'])->middleware('permission:storefront.editor.content')->name('media.update');

        // Cupones.
        Route::get('cupones', [CouponController::class, 'index'])->middleware('permission:coupons.index.view')->name('coupons.index');
        Route::get('cupones/articulos', [CouponController::class, 'products'])->middleware('permission:coupons.index.view')->name('coupons.products');
        Route::post('cupones', [CouponController::class, 'store'])->middleware('permission:coupons.index.create')->name('coupons.store');
        Route::put('cupones/{coupon}', [CouponController::class, 'update'])->middleware('permission:coupons.index.edit')->name('coupons.update');
        Route::patch('cupones/{coupon}/estado', [CouponController::class, 'toggle'])->middleware('permission:coupons.index.edit')->name('coupons.toggle');
        Route::delete('cupones/{coupon}', [CouponController::class, 'destroy'])->middleware('permission:coupons.index.delete')->name('coupons.destroy');

        Route::get('sedes', [StoreController::class, 'index'])->middleware('permission:stores.index.view')->name('stores.index');
        Route::post('sedes', [StoreController::class, 'store'])->middleware('permission:stores.index.create')->name('stores.store');
        Route::get('sedes/importar', [ImportController::class, 'form'])->defaults('module', 'stores')->middleware('permission:stores.index.import')->name('stores.import.form');
        Route::post('sedes/importar', [ImportController::class, 'store'])->defaults('module', 'stores')->middleware('permission:stores.index.import')->name('stores.import');
        Route::put('sedes/{store}', [StoreController::class, 'update'])->middleware('permission:stores.index.edit')->name('stores.update');
        Route::delete('sedes/{store}', [StoreController::class, 'destroy'])->middleware('permission:stores.index.delete')->name('stores.destroy');
        // Bodegas de una sede. Van despues de {store} porque todas lo llevan de prefijo.
        Route::get('sedes/{store}/bodegas', [WarehouseController::class, 'index'])->middleware('permission:stores.warehouses.view')->name('stores.warehouses.index');
        Route::post('sedes/{store}/bodegas', [WarehouseController::class, 'store'])->middleware('permission:stores.warehouses.create')->name('stores.warehouses.store');
        Route::put('sedes/{store}/bodegas/{warehouse}', [WarehouseController::class, 'update'])->middleware('permission:stores.warehouses.edit')->name('stores.warehouses.update');
        Route::post('sedes/{store}/bodegas/{warehouse}/estado', [WarehouseController::class, 'deactivate'])->middleware('permission:stores.warehouses.deactivate')->name('stores.warehouses.deactivate');

        // Ajustes. Una ruta por pestana, cada una con su permiso: quien cambia el redondeo no
        // cambia por eso el logo. Las fijas van antes de las que llevan parametro.
        Route::get('ajustes', [SettingController::class, 'index'])->middleware('permission:settings.index.view')->name('settings.index');

        Route::put('ajustes/marca', [BrandSettingController::class, 'update'])->middleware('permission:settings.index.brand')->name('settings.brand.update');
        Route::post('ajustes/marca/imagenes/{slot}', [BrandImageController::class, 'store'])->middleware('permission:settings.index.brand')->whereIn('slot', ['mark', 'logo_light', 'logo_dark'])->name('settings.brand.images.store');
        Route::delete('ajustes/marca/imagenes/{slot}', [BrandImageController::class, 'destroy'])->middleware('permission:settings.index.brand')->whereIn('slot', ['mark', 'logo_light', 'logo_dark'])->name('settings.brand.images.destroy');
        Route::put('ajustes/apariencia', [AppearanceSettingController::class, 'update'])->middleware('permission:settings.index.brand')->name('settings.appearance.update');
        Route::put('ajustes/menu', [NavigationSettingController::class, 'update'])->middleware('permission:settings.index.brand')->name('settings.navigation.update');

        Route::put('ajustes/caja', [PosSettingController::class, 'update'])->middleware('permission:settings.index.pos_layout')->name('settings.pos.update');
        Route::put('ajustes/caja/reglas', [PosRulesSettingController::class, 'update'])->middleware('permission:settings.index.edit')->name('settings.pos_rules.update');
        Route::put('ajustes/tiquete', [ReceiptSettingController::class, 'update'])->middleware('permission:settings.index.pos_layout')->name('settings.receipt.update');
        Route::put('ajustes/tiquete/sedes/{store}', [ReceiptSettingController::class, 'updateStore'])->middleware('permission:settings.index.pos_layout')->name('settings.receipt.store.update');

        Route::put('ajustes/credito', [CreditSettingController::class, 'update'])->middleware('permission:settings.index.edit')->name('settings.credit.update');
        Route::put('ajustes/catalogo', [CatalogSettingController::class, 'update'])->middleware('permission:settings.index.edit')->name('settings.catalog.update');
        Route::put('ajustes/empresa', [CompanySettingController::class, 'update'])->middleware('permission:settings.index.edit')->name('settings.company.update');

        // Membresia. Siguen abiertas con la empresa suspendida: son el camino para ponerse al dia.
        Route::get('ajustes/membresia/aceptacion', [MembershipSettingController::class, 'acceptance'])->middleware(['permission:settings.membership.manage_payment', 'throttle:20,1'])->name('settings.membership.acceptance');
        Route::put('ajustes/membresia/tarjeta', [MembershipSettingController::class, 'updateCard'])->middleware(['permission:settings.membership.manage_payment', 'throttle:10,1'])->name('settings.membership.card');
        Route::put('ajustes/membresia/renovacion', [MembershipSettingController::class, 'autoRenew'])->middleware('permission:settings.membership.manage_payment')->name('settings.membership.auto_renew');
        Route::post('ajustes/membresia/pagar', [MembershipSettingController::class, 'pay'])->middleware(['permission:settings.membership.manage_payment', 'throttle:10,1'])->name('settings.membership.pay');
        Route::get('ajustes/membresia/retorno', [MembershipSettingController::class, 'checkoutReturn'])->middleware('permission:settings.membership.view')->name('settings.membership.return');
        Route::put('ajustes/membresia/plan', [MembershipSettingController::class, 'schedule'])->middleware('permission:settings.membership.manage_payment')->name('settings.membership.plan');
        Route::get('ajustes/membresia/cobros/{charge}/comprobante', [MembershipSettingController::class, 'receipt'])->middleware('permission:settings.membership.view')->name('settings.membership.receipt');

        // Medios de pago de la empresa: los manuales y sus propias pasarelas. Las fijas
        // (`pasarelas`, `datafonos`, `transacciones`, `orden`) antes de las que llevan `{method}`.
        Route::get('medios-pago', [PaymentMethodController::class, 'index'])->middleware('permission:payment_methods.index.view')->name('payment_methods.index');
        Route::post('medios-pago', [PaymentMethodController::class, 'store'])->middleware('permission:payment_methods.index.create')->name('payment_methods.store');
        Route::post('medios-pago/orden', [PaymentMethodController::class, 'reorder'])->middleware('permission:payment_methods.index.edit')->name('payment_methods.reorder');
        Route::get('medios-pago/pasarelas', [GatewayAccountController::class, 'index'])->middleware('permission:payment_methods.gateways.view')->name('payment_methods.gateways.index');
        Route::post('medios-pago/pasarelas', [GatewayAccountController::class, 'store'])->middleware('permission:payment_methods.gateways.edit')->name('payment_methods.gateways.store');
        Route::put('medios-pago/pasarelas/{account}', [GatewayAccountController::class, 'update'])->middleware('permission:payment_methods.gateways.edit')->name('payment_methods.gateways.update');
        Route::post('medios-pago/pasarelas/{account}/probar', [GatewayAccountController::class, 'test'])->middleware(['permission:payment_methods.gateways.test', 'throttle:10,1'])->name('payment_methods.gateways.test');
        Route::post('medios-pago/pasarelas/{account}/token', [GatewayAccountController::class, 'rotate'])->middleware('permission:payment_methods.gateways.edit')->name('payment_methods.gateways.rotate');
        Route::patch('medios-pago/pasarelas/{account}/estado', [GatewayAccountController::class, 'toggle'])->middleware('permission:payment_methods.gateways.edit')->name('payment_methods.gateways.toggle');
        Route::get('medios-pago/datafonos', [TerminalController::class, 'index'])->middleware('permission:payment_methods.terminals.view')->name('payment_methods.terminals.index');
        Route::post('medios-pago/datafonos', [TerminalController::class, 'store'])->middleware('permission:payment_methods.terminals.edit')->name('payment_methods.terminals.store');
        Route::put('medios-pago/datafonos/{terminal}', [TerminalController::class, 'update'])->middleware('permission:payment_methods.terminals.edit')->name('payment_methods.terminals.update');
        Route::post('medios-pago/datafonos/{terminal}/prueba', [TerminalController::class, 'test'])->middleware(['permission:payment_methods.terminals.edit', 'throttle:10,1'])->name('payment_methods.terminals.test');
        Route::get('medios-pago/transacciones', [TransactionController::class, 'index'])->middleware('permission:payment_methods.transactions.view')->name('payment_methods.transactions.index');
        Route::get('medios-pago/transacciones/{transaction}', [TransactionController::class, 'show'])->middleware('permission:payment_methods.transactions.view')->name('payment_methods.transactions.show');
        Route::post('medios-pago/transacciones/{transaction}/conciliar', [TransactionController::class, 'reconcile'])->middleware('permission:payment_methods.transactions.reconcile')->name('payment_methods.transactions.reconcile');
        Route::put('medios-pago/{method}', [PaymentMethodController::class, 'update'])->middleware('permission:payment_methods.index.edit')->name('payment_methods.update');
        Route::patch('medios-pago/{method}/estado', [PaymentMethodController::class, 'toggle'])->middleware('permission:payment_methods.index.edit')->name('payment_methods.toggle');
        Route::delete('medios-pago/{method}', [PaymentMethodController::class, 'destroy'])->middleware('permission:payment_methods.index.delete')->name('payment_methods.destroy');

        // Plazos de pago de la empresa: los usan el credito de clientes, los proveedores y las compras.
        Route::get('plazos-pago', [PaymentTermController::class, 'index'])->middleware('permission:payment_terms.index.view')->name('payment_terms.index');
        Route::post('plazos-pago', [PaymentTermController::class, 'store'])->middleware('permission:payment_terms.index.create')->name('payment_terms.store');
        Route::put('plazos-pago/{term}', [PaymentTermController::class, 'update'])->middleware('permission:payment_terms.index.edit')->name('payment_terms.update');
        Route::post('plazos-pago/{term}/estado', [PaymentTermController::class, 'toggle'])->middleware('permission:payment_terms.index.deactivate')->name('payment_terms.toggle');

        // Empleados
        Route::get('empleados', [EmployeeController::class, 'index'])->middleware('permission:employees.index.view')->name('employees.index');
        Route::get('empleados/exportar', [EmployeeController::class, 'export'])->middleware('permission:employees.index.export')->name('employees.export');
        Route::get('empleados/importar', [ImportController::class, 'form'])->defaults('module', 'employees')->middleware('permission:employees.index.import')->name('employees.import.form');
        Route::post('empleados/importar', [ImportController::class, 'store'])->defaults('module', 'employees')->middleware('permission:employees.index.import')->name('employees.import');
        Route::get('empleados/crear', [EmployeeController::class, 'create'])->middleware('permission:employees.index.create')->name('employees.create');
        Route::post('empleados', [EmployeeController::class, 'store'])->middleware('permission:employees.index.create')->name('employees.store');
        Route::get('empleados/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.show.view')->name('employees.show');
        Route::get('empleados/{employee}/editar', [EmployeeController::class, 'edit'])->middleware('permission:employees.index.edit')->name('employees.edit');
        Route::put('empleados/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.index.edit')->name('employees.update');
        Route::delete('empleados/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:employees.index.delete')->name('employees.destroy');
        Route::patch('empleados/{employee}/desactivar', [EmployeeController::class, 'deactivate'])->middleware('permission:employees.index.deactivate')->name('employees.deactivate');
        Route::patch('empleados/{employee}/reactivar', [EmployeeController::class, 'reactivate'])->middleware('permission:employees.index.reactivate')->name('employees.reactivate');

        Route::post('empleados/{employee}/acceso', [EmployeeAccessController::class, 'store'])->middleware('permission:employees.access.create')->name('employees.access.store');
        Route::patch('empleados/{employee}/acceso/estado', [EmployeeAccessController::class, 'toggle'])->middleware('permission:employees.access.toggle')->name('employees.access.toggle');
        Route::patch('empleados/{employee}/acceso/rol', [EmployeeAccessController::class, 'changeRole'])->middleware('permission:employees.access.change_role')->name('employees.access.change_role');
        Route::post('empleados/{employee}/acceso/clave', [EmployeeAccessController::class, 'resetPassword'])->middleware('permission:employees.access.reset_password')->name('employees.access.reset_password');
        Route::post('empleados/{employee}/acceso/pin', [EmployeeAccessController::class, 'resetPin'])->middleware('permission:employees.access.reset_pin')->name('employees.access.reset_pin');

        // Usuarios
        Route::get('usuarios', [UserController::class, 'index'])->middleware('permission:users.index.view')->name('users.index');
        Route::get('usuarios/crear', [UserController::class, 'create'])->middleware('permission:users.index.create')->name('users.create');
        Route::post('usuarios', [UserController::class, 'store'])->middleware('permission:users.index.create')->name('users.store');
        Route::get('usuarios/{user}', [UserController::class, 'show'])->middleware('permission:users.show.view')->name('users.show');
        Route::get('usuarios/{user}/editar', [UserController::class, 'edit'])->middleware('permission:users.index.edit')->name('users.edit');
        Route::put('usuarios/{user}', [UserController::class, 'update'])->middleware('permission:users.index.edit')->name('users.update');
        Route::delete('usuarios/{user}', [UserController::class, 'destroy'])->middleware('permission:users.index.delete')->name('users.destroy');
        Route::patch('usuarios/{user}/estado', [UserController::class, 'toggle'])->middleware('permission:users.index.toggle')->name('users.toggle');
        Route::get('usuarios/{user}/permisos', [UserController::class, 'permissions'])->middleware('permission:users.edit.permissions')->name('users.permissions');
        Route::put('usuarios/{user}/permisos', [UserController::class, 'updatePermissions'])->middleware('permission:users.edit.permissions')->name('users.permissions.update');
    });
});

/*
 * Plataforma: solo el super usuario. Las rutas llevan `super.admin` y su permiso del catalogo;
 * no pasan por `company.selected` porque trabajan sobre todas las empresas.
 */
Route::middleware(['auth', 'verified.company', 'force.password.change', 'super.admin'])->prefix('plataforma')->group(function () {
    Route::post('empresa-activa', [ActiveCompanyController::class, 'store'])->middleware(['permission:companies.index.switch', 'throttle:60,1'])->name('platform.active-company');

    // Empresas. Las fijas (`exportar`, `crear`) antes de las que llevan {company}.
    Route::get('empresas', [CompanyController::class, 'index'])->middleware('permission:companies.index.view')->name('companies.index');
    Route::get('empresas/exportar', [CompanyController::class, 'export'])->middleware('permission:companies.index.view')->name('companies.export');
    Route::get('empresas/crear', [CompanyController::class, 'create'])->middleware('permission:companies.index.create')->name('companies.create');
    Route::post('empresas', [CompanyController::class, 'store'])->middleware('permission:companies.index.create')->name('companies.store');
    Route::get('empresas/{company}/editar', [CompanyController::class, 'edit'])->middleware('permission:companies.index.edit')->name('companies.edit');
    Route::put('empresas/{company}', [CompanyController::class, 'update'])->middleware('permission:companies.index.edit')->name('companies.update');
    Route::post('empresas/{company}/estado', [CompanyController::class, 'status'])->middleware('permission:companies.index.delete')->name('companies.status');
    Route::post('empresas/{company}/entrar', [CompanyController::class, 'enter'])->middleware('permission:companies.index.switch')->name('companies.enter');
    Route::post('empresas/{company}/pagos', [CompanyController::class, 'recordPayment'])->middleware('permission:companies.index.edit')->name('companies.payments.store');
    // Plan y funciones: excepciones al plan y precio especial, con vigencia y motivo.
    Route::post('empresas/{company}/excepciones', [CompanyEntitlementController::class, 'store'])->middleware('permission:companies.index.entitlements')->name('companies.entitlements.store');
    Route::post('empresas/{company}/excepciones/{exception}/terminar', [CompanyEntitlementController::class, 'end'])->middleware('permission:companies.index.entitlements')->name('companies.entitlements.end');
    Route::post('empresas/{company}/precio-especial', [CompanyEntitlementController::class, 'storePrice'])->middleware('permission:companies.index.entitlements')->name('companies.prices.store');
    Route::post('empresas/{company}/precio-especial/{price}/terminar', [CompanyEntitlementController::class, 'endPrice'])->middleware('permission:companies.index.entitlements')->name('companies.prices.end');

    // Usuarios, en dos pestañas: de plataforma (super usuarios, sin empresa ni sede) y de empresas
    // (todas las cuentas de todas las empresas). Se crean y editan en una ventana del listado, por
    // eso no hay pantallas de crear ni de editar. Las de empresas van antes de las que llevan {user}.
    Route::get('usuarios/empresas', [PlatformCompanyUserController::class, 'index'])->middleware('permission:platform.company_users.view')->name('platform.company_users.index');
    Route::get('usuarios/empresas/opciones/{company}', [PlatformCompanyUserController::class, 'options'])->middleware('permission:platform.company_users.create|platform.company_users.edit')->name('platform.company_users.options');
    Route::post('usuarios/empresas', [PlatformCompanyUserController::class, 'store'])->middleware('permission:platform.company_users.create')->name('platform.company_users.store');
    Route::put('usuarios/empresas/{user}', [PlatformCompanyUserController::class, 'update'])->middleware('permission:platform.company_users.edit')->name('platform.company_users.update');
    Route::post('usuarios/empresas/{user}/estado', [PlatformCompanyUserController::class, 'toggle'])->middleware('permission:platform.company_users.deactivate')->name('platform.company_users.toggle');
    Route::post('usuarios/empresas/{user}/clave', [PlatformCompanyUserController::class, 'resetPassword'])->middleware(['permission:platform.company_users.reset_password', 'throttle:20,1'])->name('platform.company_users.reset_password');
    Route::post('usuarios/empresas/{user}/permisos', [PlatformCompanyUserController::class, 'permissions'])->middleware('permission:companies.index.switch')->name('platform.company_users.permissions');

    Route::get('usuarios', [PlatformUserController::class, 'index'])->middleware('permission:platform.users.view')->name('platform.users.index');
    Route::post('usuarios', [PlatformUserController::class, 'store'])->middleware('permission:platform.users.create')->name('platform.users.store');
    Route::put('usuarios/{user}', [PlatformUserController::class, 'update'])->middleware('permission:platform.users.edit')->name('platform.users.update');
    Route::post('usuarios/{user}/estado', [PlatformUserController::class, 'toggle'])->middleware('permission:platform.users.deactivate')->name('platform.users.toggle');
    Route::post('usuarios/{user}/clave', [PlatformUserController::class, 'resetPassword'])->middleware(['permission:platform.users.reset_password', 'throttle:20,1'])->name('platform.users.reset_password');

    // Planes y ciclos de la membresia.
    Route::get('planes', [MembershipPlanController::class, 'index'])->middleware('permission:platform.plans.view')->name('platform.plans.index');
    Route::get('planes/crear', [MembershipPlanController::class, 'create'])->middleware('permission:platform.plans.create')->name('platform.plans.create');
    Route::post('planes', [MembershipPlanController::class, 'store'])->middleware('permission:platform.plans.create')->name('platform.plans.store');
    // Los destacados que se proponen a partir de los modulos que trae lo marcado en el editor.
    Route::post('planes/destacados', [MembershipPlanController::class, 'highlights'])->middleware(['permission:platform.plans.create|platform.plans.edit', 'throttle:60,1'])->name('platform.plans.highlights');
    Route::get('planes/{plan}/editar', [MembershipPlanController::class, 'edit'])->middleware('permission:platform.plans.edit')->name('platform.plans.edit');
    Route::put('planes/{plan}', [MembershipPlanController::class, 'update'])->middleware('permission:platform.plans.edit')->name('platform.plans.update');
    Route::post('planes/{plan}/estado', [MembershipPlanController::class, 'toggle'])->middleware('permission:platform.plans.deactivate')->name('platform.plans.toggle');
    Route::delete('planes/{plan}', [MembershipPlanController::class, 'destroy'])->middleware('permission:platform.plans.deactivate')->name('platform.plans.destroy');

    Route::get('ciclos', [BillingCycleController::class, 'index'])->middleware('permission:platform.billing_cycles.view')->name('platform.billing_cycles.index');
    Route::post('ciclos', [BillingCycleController::class, 'store'])->middleware('permission:platform.billing_cycles.create')->name('platform.billing_cycles.store');
    Route::put('ciclos/{cycle}', [BillingCycleController::class, 'update'])->middleware('permission:platform.billing_cycles.edit')->name('platform.billing_cycles.update');
    Route::post('ciclos/{cycle}/estado', [BillingCycleController::class, 'toggle'])->middleware('permission:platform.billing_cycles.deactivate')->name('platform.billing_cycles.toggle');

    // Clasificaciones de empresa (alimentos, textiles, belleza…): la landing agrupa por aqui.
    Route::get('clasificaciones', [BusinessCategoryController::class, 'index'])->middleware('permission:platform.business_categories.view')->name('platform.business_categories.index');
    Route::post('clasificaciones', [BusinessCategoryController::class, 'store'])->middleware('permission:platform.business_categories.create')->name('platform.business_categories.store');
    Route::put('clasificaciones/{category}', [BusinessCategoryController::class, 'update'])->middleware('permission:platform.business_categories.edit')->name('platform.business_categories.update');
    Route::post('clasificaciones/{category}/estado', [BusinessCategoryController::class, 'toggle'])->middleware('permission:platform.business_categories.deactivate')->name('platform.business_categories.toggle');

    // Carga masiva (CSV). Las fijas antes de las que llevan {batch}.
    Route::get('carga-masiva', [PlatformImportController::class, 'index'])->middleware('permission:platform.imports.view')->name('platform.imports.index');
    Route::get('carga-masiva/plantillas', [PlatformImportController::class, 'templates'])->middleware('permission:platform.imports.view')->name('platform.imports.templates');
    Route::get('carga-masiva/plantillas/{type}', [PlatformImportController::class, 'template'])->middleware('permission:platform.imports.view')->name('platform.imports.template');
    Route::post('carga-masiva/vista-previa', [PlatformImportController::class, 'preview'])->middleware(['permission:platform.imports.run', 'throttle:20,1'])->name('platform.imports.preview');
    Route::post('carga-masiva/{batch}/procesar', [PlatformImportController::class, 'process'])->middleware('permission:platform.imports.run')->name('platform.imports.process');
    Route::delete('carga-masiva/{batch}', [PlatformImportController::class, 'discard'])->middleware('permission:platform.imports.run')->name('platform.imports.discard');
    Route::get('carga-masiva/{batch}/original', [PlatformImportController::class, 'original'])->middleware('permission:platform.imports.view')->name('platform.imports.original');
    Route::get('carga-masiva/{batch}/errores', [PlatformImportController::class, 'errors'])->middleware('permission:platform.imports.view')->name('platform.imports.errors');

    // Solicitudes de contacto y contrataciones que alguien tiene que mirar.
    Route::get('solicitudes', [LeadController::class, 'index'])->middleware('permission:platform.leads.view')->name('platform.leads.index');
    Route::put('solicitudes/{lead}', [LeadController::class, 'update'])->middleware('permission:platform.leads.manage')->name('platform.leads.update');

    // Plantillas de tienda: el diseño con que nacen las tiendas. Las fijas antes de {template}.
    Route::get('plantillas-tienda', [StoreTemplateController::class, 'index'])->middleware('permission:platform.store_templates.view')->name('platform.store_templates.index');
    Route::post('plantillas-tienda', [StoreTemplateController::class, 'store'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.store');
    Route::get('plantillas-tienda/medios', [PlatformMediaController::class, 'index'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.media.index');
    Route::post('plantillas-tienda/medios', [PlatformMediaController::class, 'store'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.media.store');
    Route::post('plantillas-tienda/medios/videos', [PlatformMediaController::class, 'storeVideo'])->middleware(['permission:platform.store_templates.edit', 'throttle:10,1'])->name('platform.store_templates.media.store-video');
    Route::get('plantillas-tienda/{template}/editar', [StoreTemplateController::class, 'edit'])->middleware('permission:platform.store_templates.view')->name('platform.store_templates.edit');
    Route::patch('plantillas-tienda/{template}', [StoreTemplateController::class, 'update'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.update');
    Route::get('plantillas-tienda/{template}/vista-previa', [StoreTemplateController::class, 'preview'])->middleware('permission:platform.store_templates.view')->name('platform.store_templates.preview');
    Route::patch('plantillas-tienda/{template}/tema', [StoreTemplateDraftController::class, 'theme'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.theme');
    Route::patch('plantillas-tienda/{template}/ajustes/{key}', [StoreTemplateDraftController::class, 'settings'])->where('key', '[a-z_]+')->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.settings');
    Route::post('plantillas-tienda/{template}/bloques', [StoreTemplateDraftController::class, 'storeBlock'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.blocks.store');
    Route::post('plantillas-tienda/{template}/bloques/orden', [StoreTemplateDraftController::class, 'reorderBlocks'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.blocks.reorder');
    Route::patch('plantillas-tienda/{template}/bloques/{uuid}', [StoreTemplateDraftController::class, 'updateBlock'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.blocks.update');
    Route::post('plantillas-tienda/{template}/bloques/{uuid}/duplicar', [StoreTemplateDraftController::class, 'duplicateBlock'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.blocks.duplicate');
    Route::delete('plantillas-tienda/{template}/bloques/{uuid}', [StoreTemplateDraftController::class, 'destroyBlock'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.blocks.destroy');
    Route::post('plantillas-tienda/{template}/publicar', [StoreTemplateController::class, 'publish'])->middleware('permission:platform.store_templates.publish')->name('platform.store_templates.publish');
    Route::get('plantillas-tienda/{template}/historial', [StoreTemplateController::class, 'history'])->middleware('permission:platform.store_templates.view')->name('platform.store_templates.history');
    Route::post('plantillas-tienda/{template}/historial/{version}/revertir', [StoreTemplateController::class, 'restore'])->middleware('permission:platform.store_templates.publish')->name('platform.store_templates.restore');
    Route::post('plantillas-tienda/{template}/por-defecto', [StoreTemplateController::class, 'setDefault'])->middleware('permission:platform.store_templates.publish')->name('platform.store_templates.default');
    Route::post('plantillas-tienda/{template}/estado', [StoreTemplateController::class, 'toggle'])->middleware('permission:platform.store_templates.publish')->name('platform.store_templates.toggle');
    Route::post('plantillas-tienda/{template}/duplicar', [StoreTemplateController::class, 'duplicate'])->middleware('permission:platform.store_templates.edit')->name('platform.store_templates.duplicate');

    // Landing publica: editor por bloques, ajustes, publicar e historial.
    Route::get('landing', [LandingAdminController::class, 'index'])->middleware('permission:platform.landing.view')->name('platform.landing.index');
    Route::get('landing/vista-previa', [LandingAdminController::class, 'preview'])->middleware('permission:platform.landing.view')->name('platform.landing.preview');
    Route::post('landing/bloques', [LandingAdminController::class, 'store'])->middleware('permission:platform.landing.edit')->name('platform.landing.blocks.store');
    Route::post('landing/orden', [LandingAdminController::class, 'reorder'])->middleware('permission:platform.landing.edit')->name('platform.landing.blocks.reorder');
    Route::patch('landing/bloques/{block:uuid}', [LandingAdminController::class, 'update'])->middleware('permission:platform.landing.edit')->name('platform.landing.blocks.update');
    Route::post('landing/bloques/{block:uuid}/duplicar', [LandingAdminController::class, 'duplicate'])->middleware('permission:platform.landing.edit')->name('platform.landing.blocks.duplicate');
    Route::patch('landing/bloques/{block:uuid}/visible', [LandingAdminController::class, 'visibility'])->middleware('permission:platform.landing.edit')->name('platform.landing.blocks.visibility');
    Route::delete('landing/bloques/{block:uuid}', [LandingAdminController::class, 'destroy'])->middleware('permission:platform.landing.edit')->name('platform.landing.blocks.destroy');
    Route::post('landing/imagenes', [LandingAdminController::class, 'upload'])->middleware(['permission:platform.landing.edit', 'throttle:30,1'])->name('platform.landing.upload');
    Route::post('landing/videos', [LandingAdminController::class, 'uploadVideo'])->middleware(['permission:platform.landing.edit', 'throttle:10,1'])->name('platform.landing.upload-video');
    Route::put('landing/ajustes', [LandingAdminController::class, 'updateSettings'])->middleware('permission:platform.landing.edit')->name('platform.landing.settings');
    Route::post('landing/publicar', [LandingAdminController::class, 'publish'])->middleware('permission:platform.landing.publish')->name('platform.landing.publish');
    Route::post('landing/versiones/{version}/restaurar', [LandingAdminController::class, 'restore'])->middleware('permission:platform.landing.publish')->name('platform.landing.restore');

    // Pasarela de pagos.
    Route::get('pasarela', [PaymentGatewayController::class, 'index'])->middleware('permission:platform.gateway.view')->name('platform.gateway.index');
    Route::put('pasarela', [PaymentGatewayController::class, 'update'])->middleware('permission:platform.gateway.edit')->name('platform.gateway.update');
    Route::put('pasarela/{environment}', [PaymentGatewayController::class, 'updateCredentials'])->middleware('permission:platform.gateway.edit')->whereIn('environment', ['sandbox', 'production'])->name('platform.gateway.credentials');
    Route::post('pasarela/{environment}/probar', [PaymentGatewayController::class, 'test'])->middleware(['permission:platform.gateway.view', 'throttle:10,1'])->whereIn('environment', ['sandbox', 'production'])->name('platform.gateway.test');

    // Pasarelas para empresas: que proveedores pueden conectar las empresas a SU cuenta. No es la
    // pasarela de arriba, que es la cuenta con que la plataforma cobra las membresias.
    Route::get('pasarelas-empresas', [CompanyGatewayProvidersController::class, 'index'])->middleware('permission:platform.gateway.view')->name('platform.company_gateways.index');
    Route::put('pasarelas-empresas', [CompanyGatewayProvidersController::class, 'update'])->middleware('permission:platform.gateway.edit')->name('platform.company_gateways.update');

    // Mapas: el proveedor del buscador de la landing y del formulario de sedes.
    Route::get('mapas', [MapSettingsController::class, 'index'])->middleware('permission:platform.maps.view')->name('platform.maps.index');
    Route::put('mapas', [MapSettingsController::class, 'update'])->middleware('permission:platform.maps.edit')->name('platform.maps.update');
    Route::post('mapas/probar', [MapSettingsController::class, 'preview'])->middleware(['permission:platform.maps.edit', 'throttle:30,1'])->name('platform.maps.preview');
});

// Eventos de la pasarela: publica, sin sesion y fuera de CSRF (bootstrap/app.php). La firma
// y la relectura en la API son la seguridad.
Route::post('webhooks/wompi', WompiWebhookController::class)->middleware('throttle:120,1')->name('webhooks.wompi');

// Eventos de las pasarelas de las EMPRESAS, una URL por cuenta (el token la identifica). Fuera de
// CSRF. ePayco puede confirmar por GET o por POST.
Route::match(['get', 'post'], 'webhooks/pagos/{provider}/{token}', PaymentWebhookController::class)
    ->where(['provider' => 'wompi|mercadopago|payu|epayco|bold', 'token' => '[A-Za-z0-9]{20,64}'])
    ->middleware('throttle:240,1')
    ->name('webhooks.payments');

// La pagina a la que vuelve el celular del cliente despues de pagar un QR o link de la caja.
Route::get('pago/{reference}', PublicPaymentController::class)->where('reference', 'PAY-[A-Z0-9]+')->middleware('throttle:60,1')->name('payments.public');

require __DIR__.'/auth.php';
require __DIR__.'/storefront.php';
