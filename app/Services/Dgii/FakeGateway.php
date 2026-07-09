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
        return $this->respuestaAceptada(pacId: $pacId);
    }

    public function consultarTrack(string $pacId): RespuestaEcf
    {
        return $this->respuestaAceptada(pacId: $pacId);
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

    private function respuestaAceptada(?string $encf = null, ?string $pacId = null): RespuestaEcf
    {
        $pacId ??= 'FAKE-'.Str::random(10);

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
            responseJson: ['estado' => 'Aceptado', 'pacId' => $pacId],
        );
    }
}
