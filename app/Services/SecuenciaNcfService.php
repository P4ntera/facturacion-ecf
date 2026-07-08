<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TipoComprobante;
use App\Exceptions\RangoNcfSolapadoException;
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

        // El rango activo puede haberse agotado o vencido; en ese caso, antes de rendirnos,
        // buscamos el siguiente rango encolado y consecutivo para continuar sin interrumpir la venta.
        while (! $this->tieneDisponibles($secuencia)) {
            $vencida = $secuencia->vencimiento !== null && $secuencia->vencimiento->isPast();

            $secuencia->activa = false;
            $secuencia->save();

            $siguiente = $this->buscarSiguienteEncolado($tipo, $secuencia);

            if ($siguiente === null) {
                throw new SecuenciaNcfAgotadaException(
                    $vencida
                        ? "La secuencia de NCF del comprobante {$tipo->value} venció el "
                            ."{$secuencia->vencimiento->format('d/m/Y')} y no hay un rango siguiente cargado. "
                            .'Carga un rango vigente.'
                        : "Se agotó la secuencia {$secuencia->prefijo} y no hay un rango siguiente cargado. "
                            .'Carga el próximo rango.'
                );
            }

            $siguiente->activa = true;
            $siguiente->save();

            $secuencia = $siguiente;
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

        if ($secuencia === null) {
            return null;
        }

        if ($this->tieneDisponibles($secuencia)) {
            return $this->formatear($secuencia->prefijo, (int) $secuencia->secuencia_actual);
        }

        // El activo está agotado/vencido: si ya hay un rango consecutivo encolado, el próximo
        // e-NCF real saldrá de ahí en cuanto se consuma (ver siguiente()).
        $siguiente = $this->buscarSiguienteEncolado($tipo, $secuencia);

        if ($siguiente === null || ! $this->tieneDisponibles($siguiente)) {
            return null;
        }

        return $this->formatear($siguiente->prefijo, (int) $siguiente->secuencia_actual);
    }

    public function restantes(SecuenciaNcf $secuencia): int
    {
        if ($secuencia->secuencia_hasta === null) {
            return PHP_INT_MAX;
        }

        return max(0, (int) $secuencia->secuencia_hasta - (int) $secuencia->secuencia_actual + 1);
    }

    /**
     * true si el rango sigue vigente (no vencido) y tiene números disponibles.
     */
    public function tieneDisponibles(SecuenciaNcf $secuencia): bool
    {
        $vigente = $secuencia->vencimiento === null || ! $secuencia->vencimiento->isPast();

        return $vigente && $this->restantes($secuencia) > 0;
    }

    /**
     * Estado visible del rango para la UI: activa, encolada (pendiente), agotada o vencida.
     */
    public function estado(SecuenciaNcf $secuencia): string
    {
        if ($secuencia->vencimiento !== null && $secuencia->vencimiento->isPast()) {
            return 'vencida';
        }

        if ($secuencia->secuencia_hasta !== null && (int) $secuencia->secuencia_actual > (int) $secuencia->secuencia_hasta) {
            return 'agotada';
        }

        return $secuencia->activa ? 'activa' : 'encolada';
    }

    /**
     * Sugiere la próxima "secuencia_desde" para un tipo/prefijo: continúa después del rango
     * cargado más alto, o 1 si todavía no hay ninguno.
     */
    public function sugerirSecuenciaDesde(TipoComprobante $tipo, string $prefijo): int
    {
        $maximoHasta = SecuenciaNcf::query()
            ->where('tipo_comprobante', $tipo)
            ->where('prefijo', $prefijo)
            ->max('secuencia_hasta');

        return $maximoHasta === null ? 1 : (int) $maximoHasta + 1;
    }

    /**
     * true si ya existe un rango activo para ese tipo de comprobante (excluyendo, si aplica,
     * el propio registro que se está editando).
     */
    public function existeRangoActivo(TipoComprobante $tipo, ?int $ignorarId = null): bool
    {
        return SecuenciaNcf::query()
            ->where('tipo_comprobante', $tipo)
            ->where('activa', true)
            ->when($ignorarId, fn ($query) => $query->whereKeyNot($ignorarId))
            ->exists();
    }

    /**
     * Valida que [desde, hasta] no se solape con ningún otro rango existente del mismo
     * tipo_comprobante + prefijo (activo o no). Lanza RangoNcfSolapadoException si hay conflicto.
     */
    public function validarSinSolapamiento(
        TipoComprobante $tipo,
        string $prefijo,
        int $desde,
        int $hasta,
        ?int $ignorarId = null,
    ): void {
        $rangos = SecuenciaNcf::query()
            ->where('tipo_comprobante', $tipo)
            ->where('prefijo', $prefijo)
            ->when($ignorarId, fn ($query) => $query->whereKeyNot($ignorarId))
            ->get();

        foreach ($rangos as $rango) {
            $otroHasta = $rango->secuencia_hasta === null ? PHP_INT_MAX : (int) $rango->secuencia_hasta;
            $otroDesde = (int) $rango->secuencia_desde;

            $seSolapan = $desde <= $otroHasta && $hasta >= $otroDesde;

            if ($seSolapan) {
                throw new RangoNcfSolapadoException(
                    "El rango se solapa con uno existente ({$prefijo} {$otroDesde}–{$rango->secuencia_hasta})."
                );
            }
        }
    }

    /**
     * Activa manualmente un rango encolado. Falla si ya hay otro rango activo del mismo tipo
     * (la invariante es máximo un rango activo por tipo_comprobante en todo momento).
     */
    public function activarManualmente(SecuenciaNcf $secuencia): void
    {
        $secuencia = SecuenciaNcf::query()->whereKey($secuencia->getKey())->lockForUpdate()->firstOrFail();

        if ($this->existeRangoActivo($secuencia->tipo_comprobante, ignorarId: $secuencia->id)) {
            throw new RangoNcfSolapadoException(
                "Ya hay una secuencia activa para el comprobante {$secuencia->tipo_comprobante->value}; "
                .'desactívala antes de activar este rango.'
            );
        }

        $secuencia->activa = true;
        $secuencia->save();
    }

    /** Busca el rango encolado consecutivo (secuencia_desde = hasta_agotado + 1) del mismo tipo. */
    private function buscarSiguienteEncolado(TipoComprobante $tipo, SecuenciaNcf $agotado): ?SecuenciaNcf
    {
        if ($agotado->secuencia_hasta === null) {
            return null;
        }

        return SecuenciaNcf::query()
            ->where('tipo_comprobante', $tipo)
            ->where('activa', false)
            ->where('secuencia_desde', (int) $agotado->secuencia_hasta + 1)
            ->lockForUpdate()
            ->orderBy('secuencia_desde')
            ->first();
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
