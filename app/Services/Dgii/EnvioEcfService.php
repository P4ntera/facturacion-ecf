<?php

namespace App\Services\Dgii;

use App\Enums\EstadoFiscal;
use App\Exceptions\EcfInvalidoException;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta el envío de un e-CF ya registrado: construye el JSON (EcfBuilder), lo manda al PAC
 * (DgiiGatewayInterface) y persiste el resultado en la Venta. Nunca lanza: los problemas de datos
 * (RNC faltante, etc.) quedan como RECHAZADO y los de red dejan la venta en PENDIENTE para que
 * EnviarEcfJob reintente.
 */
class EnvioEcfService
{
    public function __construct(
        private readonly EcfBuilder $builder,
        private readonly DgiiGatewayInterface $gateway,
    ) {}

    public function enviar(Venta $venta): RespuestaEcf
    {
        try {
            $ecf = $this->builder->construir($venta);
        } catch (EcfInvalidoException $e) {
            return $this->rechazarPorDatosInvalidos($venta, $e);
        }

        $respuesta = $this->gateway->enviar($ecf);
        $this->guardar($venta, $respuesta);

        return $respuesta;
    }

    /**
     * Refresca el estado fiscal consultando el track del PAC (acción "Refrescar estado" del
     * Resource). Reutiliza el mismo mapeo/guardado que enviar().
     */
    public function refrescarEstado(Venta $venta): RespuestaEcf
    {
        if ($venta->pac_id === null) {
            return new RespuestaEcf(exito: false, errorMessage: 'Esta venta todavía no se ha enviado al PAC.');
        }

        $respuesta = $this->gateway->consultarTrack($venta->pac_id);
        $this->guardar($venta, $respuesta);

        return $respuesta;
    }

    private function guardar(Venta $venta, RespuestaEcf $respuesta): void
    {
        DB::transaction(function () use ($venta, $respuesta) {
            $venta->update($this->atributosParaGuardar($venta, $respuesta));
        });
    }

    private function rechazarPorDatosInvalidos(Venta $venta, EcfInvalidoException $e): RespuestaEcf
    {
        DB::transaction(function () use ($venta, $e) {
            $venta->update([
                'estado_fiscal' => EstadoFiscal::RECHAZADO,
                'ecf_respuesta' => ['error' => $e->getMessage()],
            ]);
        });

        return new RespuestaEcf(exito: false, errorMessage: $e->getMessage());
    }

    /** @return array<string, mixed> */
    private function atributosParaGuardar(Venta $venta, RespuestaEcf $respuesta): array
    {
        if (! $respuesta->exito) {
            // Error de red/PAC no disponible: la venta queda PENDIENTE (invariante ya cumplida)
            // para que EnviarEcfJob reintente; solo se guarda el motivo del último intento.
            return [
                'estado_fiscal' => EstadoFiscal::PENDIENTE,
                'ecf_respuesta' => ['error' => $respuesta->errorMessage],
            ];
        }

        return [
            'estado_fiscal' => $this->mapearEstado($respuesta),
            'ecf_track_id' => $respuesta->trackId ?? $venta->ecf_track_id,
            'pac_id' => $respuesta->pacId ?? $venta->pac_id,
            'codigo_seguridad' => $respuesta->codigoSeguridad ?? $venta->codigo_seguridad,
            'dgii_url' => $respuesta->dgiiUrl ?? $venta->dgii_url,
            'xml_url' => $respuesta->xmlUrl ?? $venta->xml_url,
            'ambiente' => $respuesta->ambiente ?? $venta->ambiente,
            'ecf_enviado_en' => now(),
            'ecf_respuesta' => $respuesta->responseJson,
        ];
    }

    private function mapearEstado(RespuestaEcf $respuesta): EstadoFiscal
    {
        return match ($respuesta->estado) {
            'Aceptado' => EstadoFiscal::ACEPTADO,
            'Aceptado Condicional' => EstadoFiscal::ACEPTADO_CONDICIONAL,
            'Rechazado' => EstadoFiscal::RECHAZADO,
            'En Proceso', 'EN_PROCESO' => EstadoFiscal::EN_PROCESO,
            // Estado desconocido o "ERROR_AL_ENVIAR": se deja pendiente para que se reintente.
            default => EstadoFiscal::PENDIENTE,
        };
    }
}
