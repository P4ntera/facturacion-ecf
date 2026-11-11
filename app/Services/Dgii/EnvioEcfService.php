<?php

namespace App\Services\Dgii;

use App\Enums\EstadoFiscal;
use App\Enums\TipoNotificacion;
use App\Exceptions\EcfInvalidoException;
use App\Models\Venta;
use App\Services\NotificacionService;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquesta el envío de un e-CF ya registrado: construye el JSON (EcfBuilder), lo manda al PAC
 * (DgiiGatewayInterface, resuelto vía DgiiGatewayFactory para LA EMPRESA DE LA VENTA — nunca la
 * de otra) y persiste el resultado en la Venta. Nunca lanza: los problemas de datos (RNC
 * faltante, etc.) quedan como RECHAZADO y los de red dejan la venta en PENDIENTE para que
 * EnviarEcfJob reintente.
 */
class EnvioEcfService
{
    public function __construct(
        private readonly EcfBuilder $builder,
        private readonly DgiiGatewayFactory $gatewayFactory,
        private readonly NotificacionService $notificaciones,
    ) {}

    public function enviar(Venta $venta): RespuestaEcf
    {
        try {
            $ecf = $this->builder->construir($venta);
        } catch (EcfInvalidoException $e) {
            return $this->rechazarPorDatosInvalidos($venta, $e);
        }

        $gateway = $this->gateway($venta);

        $config = $venta->empresa->config();

        if (blank($config->dgii_base_url) || blank($config->dgii_api_key)) {
            $faltante = blank($config->dgii_base_url) ? 'la URL del PAC' : 'la API Key del PAC';

            return $this->rechazarPorDatosInvalidos(
                $venta,
                new EcfInvalidoException("La empresa no tiene configurada {$faltante}. Configúrela en Ajustes de la Empresa antes de enviar e-CF."),
            );
        }

        $respuesta = $gateway->enviar($ecf);
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

        $respuesta = $this->gateway($venta)->consultarTrack($venta->pac_id);
        $this->guardar($venta, $respuesta);

        return $respuesta;
    }

    /**
     * Se deriva de $venta->empresa (cargada de la BD, nunca de Filament::getTenant()): este
     * service lo invoca EnviarEcfJob desde una cola, fuera de cualquier request de panel.
     */
    private function gateway(Venta $venta): DgiiGatewayInterface
    {
        return $this->gatewayFactory->make($venta->empresa);
    }

    private function guardar(Venta $venta, RespuestaEcf $respuesta): void
    {
        $anterior = $venta->estado_fiscal;

        DB::transaction(function () use ($venta, $respuesta) {
            $venta->update($this->atributosParaGuardar($venta, $respuesta));
        });

        $this->notificarSiQuedoRechazada($venta, $anterior);
    }

    private function rechazarPorDatosInvalidos(Venta $venta, EcfInvalidoException $e): RespuestaEcf
    {
        $anterior = $venta->estado_fiscal;

        DB::transaction(function () use ($venta, $e) {
            $venta->update([
                'estado_fiscal' => EstadoFiscal::RECHAZADO,
                'ecf_respuesta' => ['error' => $e->getMessage()],
            ]);
        });

        $this->notificarSiQuedoRechazada($venta, $anterior);

        return new RespuestaEcf(exito: false, errorMessage: $e->getMessage());
    }

    /**
     * Solo en la TRANSICIÓN a RECHAZADO: refrescar el estado de un e-CF que ya estaba rechazado
     * no vuelve a avisar. Después del commit, y NotificacionService nunca lanza.
     */
    private function notificarSiQuedoRechazada(Venta $venta, ?EstadoFiscal $anterior): void
    {
        if ($venta->estado_fiscal !== EstadoFiscal::RECHAZADO || $anterior === EstadoFiscal::RECHAZADO) {
            return;
        }

        $motivo = $venta->ecf_respuesta['error'] ?? null;

        $this->notificaciones->enviar(
            TipoNotificacion::ECF_RECHAZADO,
            $venta->empresa,
            Notification::make()
                ->title("e-CF rechazado: {$venta->ncf}")
                ->body(trim("Venta #{$venta->id} ({$venta->etiquetaComprobante()}). ".($motivo ? Str::limit((string) $motivo, 200) : 'Revisa la respuesta de la DGII en el detalle de la venta.')))
                ->danger(),
        );
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
            'ecf_fecha_firma' => $respuesta->fechaFirma ?? $venta->ecf_fecha_firma ?? $this->fechaFirmaDesdeXml($venta, $respuesta),
            'ecf_respuesta' => $respuesta->responseJson,
        ];
    }

    /**
     * Respaldo cuando el PAC no devuelve la fecha de firma en su respuesta: se lee FechaHoraFirma
     * (dd-MM-yyyy HH:mm:ss) del XML firmado. Solo con el e-CF ya aceptado, y nunca lanza: la
     * fecha de firma es un dato del impreso, no puede tumbar el guardado del estado fiscal.
     */
    private function fechaFirmaDesdeXml(Venta $venta, RespuestaEcf $respuesta): ?Carbon
    {
        $pacId = $respuesta->pacId ?? $venta->pac_id;

        if ($pacId === null || ! $this->mapearEstado($respuesta)->esAceptado()) {
            return null;
        }

        try {
            $xml = $this->gateway($venta)->descargarXml($pacId);

            if (! preg_match('/<FechaHoraFirma>\s*([^<]+?)\s*<\/FechaHoraFirma>/', $xml, $coincidencia)) {
                return null;
            }

            return Carbon::createFromFormat('d-m-Y H:i:s', $coincidencia[1]) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function mapearEstado(RespuestaEcf $respuesta): EstadoFiscal
    {
        return match ($respuesta->estado) {
            'Aceptado' => EstadoFiscal::ACEPTADO,
            'Aceptado Condicional' => EstadoFiscal::ACEPTADO_CONDICIONAL,
            'Rechazado' => EstadoFiscal::RECHAZADO,
            'En Proceso', 'EN_PROCESO' => EstadoFiscal::EN_PROCESO,
            // Consumo (32) por debajo de Venta::UMBRAL_CONSUMO: el PAC lo convirtió a RFCE — es
            // una aceptación final, no un error ni algo que reintentar.
            'RFCE' => EstadoFiscal::RFCE,
            // Estado desconocido o "ERROR_AL_ENVIAR": se deja pendiente para que se reintente.
            default => EstadoFiscal::PENDIENTE,
        };
    }
}
