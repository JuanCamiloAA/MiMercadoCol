<?php

namespace App\Payments\Gateways;

/**
 * Como se lleva al cliente a pagar: una redireccion, un formulario que se envia solo (PayU) o un
 * widget que se abre en la pagina (ePayco). Los datos de tarjeta nunca pasan por el servidor.
 */
final class CheckoutSession
{
    public const TYPE_REDIRECT = 'redirect';

    public const TYPE_FORM = 'form';

    public const TYPE_WIDGET = 'widget';

    /**
     * @param  array<string, string>  $fields  los del formulario (TYPE_FORM)
     * @param  array<string, mixed>  $widget  `script` y `config` (TYPE_WIDGET); nada secreto
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $url = null,
        public readonly array $fields = [],
        public readonly array $widget = [],
        public readonly ?string $gatewayId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'url' => $this->url,
            'fields' => (object) $this->fields,
            'widget' => (object) $this->widget,
        ];
    }
}
