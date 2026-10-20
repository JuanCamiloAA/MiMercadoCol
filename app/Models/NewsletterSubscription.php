<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un correo que un cliente dejo en el pie de la tienda para recibir novedades, con su
 * autorizacion de datos. Uno por empresa y correo.
 */
class NewsletterSubscription extends Model
{
    use BelongsToCompany;

    public const SOURCE_FOOTER = 'footer';

    protected $fillable = ['company_id', 'store_id', 'email', 'consent_at', 'source', 'ip'];

    protected function casts(): array
    {
        return ['consent_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
