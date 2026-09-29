<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una corrida de importacion de catalogo.
 *
 * Nace `pending`, el job la pasa a `processing` y termina en `completed`, `failed` o
 * `cancelled`. Nunca se queda a medias: si el job revienta, `failed()` la cierra con el motivo.
 */
class CatalogImport extends Model
{
    use BelongsToCompany, HasFactory;

    public const SOURCE_API = 'openfoodfacts';

    public const SOURCE_CSV = 'csv';

    /** @var array<string, string> */
    public const SOURCE_LABELS = [
        self::SOURCE_API => 'Open Food Facts',
        self::SOURCE_CSV => 'Archivo CSV',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'En espera',
        self::STATUS_PROCESSING => 'Importando',
        self::STATUS_COMPLETED => 'Terminada',
        self::STATUS_FAILED => 'Fallida',
        self::STATUS_CANCELLED => 'Cancelada',
    ];

    /** Estados de los que ya no se sale: la pantalla deja de preguntar al llegar a uno. */
    public const FINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    protected $fillable = [
        'company_id', 'user_id', 'source', 'criteria', 'status',
        'total_detected', 'total_imported', 'total_updated', 'total_skipped', 'total_failed',
        'error_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'total_detected' => 'integer',
            'total_imported' => 'integer',
            'total_updated' => 'integer',
            'total_skipped' => 'integer',
            'total_failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CatalogImportItem::class);
    }

    /** Las que siguen vivas: en espera o corriendo. */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    /**
     * Cuanto lleva recorrido, de 0 a 100.
     *
     * Se mide sobre lo detectado, no sobre lo importado: omitir un articulo tambien es avanzar.
     */
    public function progress(): int
    {
        if ($this->isFinal()) {
            return 100;
        }

        if ($this->total_detected <= 0) {
            return 0;
        }

        $done = $this->total_imported + $this->total_updated + $this->total_skipped + $this->total_failed;

        return (int) min(99, round($done * 100 / $this->total_detected));
    }
}
