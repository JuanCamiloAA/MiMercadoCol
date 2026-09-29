<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentCounter extends Model
{
    protected $fillable = ['company_id', 'store_id', 'key', 'last_number'];

    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}
