<?php

namespace Tests\Support;

use App\Services\Dgii\DgiiGatewayInterface;
use App\Services\Dgii\RespuestaEcf;

/**
 * Base para test doubles de DgiiGatewayInterface: cada método lanza por defecto ("no debería
 * llamarse"), así un test solo sobreescribe el método que realmente le interesa.
 */
abstract class GatewayStub implements DgiiGatewayInterface
{
    public function enviar(array $ecf): RespuestaEcf
    {
        throw new \RuntimeException('no usado');
    }

    public function consultarEstado(string $pacId): RespuestaEcf
    {
        throw new \RuntimeException('no usado');
    }

    public function consultarTrack(string $pacId): RespuestaEcf
    {
        throw new \RuntimeException('no usado');
    }

    public function descargarXml(string $pacId): string
    {
        throw new \RuntimeException('no usado');
    }

    public function buscarContribuyente(string $valor): ?array
    {
        throw new \RuntimeException('no usado');
    }

    public function buscarCedulaJce(string $cedula): ?array
    {
        throw new \RuntimeException('no usado');
    }

    public function reenviarRecepcion(string $xml): RespuestaEcf
    {
        throw new \RuntimeException('no usado');
    }

    public function reenviarAprobacionComercial(string $xml): RespuestaEcf
    {
        throw new \RuntimeException('no usado');
    }

    public function registrarAprobacionComercial(array $datos): RespuestaEcf
    {
        throw new \RuntimeException('no usado');
    }
}
