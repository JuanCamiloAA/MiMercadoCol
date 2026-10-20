<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Services\BrandThemeService;
use App\Services\Membership\MembershipOverviewService;
use App\Services\SettingsPreviewService;
use App\Services\SettingsService;
use App\Settings\NavigationOverrides;
use App\Settings\SettingsOptions;
use App\Settings\SettingsSchema;
use App\Support\HomeResolver;
use App\Support\TenantContext;
use App\Support\Uploads;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pantalla de Ajustes. Cada pestana se guarda en su propia ruta (`Settings\*Controller`),
 * con su permiso; aqui solo se arma lo que la pantalla necesita para pintarse.
 */
class SettingController extends Controller
{
    /** Pestanas de la pantalla, en orden, con el permiso que exige guardarlas. */
    public const TABS = [
        'company' => 'settings.index.edit',
        SettingsSchema::TAB_BRAND => 'settings.index.brand',
        SettingsSchema::TAB_APPEARANCE => 'settings.index.brand',
        SettingsSchema::TAB_NAVIGATION => 'settings.index.brand',
        SettingsSchema::TAB_POS => 'settings.index.pos_layout',
        SettingsSchema::TAB_POS_RULES => 'settings.index.edit',
        SettingsSchema::TAB_RECEIPT => 'settings.index.pos_layout',
        SettingsSchema::TAB_CREDIT => 'settings.index.edit',
        SettingsSchema::TAB_CATALOG => 'settings.index.edit',
        // Esta no guarda ajustes: el permiso es el de verla. Pagar pide `manage_payment`.
        'membership' => 'settings.membership.view',
    ];

    public function __construct(
        protected SettingsService $settings,
        protected BrandThemeService $brand,
        protected SettingsPreviewService $previews,
        protected MembershipOverviewService $membership,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $user = $request->user();
        $all = $this->settings->all($companyId);
        $editable = array_flip(array_merge(...array_map(
            fn (string $tab) => SettingsSchema::keysFor($tab),
            array_keys(self::TABS),
        )));

        $stores = Store::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'address', 'phone', 'is_active']);

        return Inertia::render('Settings/Index', [
            'settings' => array_intersect_key($all, $editable),
            // Para «Valores de fabrica»: lo que el sistema trae sin que nadie toque nada.
            'defaults' => array_intersect_key(SettingsService::DEFAULTS, $editable),
            // Que pestanas puede guardar esta persona. Las que no, no se pintan.
            'editable' => array_map(fn (string $permission) => $user->can($permission), self::TABS),
            // La empresa activa, no la del usuario: el super administrador puede estar en otra.
            'company' => Company::query()->find($companyId)?->only(['id', 'name', 'legal_name', 'tax_id', 'email', 'phone', 'address', 'city']),
            'stores' => $stores->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'code' => $store->code,
                'address' => $store->address,
                'phone' => $store->phone,
                'is_active' => (bool) $store->is_active,
            ])->values(),
            // Lo propio de cada sede en el tiquete. Lo que no aparece, lo hereda de la empresa.
            'storeOverrides' => $stores->mapWithKeys(fn (Store $store) => [
                $store->id => (object) $this->settings->overridesFor($companyId, (int) $store->id),
            ]),
            'brandAssets' => $this->brandAssets($all),
            'fontOptions' => $this->brand->fontOptions(),
            'previewTokens' => $this->brand->previewTokens(),
            'options' => SettingsOptions::all(),
            'swatches' => SettingsOptions::SWATCHES,
            'roles' => Role::query()
                ->where('company_id', $companyId)
                ->orderBy('display_name')
                ->get()
                ->map(fn (Role $role) => ['value' => $role->name, 'label' => $role->label()])
                ->values(),
            'homePages' => HomeResolver::options(),
            'lockedNavigation' => NavigationOverrides::LOCKED,
            'receiptLimits' => [
                'max_lines' => SettingsSchema::RECEIPT_HEADER_MAX_LINES,
                'chars' => SettingsSchema::RECEIPT_CHARS_PER_LINE,
            ],
            'receiptSample' => $this->previews->receiptSample($companyId),
            'posSample' => $this->previews->posSample($companyId),
            // Solo si la puede ver: consulta cobros y topes que el resto de pestañas no necesita.
            'membership' => fn () => $user->can('settings.membership.view') && ($company = TenantContext::company())
                ? $this->membership->build($company, $user)
                : null,
        ]);
    }

    /**
     * Las imagenes de marca como direcciones. El valor guardado trae rutas del disco.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string|null>
     */
    protected function brandAssets(array $settings): array
    {
        $url = fn (string $key) => is_array($settings[$key] ?? null) && is_string($settings[$key]['path'] ?? null)
            ? Uploads::url($settings[$key]['path'])
            : null;

        return [
            'mark' => $url('brand.mark'),
            'logo_light' => $url('brand.logo_light'),
            'logo_dark' => $url('brand.logo_dark'),
            'logo_receipt' => $url('brand.logo_receipt'),
        ];
    }
}
