<?php

namespace App\Services\Dgii;

use App\Enums\CanalRecepcionEcf;
use App\Enums\EstadoReenvioPac;
use App\Models\DocumentoRecibido;
use App\Settings\EmpresaSettings;

/**
 * Procesa lo que llega a nuestros endpoints públicos de recepción/aprobación comercial (los que
 * se registran en la DGII): valida, reenvía el XML tal cual al PAC y deja constancia de todo
 * (incluidas las recepciones rechazadas) en documentos_recibidos. Nunca lanza: el controller
 * decide la respuesta HTTP según el estado_reenvio resultante.
 */
class RecepcionEcfService
{
    /** DGII no publica un límite oficial; un e-CF real no se acerca a esto. */
    private const TAMANO_MAXIMO_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly DgiiGatewayInterface $gateway,
        private readonly EmpresaSettings $settings,
    ) {}

    public function procesar(CanalRecepcionEcf $canal, string $xml, ?string $ipOrigen): DocumentoRecibido
    {
        $metadatos = $this->extraerMetadatos($xml);

        if (strlen($xml) > self::TAMANO_MAXIMO_BYTES) {
            return $this->registrar($canal, $xml, $metadatos, $ipOrigen, EstadoReenvioPac::RECHAZADO_VALIDACION,
                error: 'El XML supera el tamaño máximo permitido ('.self::TAMANO_MAXIMO_BYTES.' bytes).');
        }

        if (! $this->rncCoincide($metadatos)) {
            return $this->registrar($canal, $xml, $metadatos, $ipOrigen, EstadoReenvioPac::RECHAZADO_VALIDACION,
                error: 'El RNC del documento no corresponde a esta empresa.');
        }

        $respuesta = $canal === CanalRecepcionEcf::RECEPCION
            ? $this->gateway->reenviarRecepcion($xml)
            : $this->gateway->reenviarAprobacionComercial($xml);

        return $this->registrar(
            $canal,
            $xml,
            $metadatos,
            $ipOrigen,
            $respuesta->exito ? EstadoReenvioPac::REENVIADO : EstadoReenvioPac::ERROR_REENVIO,
            error: $respuesta->exito ? null : $respuesta->errorMessage,
            respuestaPac: $respuesta->responseJson,
        );
    }

    /**
     * Best-effort: nunca lanza. Busca las etiquetas por nombre local (sin depender de namespaces)
     * para tolerar variaciones del XML real de la DGII; lo que no se pueda leer queda null.
     *
     * @return array<string, ?string>
     */
    private function extraerMetadatos(string $xml): array
    {
        $vacio = [
            'rnc_comprador' => null,
            'rnc_emisor' => null,
            'razon_social_emisor' => null,
            'encf' => null,
            'tipo_comprobante' => null,
            'monto_total' => null,
            'fecha_emision' => null,
        ];

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();

        if ($doc === false) {
            return $vacio;
        }

        $buscar = function (string $etiqueta) use ($doc): ?string {
            $nodos = $doc->xpath(".//*[local-name()='{$etiqueta}']");
            $valor = $nodos[0] ?? null;

            return $valor !== null ? trim((string) $valor) : null;
        };

        return [
            'rnc_comprador' => $buscar('RNCComprador'),
            'rnc_emisor' => $buscar('RNCEmisor'),
            'razon_social_emisor' => $buscar('RazonSocialEmisor'),
            'encf' => $buscar('eNCF'),
            'tipo_comprobante' => $buscar('TipoeCF'),
            'monto_total' => $buscar('MontoTotal'),
            'fecha_emision' => $buscar('FechaEmision'),
        ];
    }

    /**
     * El documento debe involucrarnos como comprador o como emisor original; si no se pudo leer
     * ninguno de los dos (XML irreconocible) se rechaza por precaución.
     *
     * @param  array<string, ?string>  $metadatos
     */
    private function rncCoincide(array $metadatos): bool
    {
        $rnc = $this->settings->rnc;

        return $metadatos['rnc_comprador'] === $rnc || $metadatos['rnc_emisor'] === $rnc;
    }

    /**
     * @param  array<string, ?string>  $metadatos
     * @param  array<string, mixed>  $respuestaPac
     */
    private function registrar(
        CanalRecepcionEcf $canal,
        string $xml,
        array $metadatos,
        ?string $ipOrigen,
        EstadoReenvioPac $estado,
        ?string $error = null,
        array $respuestaPac = [],
    ): DocumentoRecibido {
        return DocumentoRecibido::create([
            'canal' => $canal,
            'rnc_destino' => $this->settings->rnc,
            'rnc_emisor' => $metadatos['rnc_emisor'],
            'razon_social_emisor' => $metadatos['razon_social_emisor'],
            'encf' => $metadatos['encf'],
            'tipo_comprobante' => $metadatos['tipo_comprobante'],
            'monto_total' => $metadatos['monto_total'],
            'fecha_emision' => $metadatos['fecha_emision'],
            'xml' => $xml,
            'estado_reenvio' => $estado,
            'error' => $error,
            'respuesta_pac' => $respuestaPac,
            'ip_origen' => $ipOrigen,
        ]);
    }
}
