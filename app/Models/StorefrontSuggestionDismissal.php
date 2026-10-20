<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una sugerencia de publicar que alguien descarto, en una sede y por un motivo. Deja de valer si
 * el articulo se publica (`StorefrontCatalogManagerController::update` borra sus descartes).
 */
class StorefrontSuggestionDismissal extends Model
{
    use BelongsToCompany;

    /** Se vende en caja y no esta en linea. */
    public const GROUP_POS_SELLERS = 'pos_sellers';

    /** Tiene un lote por vencer. */
    public const GROUP_EXPIRING = 'expiring';

    /** Inventario alto y poca rotacion. */
    public const GROUP_OVERSTOCK = 'overstock';

    /** Le falta foto, descripcion o precio. */
    public const GROUP_INCOMPLETE = 'incomplete';

    public const GROUPS = [self::GROUP_POS_SELLERS, self::GROUP_EXPIRING, self::GROUP_OVERSTOCK, self::GROUP_INCOMPLETE];

    protected $fillable = ['company_id', 'store_id', 'product_id', 'group', 'dismissed_by_user_id', 'dismissed_at'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by_user_id');
    }
}
