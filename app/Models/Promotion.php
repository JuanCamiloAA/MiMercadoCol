<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use BelongsToCompany, HasFactory;

    /** Precio fijo por articulo: antes 5.100, ahora 4.200. */
    public const TYPE_SPECIAL_PRICE = 'special_price';

    /** Porcentaje sobre una categoria o una lista de articulos. */
    public const TYPE_PERCENT_OFF = 'percent_off';

    /** Lleva N y paga M: el 3x2 de toda la vida. */
    public const TYPE_N_FOR_M = 'n_for_m';

    /** Varios articulos juntos a un precio fijo. */
    public const TYPE_BUNDLE = 'bundle';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_SPECIAL_PRICE => 'Precio especial',
        self::TYPE_PERCENT_OFF => 'Descuento por porcentaje',
        self::TYPE_N_FOR_M => 'Lleva N paga M',
        self::TYPE_BUNDLE => 'Combo',
    ];

    /** Los que cambian el precio unitario; los demas se resuelven sobre la linea completa. */
    public const UNIT_TYPES = [self::TYPE_SPECIAL_PRICE, self::TYPE_PERCENT_OFF];

    protected $fillable = [
        'company_id', 'name', 'type', 'label', 'color', 'starts_at', 'ends_at',
        'applies_online', 'applies_pos', 'priority', 'config', 'is_active', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'applies_online' => 'boolean',
            'applies_pos' => 'boolean',
            'priority' => 'integer',
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /** Vigente hoy: encendida y dentro de su ventana de fechas. */
    public function scopeRunning(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('is_active', true)
            ->where(fn ($inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where($channel === 'pos' ? 'applies_pos' : 'applies_online', true);
    }

    /**
     * Una linea que explique la promocion en la caja.
     *
     * El cajero tiene que poder decirle al cliente por que se le esta rebajando, y en las que
     * dependen de la cantidad, que le falta para alcanzarla.
     */
    public function hint(): string
    {
        return match ($this->type) {
            self::TYPE_SPECIAL_PRICE => 'Precio especial',
            self::TYPE_PERCENT_OFF => 'Descuento de '.rtrim(rtrim(number_format((float) ($this->config['percent'] ?? 0), 1, ',', '.'), '0'), ',').'%',
            self::TYPE_N_FOR_M => 'Lleva '.($this->config['buy'] ?? 0).' y paga '.($this->config['pay'] ?? 0),
            self::TYPE_BUNDLE => 'Combo de '.$this->targets->count().' articulos',
            default => $this->name,
        };
    }

    /** Las que solo se resuelven cuando ya hay cantidades en el tiquete. */
    public function dependsOnQuantity(): bool
    {
        return ! in_array($this->type, self::UNIT_TYPES, true);
    }

    /** Etiqueta que se le pinta al cliente cuando la promocion no trae una propia. */
    public function badge(): string
    {
        if ($this->label) {
            return $this->label;
        }

        return match ($this->type) {
            self::TYPE_PERCENT_OFF => '-'.rtrim(rtrim(number_format((float) ($this->config['percent'] ?? 0), 1, ',', '.'), '0'), ',').'%',
            self::TYPE_N_FOR_M => ($this->config['buy'] ?? 0).'x'.($this->config['pay'] ?? 0),
            self::TYPE_BUNDLE => 'Combo',
            default => 'Oferta',
        };
    }
}
