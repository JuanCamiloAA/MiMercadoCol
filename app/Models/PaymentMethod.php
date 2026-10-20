<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un medio de pago que la empresa configuro para cobrar ventas: «Efectivo», «Nequi»,
 * «Datáfono Bancolombia», «Paga con Wompi».
 *
 * No confundir con `CompanyPaymentMethod`, que es la tarjeta con que la empresa le paga la
 * membresia a la plataforma.
 *
 * El comportamiento sale del `kind`, nunca del nombre: el efectivo da cambio y se redondea, el
 * credito pide cupo, la pasarela crea una transaccion. El nombre es solo lo que se lee. Cuando
 * aparece lo decide `PaymentMethodAvailability` con las reglas de `availability`.
 */
class PaymentMethod extends Model
{
    use BelongsToCompany;

    public const KIND_CASH = 'cash';

    /** Datafono manual: el cajero cobra en el aparato y anota el voucher. */
    public const KIND_CARD = 'card';

    public const KIND_TRANSFER = 'transfer';

    public const KIND_CREDIT = 'credit';

    public const KIND_STORE_CREDIT = 'store_credit';

    /** Lo arma solo una devolucion con cambio; nunca se muestra. */
    public const KIND_EXCHANGE = 'exchange';

    /** Checkout de la pasarela en la tienda en linea. */
    public const KIND_GATEWAY_CHECKOUT = 'gateway_checkout';

    /** QR o link de pago de la pasarela, en la caja. */
    public const KIND_GATEWAY_LINK = 'gateway_link';

    /** Datafono integrado con la pasarela, en la caja. */
    public const KIND_GATEWAY_TERMINAL = 'gateway_terminal';

    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_CASH, self::KIND_CARD, self::KIND_TRANSFER, self::KIND_CREDIT, self::KIND_STORE_CREDIT,
        self::KIND_EXCHANGE, self::KIND_GATEWAY_CHECKOUT, self::KIND_GATEWAY_LINK, self::KIND_GATEWAY_TERMINAL, self::KIND_OTHER,
    ];

    public const KIND_LABELS = [
        self::KIND_CASH => 'Efectivo',
        self::KIND_CARD => 'Datáfono (manual)',
        self::KIND_TRANSFER => 'Transferencia',
        self::KIND_CREDIT => 'Crédito',
        self::KIND_STORE_CREDIT => 'Saldo a favor',
        self::KIND_EXCHANGE => 'Cambio por devolución',
        self::KIND_GATEWAY_CHECKOUT => 'Pasarela en la tienda',
        self::KIND_GATEWAY_LINK => 'QR o link de pago en la caja',
        self::KIND_GATEWAY_TERMINAL => 'Datáfono integrado',
        self::KIND_OTHER => 'Otro',
    ];

    public const CHANNEL_POS = 'pos';

    public const CHANNEL_ONLINE = 'online';

    public const CHANNELS = [self::CHANNEL_POS, self::CHANNEL_ONLINE];

    /** En que canales tiene sentido cada `kind`. La regla `channels` solo puede acotar esto. */
    public const KIND_CHANNELS = [
        self::KIND_CASH => [self::CHANNEL_POS, self::CHANNEL_ONLINE],
        self::KIND_CARD => [self::CHANNEL_POS, self::CHANNEL_ONLINE],
        self::KIND_TRANSFER => [self::CHANNEL_POS, self::CHANNEL_ONLINE],
        self::KIND_CREDIT => [self::CHANNEL_POS, self::CHANNEL_ONLINE],
        self::KIND_STORE_CREDIT => [self::CHANNEL_POS, self::CHANNEL_ONLINE],
        self::KIND_EXCHANGE => [],
        self::KIND_GATEWAY_CHECKOUT => [self::CHANNEL_ONLINE],
        self::KIND_GATEWAY_LINK => [self::CHANNEL_POS],
        self::KIND_GATEWAY_TERMINAL => [self::CHANNEL_POS],
        self::KIND_OTHER => [self::CHANNEL_POS, self::CHANNEL_ONLINE],
    ];

    /** Los que cobran por una pasarela: necesitan cuenta y crean una transaccion. */
    public const GATEWAY_KINDS = [self::KIND_GATEWAY_CHECKOUT, self::KIND_GATEWAY_LINK, self::KIND_GATEWAY_TERMINAL];

    /** Lo que la empresa puede crear y editar. Los demas son del sistema. */
    public const CONFIGURABLE_KINDS = [
        self::KIND_CASH, self::KIND_CARD, self::KIND_TRANSFER, self::KIND_CREDIT, self::KIND_OTHER,
        self::KIND_GATEWAY_CHECKOUT, self::KIND_GATEWAY_LINK, self::KIND_GATEWAY_TERMINAL,
    ];

    /** Lo que se cobra al recibir un pedido: plata en la puerta, no deuda ni pasarela. */
    public const COLLECTABLE_KINDS = [self::KIND_CASH, self::KIND_CARD, self::KIND_TRANSFER, self::KIND_OTHER];

    /** Grupos del checkout de la tienda. */
    public const GROUP_ONLINE = 'online';

    public const GROUP_ON_DELIVERY = 'on_delivery';

    public const GROUP_CREDIT = 'credit';

    public const GROUP_LABELS = [
        self::GROUP_ONLINE => 'Paga en línea',
        self::GROUP_ON_DELIVERY => 'Paga al recibir',
        self::GROUP_CREDIT => 'Crédito',
    ];

    /** Iconos que se pueden elegir; los pinta `payment-icons.tsx`. */
    public const ICONS = ['money', 'credit-card', 'bank', 'device-mobile', 'qr-code', 'wallet', 'lock-key', 'arrows-split', 'globe', 'receipt', 'hand-coins', 'storefront'];

    public const DEFAULT_ICONS = [
        self::KIND_CASH => 'money',
        self::KIND_CARD => 'credit-card',
        self::KIND_TRANSFER => 'arrows-split',
        self::KIND_CREDIT => 'lock-key',
        self::KIND_STORE_CREDIT => 'wallet',
        self::KIND_EXCHANGE => 'receipt',
        self::KIND_GATEWAY_CHECKOUT => 'globe',
        self::KIND_GATEWAY_LINK => 'qr-code',
        self::KIND_GATEWAY_TERMINAL => 'credit-card',
        self::KIND_OTHER => 'hand-coins',
    ];

    protected $fillable = [
        'company_id', 'name', 'kind', 'system_key', 'gateway_account_id', 'is_system', 'icon', 'display',
        'requires_reference', 'reference_label', 'fee_percent', 'fee_fixed', 'sort_order', 'is_active', 'availability',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'display' => 'array',
            'requires_reference' => 'boolean',
            'fee_percent' => 'decimal:2',
            'fee_fixed' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'availability' => 'array',
        ];
    }

    public function gatewayAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayAccount::class, 'gateway_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function isGateway(): bool
    {
        return in_array($this->kind, self::GATEWAY_KINDS, true);
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    /** Una regla de `availability`, o su defecto. */
    public function rule(string $key, mixed $default = null): mixed
    {
        return ($this->availability ?? [])[$key] ?? $default;
    }

    /** Si se puede mezclar con otros medios en el mismo cobro. Por defecto, si. */
    public function isCombinable(): bool
    {
        return (bool) $this->rule('combinable', true);
    }

    /** Lo que dice el medio en un canal: su descripcion corta. */
    public function description(string $channel): ?string
    {
        $display = $this->display ?? [];
        $value = $display[$channel === self::CHANNEL_ONLINE ? 'description_online' : 'description_pos'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function instructions(): ?string
    {
        $value = ($this->display ?? [])['instructions'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function iconName(): string
    {
        return in_array($this->icon, self::ICONS, true) ? $this->icon : (self::DEFAULT_ICONS[$this->kind] ?? 'hand-coins');
    }

    /** El grupo del checkout donde aparece. */
    public function onlineGroup(): string
    {
        return match ($this->kind) {
            self::KIND_GATEWAY_CHECKOUT => self::GROUP_ONLINE,
            self::KIND_CREDIT => self::GROUP_CREDIT,
            default => self::GROUP_ON_DELIVERY,
        };
    }

    /**
     * Comision estimada de un cobro: porcentaje mas fijo, en pesos enteros.
     */
    public function feeFor(int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        return (int) round($amount * (float) $this->fee_percent / 100) + (int) $this->fee_fixed;
    }
}
