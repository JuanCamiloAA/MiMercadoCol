<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * El sitio de una sede.
 *
 * Es la bisagra entre el borrador —bloques, tema y ajustes, que el editor toca todo el tiempo—
 * y lo que ve el cliente, que sale del retrato de `published_version_id` y de ningun otro lado.
 */
class Storefront extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'store_id', 'slug', 'custom_domain', 'is_online',
        'published_version_id', 'published_at', 'published_by_user_id', 'template_id', 'follows_template',
    ];

    protected function casts(): array
    {
        return ['is_online' => 'boolean', 'published_at' => 'datetime', 'follows_template' => 'boolean'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function theme(): HasOne
    {
        return $this->hasOne(StorefrontTheme::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(StorefrontBlock::class)->orderBy('sort_order')->orderBy('id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(StorefrontSetting::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StorefrontVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(StorefrontVersion::class, 'published_version_id');
    }

    /** La plantilla de plataforma. Solo manda en el diseño si `follows_template`. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(StorefrontTemplate::class, 'template_id');
    }

    /** Si hoy el diseño lo pone la plantilla: la sigue y la plantilla esta publicada. */
    public function usesTemplate(): bool
    {
        return $this->follows_template && $this->template_id !== null && $this->template?->published !== null;
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function zones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class)->orderBy('sort_order')->orderBy('id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(DeliverySlot::class)->orderBy('weekday')->orderBy('starts_at');
    }

    /** Un ajuste por su clave, con su valor por defecto si todavia no se ha guardado. */
    public function setting(string $key, mixed $default = []): mixed
    {
        return $this->settings->firstWhere('key', $key)?->value ?? $default;
    }
}
