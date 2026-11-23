<?php

namespace App\Models;

use App\Services\EffectivePermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'company_id',
        'employee_id',
        'name',
        'last_name',
        'email',
        'document',
        'password',
        'avatar',
        'phone',
        'job_title',
        'pos_pin',
        'is_active',
        'password_change_required',
        'password_changed_at',
        'last_login_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'pos_pin', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pos_pin' => 'hashed',
            'is_active' => 'boolean',
            'password_change_required' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class)->withTimestamps();
    }

    public function registerSessions(): HasMany
    {
        return $this->hasMany(RegisterSession::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @var list<string>|null */
    protected ?array $effectivePermissionNamesCache = null;

    /**
     * La peticion para la que se calculo. Los permisos dependen del plan de la empresa, que puede
     * cambiar entre una peticion y otra aunque el objeto del usuario sea el mismo.
     */
    protected ?object $effectivePermissionNamesRequest = null;

    public function flushEffectivePermissionCache(): void
    {
        $this->effectivePermissionNamesCache = null;
        $this->effectivePermissionNamesRequest = null;
    }

    /**
     * @return list<string>
     */
    public function getEffectivePermissionNames(): array
    {
        $request = app()->bound('request') ? request() : null;

        if ($this->effectivePermissionNamesCache === null || $this->effectivePermissionNamesRequest !== $request) {
            $this->effectivePermissionNamesCache = app(EffectivePermissionService::class)->getEffectivePermissionNames($this);
            $this->effectivePermissionNamesRequest = $request;
        }

        return $this->effectivePermissionNamesCache;
    }

    /**
     * El rol es una plantilla: lo que vale son los permisos directos del usuario.
     *
     * @param  string|int|PermissionContract  $permission
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($permission instanceof PermissionContract) {
            $permission = $permission->getName();
        }

        if (! is_string($permission)) {
            return false;
        }

        $guard = $guardName ?? $this->getDefaultGuardName();

        if (! Permission::query()->where('name', $permission)->where('guard_name', $guard)->exists()) {
            return false;
        }

        return in_array($permission, $this->getEffectivePermissionNames(), true);
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->name ?? '').' '.($this->last_name ?? ''));
    }

    public function getInitialsAttribute(): string
    {
        $initials = '';

        foreach (explode(' ', trim($this->full_name)) as $part) {
            if ($part !== '') {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'U';
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['admin', 'super_admin']);
    }

    public function primaryRole(): ?Role
    {
        /** @var Role|null $role */
        $role = $this->roles->first();

        return $role;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Los super usuarios: el rol global `super_admin`, el mismo que revisa `isSuperAdmin()`. */
    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $roles) => $roles
            ->where('roles.name', 'super_admin')
            ->whereNull('roles.company_id'));
    }

    /**
     * Paginas que puede abrir, en formato `modulo.pagina`. El menu se pinta con esto.
     *
     * @return list<string>
     */
    public function getAccessiblePages(): array
    {
        $pages = [];

        foreach ($this->getEffectivePermissionNames() as $permission) {
            $parts = explode('.', $permission);

            if (count($parts) === 3 && $parts[2] === 'view') {
                $pages[] = $parts[0].'.'.$parts[1];
            }
        }

        return array_values(array_unique($pages));
    }
}
