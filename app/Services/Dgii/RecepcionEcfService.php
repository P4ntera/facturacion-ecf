<?php

namespace App\Services\Dgii;

use App\Enums\CanalRecepcionEcf;
use App\Enums\EstadoReenvioPac;
use App\Models\DocumentoRecibido;
use App\Models\Empresa;

/**
 * Procesa lo que llega a nuestros endpoints públicos de recepción/aprobación comercial (los que
 * se registran en la DGII): valida, reenvía el XML tal cual al PAC (con la api key de LA EMPRESA
 * dueña del documento, no una global) y deja constancia de todo (incluidas las recepciones
 * rechazadas) en documentos_recibidos. Nunca lanza: el controller decide la respuesta HTTP según
 * el estado_reenvio resultante.
 *
 * Con la configuración fiscal por empresa (T3) ya no hay un solo "nuestro RNC" global: la DGII
 * llama este endpoint sin indicar a qué empresa va dirigido, así que hay que deducirlo del propio
 * XML, comparando RNCComprador/RNCEmisor contra el RNC de cada empresa con usa_ecf activo.
 */
class RecepcionEcfService
{
    /** DGII no publica un límite oficial; un e-CF real no se acerca a esto. */
    private const TAMANO_MAXIMO_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly DgiiGatewayFactory $gatewayFactory) {}

    public function procesar(CanalRecepcionEcf $canal, string $xml, ?string $ipOrigen): DocumentoRecibido
    {
        $metadatos = $this->extraerMetadatos($xml);

        if (strlen($xml) > self::TAMANO_MAXIMO_BYTES) {
            return $this->registrar($canal, $xml, $metadatos, $ipOrigen, null, EstadoReenvioPac::RECHAZADO_VALIDACION,
                error: 'El XML supera el tamaño máximo permitido ('.self::TAMANO_MAXIMO_BYTES.' bytes).');
        }

        $empresa = $this->resolverEmpresa($metadatos);

        if ($empresa === null) {
            return $this->registrar($canal, $xml, $metadatos, $ipOrigen, null, EstadoReenvioPac::RECHAZADO_VALIDACION,
                error: 'El RNC del documento no corresponde a ninguna empresa registrada en este sistema.');
        }

        $gateway = $this->gatewayFactory->make($empresa);

        $respuesta = $canal === CanalRecepcionEcf::RECEPCION
            ? $gateway->reenviarRecepcion($xml)
            : $gateway->reenviarAprobacionComercial($xml);

        return $this->registrar(
            $canal,
            $xml,
            $metadatos,
            $ipOrigen,
            $empresa,
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
     * El documento debe involucrarnos como comprador o como emisor original: se busca una
     * empresa (con e-CF activo) cuyo RNC coincida con cualquiera de los dos. Si ninguno se pudo
     * leer, o ninguna empresa coincide, se rechaza por precaución.
     *
     * @param  array<string, ?string>  $metadatos
     */
    private function resolverEmpresa(array $metadatos): ?Empresa
    {
        $rncs = array_values(array_filter([$metadatos['rnc_comprador'], $metadatos['rnc_emisor']]));

        if ($rncs === []) {
            return null;
        }

        return Empresa::where('usa_ecf', true)->whereIn('rnc', $rncs)->first();
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
        ?Empresa $empresa,
        EstadoReenvioPac $estado,
        ?string $error = null,
        array $respuestaPac = [],
    ): DocumentoRecibido {
        return DocumentoRecibido::create([
            'empresa_id' => $empresa?->id,
            'canal' => $canal,
            // Sin empresa resuelta (rechazado antes de identificarla) se deja constancia de
            // cuál de los dos RNC del XML se intentó usar, para poder investigar el rechazo.
            'rnc_destino' => $empresa?->rnc ?? $metadatos['rnc_comprador'] ?? $metadatos['rnc_emisor'] ?? '',
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
