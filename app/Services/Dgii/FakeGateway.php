<?php

namespace App\Services\Dgii;

use App\Enums\AmbienteEcf;
use Illuminate\Support\Str;

/**
 * Gateway sin red para desarrollo/pruebas: siempre "acepta" el e-CF con datos ficticios. Bind por
 * defecto en local (ver DgiiServiceProvider) para no disparar envíos reales por accidente.
 */
final class FakeGateway implements DgiiGatewayInterface
{
    public function enviar(array $ecf): RespuestaEcf
    {
        return $this->respuestaAceptada($ecf['encf'] ?? null);
    }

    public function consultarEstado(string $pacId): RespuestaEcf
    {
        return $this->respuestaAceptada(pacId: $pacId, incluirTrack: true);
    }

    public function consultarTrack(string $pacId): RespuestaEcf
    {
        return $this->respuestaAceptada(pacId: $pacId, incluirTrack: true);
    }

    public function descargarXml(string $pacId): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            ."<ECF><Encabezado><IdDoc><TrackId>{$pacId}</TrackId></IdDoc></Encabezado></ECF>";
    }

    public function buscarContribuyente(string $valor): ?array
    {
        return [
            'rnc' => $valor,
            'nombre' => 'Contribuyente de prueba',
            'estado' => 'ACTIVO',
        ];
    }

    public function reenviarRecepcion(string $xml): RespuestaEcf
    {
        return $this->respuestaAceptada();
    }

    public function reenviarAprobacionComercial(string $xml): RespuestaEcf
    {
        return $this->respuestaAceptada();
    }

    public function registrarAprobacionComercial(array $datos): RespuestaEcf
    {
        return $this->respuestaAceptada($datos['encf'] ?? null);
    }

    /**
     * @param  bool  $incluirTrack  El "track" del PAC devuelve el historial completo hasta el
     *                              momento de la consulta, así que agrega un evento más al final.
     */
    private function respuestaAceptada(?string $encf = null, ?string $pacId = null, bool $incluirTrack = false): RespuestaEcf
    {
        $pacId ??= 'FAKE-'.Str::random(10);
        $ahora = now();

        $eventos = [
            ['status' => 'AUTENTICACION_EXITOSA', 'timestamp' => $ahora->copy()->subSeconds(3)->toIso8601String()],
            ['status' => 'DOCUMENTO_FIRMADO', 'timestamp' => $ahora->copy()->subSeconds(2)->toIso8601String()],
            ['status' => 'RESPUESTA_DGII', 'timestamp' => $ahora->copy()->subSecond()->toIso8601String()],
        ];

        if ($incluirTrack) {
            $eventos[] = ['status' => 'TRACK_STATUS', 'timestamp' => $ahora->toIso8601String()];
        }

        return new RespuestaEcf(
            exito: true,
            pacId: $pacId,
            encf: $encf,
            estado: 'Aceptado',
            trackId: 'TRACK-'.Str::random(8),
            codigoSeguridad: strtoupper(Str::random(6)),
            dgiiUrl: "https://ecf.dgii.gov.do/fake/{$pacId}",
            xmlUrl: "https://ecf.dgii.gov.do/fake/{$pacId}/xml",
            ambiente: AmbienteEcf::TESTECF,
            responseJson: ['estado' => 'Aceptado', 'pacId' => $pacId, 'eventos' => $eventos],
        );
    }
}
