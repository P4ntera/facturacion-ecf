<?php

namespace App\Exceptions;

/**
 * Fallo al hablar con el PAC en operaciones que no devuelven RespuestaEcf (p. ej. descargarXml,
 * cuyo contrato es `string` y no admite un "exito=false" tipado). El mensaje ya viene en español
 * y sin detalles internos: nunca envuelve el mensaje crudo de la excepción de red, que podría
 * incluir la API Key enviada en los headers.
 */
class DgiiGatewayException extends \Exception {}
