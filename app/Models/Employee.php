<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const DOCUMENT_TYPES = ['CC', 'CE', 'PA'];

    /** @var list<string> */
    public const CONTRACT_TYPES = ['indefinido', 'fijo', 'obra', 'aprendiz'];

    protected $fillable = [
        'company_id', 'store_id', 'first_name', 'last_name', 'document_type', 'document_number',
        'birth_date', 'phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone',
        'position', 'contract_type', 'hired_at', 'terminated_at', 'work_schedule', 'status', 'photo',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hired_at' => 'date',
            'terminated_at' => 'date',
            'work_schedule' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
