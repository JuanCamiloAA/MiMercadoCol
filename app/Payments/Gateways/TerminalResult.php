<?php

namespace App\Payments\Gateways;

/**
 * El cobro que se mando a un datafono: con que id lo reconoce la pasarela.
 */
final class TerminalResult
{
    public function __construct(
        public readonly ?string $intentId = null,
        public readonly ?string $message = null,
    ) {}
}
