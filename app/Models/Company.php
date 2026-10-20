<?php

namespace App\Models;

use App\Services\Membership\Entitlement;
use App\Services\Membership\Entitlements;
use App\Services\Membership\EntitlementService;
use App\Services\Membership\MembershipFeatures;
use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Una empresa cliente de la plataforma.
 *
 * El estado de la membresia lo mueve `MembershipService` (y el comando `membership:process`),
 * nunca un update suelto: cada cambio deja su renglon en la bitacora.
 */
class Company extends Model
{
    use HasFactory;

    public const MEMBERSHIP_TRIAL = 'trial';

    public const MEMBERSHIP_ACTIVE = 'active';

    public const MEMBERSHIP_GRACE = 'grace';

    public const MEMBERSHIP_SUSPENDED = 'suspended';

    public const MEMBERSHIP_CANCELLED = 'cancelled';

    public const MEMBERSHIP_STATUSES = [
        self::MEMBERSHIP_TRIAL,
        self::MEMBERSHIP_ACTIVE,
        self::MEMBERSHIP_GRACE,
        self::MEMBERSHIP_SUSPENDED,
        self::MEMBERSHIP_CANCELLED,
    ];

    public const MEMBERSHIP_LABELS = [
        self::MEMBERSHIP_TRIAL => 'En prueba',
        self::MEMBERSHIP_ACTIVE => 'Activa',
        self::MEMBERSHIP_GRACE => 'En gracia',
        self::MEMBERSHIP_SUSPENDED => 'Suspendida',
        self::MEMBERSHIP_CANCELLED => 'Cancelada',
    ];

    protected $fillable = [
        'name', 'slug', 'legal_name', 'tax_id', 'email', 'phone', 'address', 'city', 'business_category_id', 'logo', 'currency', 'is_active',
        'membership_plan_id', 'billing_cycle_id', 'next_membership_plan_id', 'next_billing_cycle_id',
        'membership_status', 'membership_started_at', 'membership_ends_at', 'grace_ends_at', 'next_charge_at',
        'auto_renew', 'show_on_landing', 'deactivated_at', 'deactivation_reason', 'signup_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_renew' => 'boolean',
            'show_on_landing' => 'boolean',
            'membership_started_at' => 'datetime',
            'membership_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'next_charge_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * Toda empresa nace con su `slug`: es la direccion de su tienda en linea. Va aqui y no en
     * cada formulario porque las empresas nacen por varios caminos (Empresas, la contratacion
     * en linea, la carga masiva, los seeders).
     */
    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (empty($company->slug)) {
                $company->slug = self::uniqueSlug((string) $company->name);
            }
        });

        // Toda empresa nace con sus medios de pago, como toda sede nace con su bodega: una empresa
        // sin «Efectivo» revienta el primer cobro. Cubre Empresas, la contratacion, la carga masiva
        // y las semillas, que crean empresas por caminos distintos.
        static::created(function (Company $company) {
            app(PaymentMethodCatalog::class)->ensureDefaults((int) $company->id);
        });

        // Cambiar de plan cambia lo que la empresa tiene en esta misma peticion.
        static::saved(function (Company $company) {
            if ($company->wasChanged('membership_plan_id')) {
                app(EntitlementService::class)->forget((int) $company->id);
            }
        });
    }

    public function entitlementExceptions(): HasMany
    {
        return $this->hasMany(CompanyEntitlement::class)->latest('id');
    }

    public function planPrices(): HasMany
    {
        return $this->hasMany(CompanyPlanPrice::class)->latest('id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Alimentos, textiles, belleza…: con ella la landing agrupa las tiendas. */
    public function businessCategory(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class, 'billing_cycle_id');
    }

    public function nextPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'next_membership_plan_id');
    }

    public function nextCycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class, 'next_billing_cycle_id');
    }

    public function membershipEvents(): HasMany
    {
        return $this->hasMany(CompanyMembershipEvent::class)->latest('id');
    }

    public function membershipCharges(): HasMany
    {
        return $this->hasMany(MembershipCharge::class)->latest('id');
    }

    public function paymentMethod(): HasOne
    {
        return $this->hasOne(CompanyPaymentMethod::class);
    }

    public function isSuspended(): bool
    {
        return $this->membership_status === self::MEMBERSHIP_SUSPENDED || $this->membership_status === self::MEMBERSHIP_CANCELLED;
    }

    public function membershipLabel(): string
    {
        return self::MEMBERSHIP_LABELS[$this->membership_status] ?? $this->membership_status;
    }

    /**
     * Atajo para las funciones de antes: traduce la funcion a su nodo y le pregunta a
     * `EntitlementService` (`online_store` → `storefront.*`, `promotions` → `promotions.*`,
     * `multi_warehouse` → `stores.warehouses.create`, `catalog_import` → `products.catalog_import.*`).
     * Sin plan asignado (empresas de antes de las membresias) se tiene todo.
     */
    public function hasFeature(string $feature): bool
    {
        $node = Entitlements::FEATURE_NODES[$feature] ?? null;

        if ($node === null) {
            return false;
        }

        return $this->entitlement()->allowsNode($node);
    }

    /** Lo que la empresa tiene, ya calculado con su plan y sus excepciones. */
    public function entitlement(): Entitlement
    {
        return app(EntitlementService::class)->for($this);
    }

    /**
     * Las funciones viejas que tiene la empresa, para lo que todavia pregunta por ellas.
     *
     * @return list<string>
     */
    public function features(): array
    {
        return array_values(array_filter(array_keys(MembershipFeatures::CATALOGUE), fn (string $feature) => $this->hasFeature($feature)));
    }

    /**
     * Un `slug` libre a partir del nombre, para la direccion de la tienda.
     */
    public const RESERVED_SLUGS = ['producto', 'servicio', 'pedido', 'pedidos', 'preview', 'carrito', 'pago', 'registro', 'ingresar', 'salir', 'sede', 'activar', 'categoria', 'ofertas', 'buscar', 'devoluciones', 'menu', 'articulo', 'newsletter'];

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($name) ?: 'empresa', 70, '');
        $slug = $base;
        $suffix = 2;

        // Las rutas fijas de la tienda: una empresa llamada «Carrito» dañaria el enlace `/tienda/{empresa}/{tienda}`.
        while (in_array($slug, self::RESERVED_SLUGS, true)
            || self::query()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
