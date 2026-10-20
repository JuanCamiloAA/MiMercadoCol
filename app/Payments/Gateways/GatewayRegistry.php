<?php

namespace App\Payments\Gateways;

use App\Services\PlatformSettingsService;
use InvalidArgumentException;

/**
 * Los conectores de pasarela que pueden usar las empresas y cuales tiene encendidos la
 * plataforma (Plataforma > Pasarelas para empresas).
 */
class GatewayRegistry
{
    public const DRIVERS = [
        'wompi' => WompiGateway::class,
        'mercadopago' => MercadoPagoGateway::class,
        'payu' => PayUGateway::class,
        'epayco' => EpaycoGateway::class,
        'bold' => BoldGateway::class,
    ];

    /** @var array<string, PaymentGateway> */
    protected array $instances = [];

    public function __construct(protected PlatformSettingsService $platform) {}

    public function for(string $provider): PaymentGateway
    {
        $class = self::DRIVERS[$provider] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Pasarela desconocida: {$provider}");
        }

        return $this->instances[$provider] ??= app($class);
    }

    public function exists(string $provider): bool
    {
        return isset(self::DRIVERS[$provider]);
    }

    /**
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys(self::DRIVERS);
    }

    /** Si la plataforma deja que las empresas conecten esta pasarela hoy. */
    public function isEnabled(string $provider): bool
    {
        return $this->exists($provider) && in_array($provider, $this->enabled(), true);
    }

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        $stored = $this->platform->get('payments.company_providers');

        return array_values(array_intersect($this->providers(), is_array($stored) ? $stored : []));
    }

    /**
     * Lo que la pantalla de Pasarelas necesita de cada conector: nombre, llaves y capacidades.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(bool $onlyEnabled = true): array
    {
        $rows = [];

        foreach ($this->providers() as $provider) {
            if ($onlyEnabled && ! $this->isEnabled($provider)) {
                continue;
            }

            $gateway = $this->for($provider);
            $rows[] = [
                'provider' => $provider,
                'label' => $gateway->label(),
                'fields' => $gateway->fields(),
                'capabilities' => $gateway->capabilities(),
                'webhook_instructions' => $gateway->webhookInstructions(),
                'enabled' => $this->isEnabled($provider),
            ];
        }

        return $rows;
    }
}
