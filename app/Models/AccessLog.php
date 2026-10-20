<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessLog extends Model
{
    public const ACTION_LOGIN = 'login';

    public const ACTION_LOGOUT = 'logout';

    public const ACTION_LOGIN_FAILED = 'login_failed';

    public const ACTION_PERMISSION_DENIED = 'permission_denied';

    /** Tenia el permiso, pero el plan de su empresa no lo incluye. */
    public const ACTION_PLAN_DENIED = 'plan_denied';

    protected $fillable = ['company_id', 'user_id', 'action', 'ip', 'user_agent', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function log(string $action, ?int $userId = null, array $context = [], ?int $companyId = null): self
    {
        $request = request();

        return self::create([
            'company_id' => $companyId ?? auth('web')->user()?->company_id,
            'user_id' => $userId ?? auth('web')->id(),
            'action' => $action,
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 500),
            'context' => $context ?: null,
        ]);
    }
}
