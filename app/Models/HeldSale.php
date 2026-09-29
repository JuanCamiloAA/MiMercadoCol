<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeldSale extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'store_id', 'register_session_id', 'user_id', 'customer_id', 'label', 'payload', 'total',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'total' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
