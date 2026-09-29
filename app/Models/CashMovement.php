<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use HasFactory;

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    protected $fillable = ['register_session_id', 'type', 'amount', 'reason', 'user_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(RegisterSession::class, 'register_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
