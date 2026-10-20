<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscription;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tienda en linea › Suscriptores: los correos que los clientes dejaron en el pie de la tienda,
 * con su autorizacion de datos, y su descarga en CSV. Los de la empresa activa (`CompanyScope`).
 */
class StorefrontNewsletterController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Storefront/Newsletter/Index', [
            'subscriptions' => $this->query($filters)
                ->with('store:id,name')
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
                ->through(fn (NewsletterSubscription $subscription) => [
                    'id' => $subscription->id,
                    'email' => $subscription->email,
                    'store' => $subscription->store?->name,
                    'consent_at' => $subscription->consent_at?->toIso8601String(),
                    'created_at' => $subscription->created_at?->toIso8601String(),
                ]),
            'filters' => $filters,
            'stores' => Store::query()->orderBy('name')->get(['id', 'name'])->map(fn (Store $store) => ['value' => (string) $store->id, 'label' => $store->name])->all(),
            'total' => NewsletterSubscription::query()->count(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($this->filters($request))->with('store:id,name')->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');
            // Sin el BOM, Excel se come los acentos.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Correo', 'Sede', 'Autorizo datos', 'Suscrito'], ';');

            $query->chunk(500, function ($chunk) use ($handle) {
                foreach ($chunk as $subscription) {
                    fputcsv($handle, [
                        $subscription->email,
                        $subscription->store?->name ?? '',
                        $subscription->consent_at?->timezone('America/Bogota')->format('Y-m-d H:i') ?? '',
                        $subscription->created_at?->timezone('America/Bogota')->format('Y-m-d H:i') ?? '',
                    ], ';');
                }
            });

            fclose($handle);
        }, 'suscriptores-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{q: string, sede: string}
     */
    protected function filters(Request $request): array
    {
        return ['q' => trim((string) $request->string('q')), 'sede' => (string) $request->string('sede')];
    }

    /**
     * @param  array{q: string, sede: string}  $filters
     * @return Builder<NewsletterSubscription>
     */
    protected function query(array $filters): Builder
    {
        return NewsletterSubscription::query()
            ->when($filters['q'] !== '', fn (Builder $query) => $query->where('email', 'like', '%'.$filters['q'].'%'))
            ->when(ctype_digit($filters['sede']), fn (Builder $query) => $query->where('store_id', (int) $filters['sede']));
    }
}
