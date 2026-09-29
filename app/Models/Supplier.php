<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'name', 'tax_id', 'contact_name', 'phone', 'email', 'address',
        'lead_time_days', 'payment_term_id', 'delivery_days', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'delivery_days' => 'array',
            'is_active' => 'boolean',
            'lead_time_days' => 'integer',
        ];
    }

    public function paymentTerm(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
