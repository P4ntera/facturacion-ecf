<?php

namespace App\Services\Dgii;

use App\Enums\AmbienteEcf;

/**
 * Respuesta normalizada de cualquier operación del PAC (envío o consulta de estado/track). El
 * "estado" se guarda tal cual lo devuelve el PAC (p. ej. "Aceptado", "Rechazado", "En proceso"):
 * no se mapea a un enum propio en esta fase para no adivinar valores que el PAC real aún no ha
 * confirmado.
 */
final readonly class RespuestaEcf
{
    /** @param  array<string, mixed>  $responseJson */
    public function __construct(
        public bool $exito,
        public ?string $pacId = null,
        public ?string $encf = null,
        public ?string $estado = null,
        public ?string $trackId = null,
        public ?string $codigoSeguridad = null,
        public ?string $dgiiUrl = null,
        public ?string $xmlUrl = null,
        public ?AmbienteEcf $ambiente = null,
        public ?string $errorMessage = null,
        public array $responseJson = [],
    ) {}
}
