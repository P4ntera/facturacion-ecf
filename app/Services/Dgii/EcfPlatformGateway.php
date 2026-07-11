<?php

namespace App\Services\Dgii;

use App\Enums\AmbienteEcf;
use App\Enums\CanalRecepcionEcf;
use App\Exceptions\DgiiGatewayException;
use App\Settings\EmpresaSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementación real: habla con el PAC configurado en EmpresaSettings (dgii_api_key/dgii_base_url).
 * Los errores de red NO se lanzan aquí para enviar/consultarEstado/consultarTrack: se devuelven como
 * RespuestaEcf con exito=false para que el job que orquesta el envío decida si reintenta. descargarXml
 * y buscarContribuyente no tienen un "exito" tipado en su contrato, así que ahí sí se propaga un
 * error (DgiiGatewayException) o se devuelve null, respectivamente.
 */
final class EcfPlatformGateway implements DgiiGatewayInterface
{
    public function __construct(private readonly EmpresaSettings $settings) {}

    public function enviar(array $ecf): RespuestaEcf
    {
        return $this->peticion(fn (PendingRequest $cliente) => $cliente->post('/ecf/send', $ecf));
    }

    public function consultarEstado(string $pacId): RespuestaEcf
    {
        return $this->peticion(fn (PendingRequest $cliente) => $cliente->get("/ecf/{$pacId}/status"));
    }

    public function consultarTrack(string $pacId): RespuestaEcf
    {
        return $this->peticion(fn (PendingRequest $cliente) => $cliente->get("/ecf/{$pacId}/track"));
    }

    public function descargarXml(string $pacId): string
    {
        try {
            $response = $this->cliente()->get("/ecf/{$pacId}/xml");
        } catch (Throwable $e) {
            $this->registrarFallo('descargarXml', $e);

            throw new DgiiGatewayException('No se pudo conectar con el PAC de DGII para descargar el XML del e-CF.');
        }

        if ($response->failed()) {
            throw new DgiiGatewayException("El PAC de DGII no pudo entregar el XML del e-CF {$pacId}.");
        }

        return $response->body();
    }

    public function buscarContribuyente(string $valor): ?array
    {
        try {
            $response = $this->cliente()->get('/dgii/rnc', ['valor' => $valor]);
        } catch (Throwable $e) {
            $this->registrarFallo('buscarContribuyente', $e);

            return null;
        }

        return $response->successful() ? $response->json() : null;
    }

    public function buscarCedulaJce(string $cedula): ?array
    {
        try {
            $response = $this->cliente()->get('/dgii/jce', ['cedula' => $cedula]);
        } catch (Throwable $e) {
            $this->registrarFallo('buscarCedulaJce', $e);

            return null;
        }

        return $response->successful() ? $response->json() : null;
    }

    public function reenviarRecepcion(string $xml): RespuestaEcf
    {
        return $this->reenviarXml(CanalRecepcionEcf::RECEPCION, $xml);
    }

    public function reenviarAprobacionComercial(string $xml): RespuestaEcf
    {
        return $this->reenviarXml(CanalRecepcionEcf::APROBACION_COMERCIAL, $xml);
    }

    public function registrarAprobacionComercial(array $datos): RespuestaEcf
    {
        return $this->peticion(fn (PendingRequest $cliente) => $cliente->post('/aprobacion-comercial', $datos));
    }

    /** El XML se reenvía tal cual, sin transformarlo: el cuerpo del POST es el XML recibido. */
    private function reenviarXml(CanalRecepcionEcf $canal, string $xml): RespuestaEcf
    {
        return $this->peticion(fn (PendingRequest $cliente) => $cliente
            ->withBody($xml, 'application/xml')
            ->post("/{$this->settings->rnc}/fe/{$canal->segmentoPac()}/api/ecf"));
    }

    private function peticion(\Closure $llamada): RespuestaEcf
    {
        try {
            return $this->mapearRespuesta($llamada($this->cliente()));
        } catch (Throwable $e) {
            $this->registrarFallo('peticion', $e);

            return new RespuestaEcf(
                exito: false,
                ambiente: AmbienteEcf::tryFrom($this->settings->dgii_ambiente),
                errorMessage: 'No se pudo conectar con el PAC de DGII. Se reintentará automáticamente.',
            );
        }
    }

    private function cliente(): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => $this->settings->dgii_api_key])
            ->baseUrl($this->settings->dgii_base_url)
            ->acceptJson()
            ->timeout(30);
    }

    private function mapearRespuesta(Response $response): RespuestaEcf
    {
        $json = $response->json() ?? [];

        return new RespuestaEcf(
            exito: $response->successful(),
            pacId: $json['pacId'] ?? null,
            encf: $json['encf'] ?? null,
            estado: $json['estado'] ?? null,
            trackId: $json['trackId'] ?? null,
            codigoSeguridad: $json['codigoSeguridad'] ?? null,
            dgiiUrl: $json['dgiiUrl'] ?? null,
            xmlUrl: $json['xmlUrl'] ?? null,
            ambiente: AmbienteEcf::tryFrom($json['ambiente'] ?? $this->settings->dgii_ambiente),
            errorMessage: $response->successful() ? null : ($json['error'] ?? 'El PAC de DGII rechazó la solicitud.'),
            responseJson: $json,
        );
    }

    /** Nunca registra la API Key: solo el nombre de la operación y la clase de la excepción. */
    private function registrarFallo(string $operacion, Throwable $e): void
    {
        Log::error('Fallo de conexión con el PAC de DGII.', [
            'operacion' => $operacion,
            'excepcion' => $e::class,
        ]);
    }
}
