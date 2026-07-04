<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TipoComprobante;
use App\Exceptions\SecuenciaNcfAgotadaException;
use App\Models\SecuenciaNcf;
use App\Models\User;
use Filament\Notifications\Notification;
use Throwable;

class SecuenciaNcfService
{
    /** Longitud del secuencial en el e-NCF (E + tipo(2) + secuencial(10) = 13). */
    private const LONGITUD_SECUENCIAL = 10;

    /** Umbral de comprobantes restantes para alertar "rango por agotarse". */
    public const UMBRAL_ALERTA = 50;

    /**
     * Asigna y CONSUME el siguiente e-NCF para un tipo de comprobante.
     * Debe ejecutarse DENTRO de una transacción (la abre el llamador, p. ej. VentaService):
     * usa lockForUpdate para que dos ventas simultáneas no tomen el mismo número.
     */
    public function siguiente(TipoComprobante $tipo): string
    {
        $secuencia = SecuenciaNcf::query()
            ->where('tipo_comprobante', $tipo)
            ->where('activa', true)
            ->lockForUpdate()
            ->first();

        if ($secuencia === null) {
            throw new SecuenciaNcfAgotadaException(
                "No hay una secuencia de NCF activa para el comprobante {$tipo->value}. "
                .'Carga un rango autorizado por la DGII.'
            );
        }

        if ($secuencia->vencimiento !== null && $secuencia->vencimiento->isPast()) {
            $secuencia->activa = false;
            $secuencia->save();

            throw new SecuenciaNcfAgotadaException(
                "La secuencia de NCF del comprobante {$tipo->value} venció el "
                ."{$secuencia->vencimiento->format('d/m/Y')}. Carga un rango vigente."
            );
        }

        if ($secuencia->secuencia_hasta !== null
            && (int) $secuencia->secuencia_actual > (int) $secuencia->secuencia_hasta) {
            $secuencia->activa = false;
            $secuencia->save();

            throw new SecuenciaNcfAgotadaException(
                "Se agotó la secuencia de NCF del comprobante {$tipo->value}. "
                .'Carga un nuevo rango autorizado por la DGII.'
            );
        }

        $numero = (int) $secuencia->secuencia_actual;
        $ncf = $this->formatear($secuencia->prefijo, $numero);

        $secuencia->secuencia_actual = $numero + 1;
        $secuencia->save();

        if ($this->restantes($secuencia) <= self::UMBRAL_ALERTA) {
            $this->alertarPorAgotarse($secuencia);
        }

        return $ncf;
    }

    /** Muestra el próximo e-NCF SIN consumirlo (para la UI). Null si no hay disponible. */
    public function previsualizarSiguiente(TipoComprobante $tipo): ?string
    {
        $secuencia = SecuenciaNcf::query()
            ->where('tipo_comprobante', $tipo)
            ->where('activa', true)
            ->first();

        if ($secuencia === null || ! $this->tieneDisponibles($secuencia)) {
            return null;
        }

        return $this->formatear($secuencia->prefijo, (int) $secuencia->secuencia_actual);
    }

    public function restantes(SecuenciaNcf $secuencia): int
    {
        if ($secuencia->secuencia_hasta === null) {
            return PHP_INT_MAX;
        }

        return max(0, (int) $secuencia->secuencia_hasta - (int) $secuencia->secuencia_actual + 1);
    }

    private function tieneDisponibles(SecuenciaNcf $secuencia): bool
    {
        $vigente = $secuencia->vencimiento === null || ! $secuencia->vencimiento->isPast();

        return $vigente && $this->restantes($secuencia) > 0;
    }

    private function formatear(string $prefijo, int $numero): string
    {
        return $prefijo.str_pad((string) $numero, self::LONGITUD_SECUENCIAL, '0', STR_PAD_LEFT);
    }

    private function alertarPorAgotarse(SecuenciaNcf $secuencia): void
    {
        try {
            $destinatarios = User::permission('administrar_secuencias')->get();

            if ($destinatarios->isEmpty()) {
                return;
            }

            $restantes = $this->restantes($secuencia);

            Notification::make()
                ->title("Rango de NCF por agotarse: {$secuencia->tipo_comprobante->etiqueta()} — quedan {$restantes}")
                ->body("El rango {$secuencia->prefijo} tiene {$restantes} comprobante(s) disponible(s). Carga un nuevo rango autorizado por la DGII.")
                ->warning()
                ->sendToDatabase($destinatarios);
        } catch (Throwable) {
            // La alerta nunca debe romper la emisión del comprobante.
        }
    }
}
