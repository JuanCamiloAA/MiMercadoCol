<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureCompanySelected;
use App\Http\Middleware\EnsureMembershipActive;
use App\Http\Middleware\EnsurePlanFeatures;
use App\Http\Middleware\EnsureCustomerAuthenticated;
use App\Http\Middleware\EnsureStorefrontAvailable;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserBelongsToCompany;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * El guard de los canales es `web`, explicito.
     *
     * Sin esto, un cliente con sesion de la tienda en linea —guard `customer`— llega hasta el
     * callback del canal y revienta el tipo. Los canales son del personal: la tienda no
     * escucha nada de aqui, y la respuesta correcta para ella es 403, no un error del servidor.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['web', 'auth:web']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Los eventos de la pasarela no traen token CSRF: los protege su firma.
        $middleware->validateCsrfTokens(except: ['webhooks/wompi']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'permission' => CheckPermission::class,
            'super.admin' => EnsureSuperAdmin::class,
            'verified.company' => EnsureUserBelongsToCompany::class,
            'force.password.change' => ForcePasswordChange::class,
            'customer.auth' => EnsureCustomerAuthenticated::class,
            'company.selected' => EnsureCompanySelected::class,
            'membership.active' => EnsureMembershipActive::class,
            'plan.features' => EnsurePlanFeatures::class,
            'storefront.available' => EnsureStorefrontAvailable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Lo que nunca se guarda en sesion como «old input» cuando una validacion falla: datos
        // de tarjeta (que ademas se rechazan), tokens de la pasarela y contraseñas.
        $exceptions->dontFlash([
            'card_number', 'number', 'cvc', 'cvv', 'pan', 'token', 'acceptance_token', 'personal_auth_token',
            'admin_password', 'admin_password_confirmation', 'code',
        ]);

        /**
         * Un cajero no puede quedarse mirando una pantalla que diga «419 PAGE EXPIRED».
         *
         * Cuando la sesion caduca —o el servidor se reinicio y se perdieron las sesiones— el
         * tiquete que tenia armado sigue en pantalla: se le avisa y se le pide repetir el
         * cobro, sin sacarlo de la caja.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (! $request->header('X-Inertia')) {
                return $response;
            }

            return match ($response->getStatusCode()) {
                419 => back()->with('error', 'La sesion caduco por inactividad. Vuelve a intentarlo.'),
                403 => inertia('Errors/403', [
                    'message' => $exception->getMessage() ?: 'No tienes permiso para hacer eso.',
                ])->toResponse($request)->setStatusCode(403),
                404 => inertia('Errors/404')->toResponse($request)->setStatusCode(404),
                500, 503 => inertia('Errors/500', [
                    'status' => $response->getStatusCode(),
                ])->toResponse($request)->setStatusCode($response->getStatusCode()),
                default => $response,
            };
        });
    })->create();
