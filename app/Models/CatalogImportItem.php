<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Que paso con cada articulo de una importacion.
 *
 * Guarda el payload crudo de la fuente tal cual llego. Cuando algo sale raro —un nombre
 * cortado, una marca equivocada— es la unica manera de saber si el error fue de la fuente o
 * del normalizador.
 */
class CatalogImportItem extends Model
{
    use HasFactory;

    public const RESULT_IMPORTED = 'imported';

    public const RESULT_UPDATED = 'updated';

    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_FAILED = 'failed';

    /** @var array<string, string> */
    public const RESULT_LABELS = [
        self::RESULT_IMPORTED => 'Importado',
        self::RESULT_UPDATED => 'Actualizado',
        self::RESULT_SKIPPED => 'Omitido',
        self::RESULT_FAILED => 'Fallido',
    ];

    /**
     * Los motivos que produce el modulo, en lenguaje de tienda.
     *
     * Son claves estables para poder filtrar y contar; la pantalla muestra el texto.
     *
     * @var array<string, string>
     */
    public const REASON_LABELS = [
        'invalid_ean' => 'El código de barras no es válido',
        'missing_name' => 'El artículo no trae nombre',
        'not_found' => 'La fuente no tiene ese código',
        'duplicate' => 'Repetido dentro de la misma importación',
        'deleted_product' => 'Ya existe pero está eliminado en el catálogo',
        'no_changes' => 'Ya estaba al día, no hubo nada que cambiar',
        'sku_taken' => 'El código interno que le tocaba ya está en uso',
    ];

    protected $fillable = [
        'catalog_import_id', 'ean', 'name', 'payload', 'result', 'reason', 'product_id',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CatalogImport::class, 'catalog_import_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function resultLabel(): string
    {
        return self::RESULT_LABELS[$this->result] ?? $this->result;
    }

    public function reasonLabel(): ?string
    {
        if ($this->reason === null) {
            return null;
        }

        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }
}
