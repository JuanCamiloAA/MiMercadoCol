<?php

namespace App\Models;

use App\Services\Membership\MembershipFeatures;
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
        'name', 'slug', 'legal_name', 'tax_id', 'email', 'phone', 'address', 'city', 'logo', 'currency', 'is_active',
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
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
     * Si la empresa tiene una funcion del plan. Sin plan asignado (empresas de antes de las
     * membresias) se asume todo: no se le quita a nadie lo que ya usaba.
     */
    public function hasFeature(string $feature): bool
    {
        if ($this->membership_plan_id === null) {
            return true;
        }

        return (bool) $this->plan?->hasFeature($feature);
    }

    /**
     * Las funciones que tiene la empresa. Sin plan, todas.
     *
     * @return list<string>
     */
    public function features(): array
    {
        if ($this->membership_plan_id === null) {
            return array_keys(MembershipFeatures::CATALOGUE);
        }

        return MembershipFeatures::sanitize($this->plan?->features);
    }

    /**
     * Un `slug` libre a partir del nombre, para la direccion de la tienda.
     */
    public const RESERVED_SLUGS = ['producto', 'servicio', 'pedido', 'pedidos', 'preview', 'carrito', 'pago', 'registro', 'ingresar', 'salir', 'sede', 'activar', 'categoria', 'ofertas', 'buscar', 'devoluciones', 'menu', 'articulo'];

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
