<?php

namespace App\Services\Dgii;

/**
 * Contrato hacia el PAC (proveedor autorizado de servicios) que efectivamente envía y consulta
 * e-CF ante la DGII. Implementaciones: EcfPlatformGateway (real, vía HTTP) y FakeGateway (para
 * desarrollo/pruebas, sin red) — ver DgiiServiceProvider para el bind según entorno.
 */
interface DgiiGatewayInterface
{
    /** POST /ecf/send */
    public function enviar(array $ecf): RespuestaEcf;

    /** GET /ecf/{id}/status */
    public function consultarEstado(string $pacId): RespuestaEcf;

    /** GET /ecf/{id}/track */
    public function consultarTrack(string $pacId): RespuestaEcf;

    /** GET /ecf/{id}/xml */
    public function descargarXml(string $pacId): string;

    /** GET /dgii/rnc */
    public function buscarContribuyente(string $valor): ?array;

    /**
     * POST {base}/{rnc}/fe/recepcion/api/ecf — reenvía tal cual (sin transformar) un e-CF que
     * otra empresa nos envió, recibido en nuestro endpoint público de recepción.
     */
    public function reenviarRecepcion(string $xml): RespuestaEcf;

    /**
     * POST {base}/{rnc}/fe/aprobacioncomercial/api/ecf — reenvía tal cual una aprobación
     * comercial recibida en nuestro endpoint público, o la que nosotros emitimos sobre un e-CF
     * de un proveedor.
     */
    public function reenviarAprobacionComercial(string $xml): RespuestaEcf;
}
