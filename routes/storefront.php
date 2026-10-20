<?php

use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\CustomerAuthController;
use App\Http\Controllers\Storefront\CustomerRegisterController;
use App\Http\Controllers\Storefront\CustomerReturnRequestController;
use App\Http\Controllers\Storefront\NewsletterController;
use App\Http\Controllers\Storefront\OnlinePaymentController;
use App\Http\Controllers\Storefront\OrderTrackingController;
use App\Http\Controllers\Storefront\PreviewController;
use App\Http\Controllers\Storefront\StorefrontEntryController;
use Illuminate\Support\Facades\Route;

// Vista previa del borrador: no pide sesion, pide una firma que dura media hora. Va por fuera
// de `storefront.available`: el editor tiene que poder ver una tienda que aun esta apagada.
Route::get('tienda/preview/{store}', [PreviewController::class, 'show'])->name('storefront.preview');

Route::prefix('tienda')->name('storefront.')->middleware('storefront.available')->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('home');

    // Las paginas de catalogo. Van antes de las que llevan parametro y sus nombres estan en
    // `Company::RESERVED_SLUGS`: una empresa llamada «Ofertas» no puede tapar la ruta.
    Route::get('ofertas', [CatalogController::class, 'deals'])->name('deals');
    Route::get('buscar', [CatalogController::class, 'search'])->name('search');
    Route::get('buscar/sugerencias', [CatalogController::class, 'suggestions'])->middleware('throttle:60,1')->name('search.suggestions');
    // El panel del menu de categorias: se pide al pasar el mouse, por eso el tope es mas alto.
    Route::get('menu/categoria/{category}', [CatalogController::class, 'menu'])->whereNumber('category')->middleware('throttle:120,1')->name('menu.category');
    // La categoria se busca por su slug dentro de la empresa de la sede, no con el enlace de
    // modelo: dos empresas pueden tener una «despensa».
    Route::get('categoria/{categorySlug}', [CatalogController::class, 'category'])->where('categorySlug', '[a-z0-9-]+')->name('category');

    Route::post('sede', [CatalogController::class, 'switchStore'])->name('store.switch');
    Route::get('producto/{product}', [CatalogController::class, 'show'])->name('product');
    // Un articulo con subarticulos: una pagina con sus presentaciones para escoger.
    Route::get('articulo/{familySlug}', [CatalogController::class, 'family'])->where('familySlug', '[a-z0-9-]+')->name('family');
    Route::get('articulo/{familySlug}/opciones', [CatalogController::class, 'familyOptions'])->where('familySlug', '[a-z0-9-]+')->middleware('throttle:120,1')->name('family.options');
    Route::get('servicio/{service}', [CatalogController::class, 'service'])->whereNumber('service')->name('service');

    Route::get('carrito', [CartController::class, 'index'])->name('cart.index');
    // Agregar sin recargar: cantidad absoluta, idempotente, en JSON.
    Route::put('carrito/cantidad', [CartController::class, 'quantity'])->middleware('throttle:60,1')->name('cart.quantity');
    Route::get('carrito/detalle', [CartController::class, 'detail'])->name('cart.detail');
    Route::post('carrito', [CartController::class, 'store'])->name('cart.store');
    Route::patch('carrito/{item}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('carrito/{item}', [CartController::class, 'destroy'])->name('cart.destroy');

    // Novedades por correo, desde el pie (si la sede lo encendió). `newsletter` va en `Company::RESERVED_SLUGS`.
    Route::post('newsletter', [NewsletterController::class, 'store'])->middleware('throttle:5,1')->name('newsletter.store');

    // Registro de clientes, en la empresa de la sede elegida. Un documento creado en la caja se
    // activa con un codigo al correo de su ficha.
    Route::get('registro', [CustomerRegisterController::class, 'create'])->name('register');
    Route::post('registro', [CustomerRegisterController::class, 'store'])->middleware('throttle:10,1')->name('register.store');
    Route::get('registro/activar', [CustomerRegisterController::class, 'activation'])->name('register.activation');
    Route::post('registro/activar', [CustomerRegisterController::class, 'activate'])->middleware('throttle:10,1')->name('register.activate');
    Route::post('registro/activar/reenviar', [CustomerRegisterController::class, 'resend'])->middleware('throttle:3,1')->name('register.resend');

    Route::get('ingresar', [CustomerAuthController::class, 'create'])->name('login');
    Route::post('ingresar', [CustomerAuthController::class, 'store'])->name('login.store');
    // Salir siempre se puede, aunque la tienda este cerrada.
    Route::post('salir', [CustomerAuthController::class, 'destroy'])->withoutMiddleware('storefront.available')->name('logout');

    Route::middleware('customer.auth')->group(function () {
        Route::get('pago', [CheckoutController::class, 'create'])->name('checkout.create');
        Route::post('pago', [CheckoutController::class, 'store'])->name('checkout.store');
        Route::post('pago/zona', [CheckoutController::class, 'zone'])->name('checkout.zone');
        Route::post('pago/cupon', [CheckoutController::class, 'applyCoupon'])->name('checkout.coupon.apply');
        Route::delete('pago/cupon', [CheckoutController::class, 'removeCoupon'])->name('checkout.coupon.remove');
        // Pago en linea: ida a la pasarela y vuelta a «confirmando…»; el estado se pregunta.
        Route::get('pago/en-linea/{reference}', [OnlinePaymentController::class, 'show'])->where('reference', 'PAY-[A-Z0-9]+')->name('payments.show');
        Route::get('pago/en-linea/{reference}/estado', [OnlinePaymentController::class, 'status'])->where('reference', 'PAY-[A-Z0-9]+')->middleware('throttle:60,1')->name('payments.status');
        Route::get('pedidos', [OrderTrackingController::class, 'index'])->name('orders.index');
        Route::get('pedido/{order}', [OrderTrackingController::class, 'show'])->name('orders.show');
        // Devoluciones: el cliente las pide sobre un pedido entregado y ve como van.
        Route::get('pedido/{order}/devolucion', [CustomerReturnRequestController::class, 'create'])->name('returns.create');
        Route::post('pedido/{order}/devolucion', [CustomerReturnRequestController::class, 'store'])->middleware('throttle:10,1')->name('returns.store');
        Route::post('devoluciones/{customerReturn}/retirar', [CustomerReturnRequestController::class, 'cancel'])->whereNumber('customerReturn')->name('returns.cancel');
        Route::get('devoluciones/{customerReturn}/fotos/{index}', [CustomerReturnRequestController::class, 'photo'])->whereNumber(['customerReturn', 'index'])->name('returns.photo');
    });
});

// El enlace de cada tienda. Va al final y con dos segmentos: las rutas fijas de arriba
// (`producto/{product}`, `pedido/{order}`...) se resuelven antes, y `Company::uniqueSlug` no
// deja que una empresa se llame como una de ellas.
Route::get('tienda/{companySlug}/{storefrontSlug}', StorefrontEntryController::class)
    ->where(['companySlug' => '[a-z0-9-]+', 'storefrontSlug' => '[a-z0-9-]+'])
    ->name('storefront.entry');
