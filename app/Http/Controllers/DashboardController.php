<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Services\Platform\PlatformOverviewService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboard) {}

    public function index(Request $request, PlatformOverviewService $platform): Response
    {
        $companyId = TenantContext::activeCompanyId();

        // El super usuario en «Todas las empresas» ve como va la plataforma.
        if ($companyId === null && $request->user()->isSuperAdmin()) {
            return Inertia::render('Dashboard/PlatformOverview', [
                'overview' => $platform->build(),
            ]);
        }

        $storeId = StoreContext::activeStoreId();

        if ($companyId === null || $storeId === null) {
            return Inertia::render('Dashboard/Index', [
                'data' => null,
                'canSeeTotals' => false,
            ]);
        }

        return Inertia::render('Dashboard/Index', [
            'data' => $this->dashboard->build($companyId, $storeId),
            'canSeeTotals' => $request->user()->can('dashboard.index.sales_totals'),
        ]);
    }
}
