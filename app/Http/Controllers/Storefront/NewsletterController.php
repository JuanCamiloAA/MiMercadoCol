<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreNewsletterSubscriptionRequest;
use App\Models\NewsletterSubscription;
use App\Services\Storefront\StorefrontConfig;
use App\Support\StorefrontContext;
use Illuminate\Http\JsonResponse;

/**
 * El formulario de novedades del pie de la tienda.
 *
 * Solo recibe correos si la sede lo encendio en su pie publicado: es opcional, y una tienda que
 * no lo pidio no junta correos de nadie. Un correo que ya estaba no se repite y la respuesta es
 * la misma (no se le cuenta a nadie quien esta suscrito).
 */
class NewsletterController extends Controller
{
    public function __construct(protected StorefrontConfig $config) {}

    public function store(StoreNewsletterSubscriptionRequest $request): JsonResponse
    {
        $store = StorefrontContext::requireStore();

        abort_unless((bool) ($this->config->settings($store)['footer']['newsletter'] ?? false), 404, 'Esta tienda no recibe suscripciones.');

        $email = $request->email();

        $subscription = NewsletterSubscription::query()->withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $store->company_id, 'email' => $email],
            [
                'store_id' => $store->id,
                'consent_at' => now(),
                'source' => NewsletterSubscription::SOURCE_FOOTER,
                'ip' => $request->ip(),
            ]
        );

        // Quien vuelve a suscribirse renueva su autorizacion.
        if (! $subscription->wasRecentlyCreated) {
            $subscription->forceFill(['consent_at' => now()])->save();
        }

        return response()->json(['message' => '¡Listo! Te escribiremos a '.$email.'.']);
    }
}
