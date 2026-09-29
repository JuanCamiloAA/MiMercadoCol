<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontTheme extends Model
{
    use HasFactory;

    /** @var array<string, list<string>> */
    public const OPTIONS = [
        'scheme' => ['light', 'dark', 'auto'],
        'font_pair' => ['moderna', 'editorial', 'cercana', 'mercado'],
        'radius' => ['sharp', 'soft', 'round'],
        'density' => ['compact', 'cozy'],
        'button_style' => ['solid', 'outline'],
    ];

    protected $fillable = [
        'storefront_id', 'logo_media_id', 'favicon_media_id', 'mark_media_id', 'display_name', 'tagline',
        'accent', 'deal_color', 'scheme', 'allow_scheme_toggle', 'font_pair', 'radius', 'density', 'button_style',
    ];

    protected function casts(): array
    {
        return ['allow_scheme_toggle' => 'boolean'];
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'logo_media_id');
    }

    public function favicon(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'favicon_media_id');
    }

    /** El isotipo: la marca en cuadrado, para el encabezado angosto y el icono. */
    public function mark(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'mark_media_id');
    }
}
