<?php

namespace App\Payments\Gateways;

use RuntimeException;

/**
 * La pasarela no respondio o rechazo la peticion. El mensaje es apto para la persona: nunca
 * lleva llaves ni el cuerpo crudo de la respuesta.
 */
class GatewayException extends RuntimeException {}
