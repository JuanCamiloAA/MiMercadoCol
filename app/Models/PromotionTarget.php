<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionTarget extends Model
{
    use HasFactory;

    public const TYPE_PRODUCT = 'product';

    public const TYPE_CATEGORY = 'category';

    /** Un articulo con subarticulos: alcanza a todos sus subarticulos. */
    public const TYPE_FAMILY = 'family';

    protected $fillable = ['promotion_id', 'target_type', 'target_id', 'value', 'quantity'];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'value' => 'integer',
            'quantity' => 'float',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'target_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'target_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'target_id');
    }
}
