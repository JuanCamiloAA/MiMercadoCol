<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBalance extends Model
{
    protected $fillable = ['customer_id', 'balance', 'overdue_balance', 'oldest_due_date'];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'overdue_balance' => 'integer',
            'oldest_due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
