<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una corrida de la carga masiva de plataforma, con su resultado y sus archivos.
 */
class PlatformImportBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Por procesar',
        self::STATUS_PROCESSING => 'Procesando',
        self::STATUS_COMPLETED => 'Completa',
        self::STATUS_PARTIAL => 'Con errores',
        self::STATUS_FAILED => 'Fallida',
    ];

    protected $fillable = [
        'user_id', 'company_id', 'type', 'original_filename', 'stored_path', 'status',
        'rows_total', 'rows_success', 'rows_failed', 'error_report_path', 'message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'rows_total' => 'integer',
            'rows_success' => 'integer',
            'rows_failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
