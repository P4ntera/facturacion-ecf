<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TipoComprobante;
use App\Exceptions\RangoNcfSolapadoException;
use App\Exceptions\SecuenciaNcfAgotadaException;
use App\Models\Empresa;
use App\Models\SecuenciaNcf;
use App\Models\User;
use Filament\Notifications\Notification;
use Throwable;

class SecuenciaNcfService
{
    /** Longitud del secuencial en el e-NCF (E + tipo(2) + secuencial(10) = 13). */
    private const LONGITUD_SECUENCIAL_ELECTRONICA = 10;

    /** Longitud del secuencial en el NCF físico (B + tipo(2) + secuencial(8) = 11). */
    private const LONGITUD_SECUENCIAL_FISICA = 8;

    /** Umbral de comprobantes restantes para alertar "rango por agotarse". */
    public const UMBRAL_ALERTA = 50;

    /**
     * Asigna y CONSUME el siguiente e-NCF para un tipo de comprobante.
     * Debe ejecutarse DENTRO de una transacción (la abre el llamador, p. ej. VentaService):
     * usa lockForUpdate para que dos ventas simultáneas no tomen el mismo número.
     *
     * $empresa la resuelve el llamador explícitamente (igual que VentaService/CompraService):
     * sin filtrar por empresa_id aquí, dos empresas con secuencias activas del mismo
     * tipo_comprobante podrían "robarse" el contador la una a la otra.
     */
    public function siguiente(TipoComprobante $tipo, Empresa $empresa): string
    {
        $secuencia = SecuenciaNcf::query()
            ->where('empresa_id', $empresa->id)
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

            $siguiente = $this->buscarSiguienteEncolado($tipo, $secuencia, $empresa);

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
        $ncf = $this->formatear($secuencia->prefijo, $numero, $tipo);

        $secuencia->secuencia_actual = $numero + 1;
        $secuencia->save();

        // alerta_agotamiento_enviada_en deduplica: sin esto, cada consumo bajo el umbral (podrían
        // ser decenas por hora en un negocio con volumen) generaría una notificación nueva.
        if ($this->restantes($secuencia) <= self::UMBRAL_ALERTA && $secuencia->alerta_agotamiento_enviada_en === null) {
            $this->alertarPorAgotarse($secuencia);
            $secuencia->update(['alerta_agotamiento_enviada_en' => now()]);
        }

        return $ncf;
    }

    /** Muestra el próximo e-NCF SIN consumirlo (para la UI). Null si no hay disponible. */
    public function previsualizarSiguiente(TipoComprobante $tipo, Empresa $empresa): ?string
    {
        $secuencia = SecuenciaNcf::query()
            ->where('empresa_id', $empresa->id)
            ->where('tipo_comprobante', $tipo)
            ->where('activa', true)
            ->first();

        if ($secuencia === null) {
            return null;
        }

        if ($this->tieneDisponibles($secuencia)) {
            return $this->formatear($secuencia->prefijo, (int) $secuencia->secuencia_actual, $tipo);
        }

        // El activo está agotado/vencido: si ya hay un rango consecutivo encolado, el próximo
        // e-NCF real saldrá de ahí en cuanto se consuma (ver siguiente()).
        $siguiente = $this->buscarSiguienteEncolado($tipo, $secuencia, $empresa);

        if ($siguiente === null || ! $this->tieneDisponibles($siguiente)) {
            return null;
        }

        return $this->formatear($siguiente->prefijo, (int) $siguiente->secuencia_actual, $tipo);
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
    public function sugerirSecuenciaDesde(TipoComprobante $tipo, string $prefijo, Empresa $empresa): int
    {
        $maximoHasta = SecuenciaNcf::query()
            ->where('empresa_id', $empresa->id)
            ->where('tipo_comprobante', $tipo)
            ->where('prefijo', $prefijo)
            ->max('secuencia_hasta');

        return $maximoHasta === null ? 1 : (int) $maximoHasta + 1;
    }

    /**
     * true si ya existe un rango activo para ese tipo de comprobante (excluyendo, si aplica,
     * el propio registro que se está editando).
     */
    public function existeRangoActivo(TipoComprobante $tipo, Empresa $empresa, ?int $ignorarId = null): bool
    {
        return SecuenciaNcf::query()
            ->where('empresa_id', $empresa->id)
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
        Empresa $empresa,
        ?int $ignorarId = null,
    ): void {
        $rangos = SecuenciaNcf::query()
            ->where('empresa_id', $empresa->id)
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
        // empresa_id se deriva del propio $secuencia (entidad ya validada), no de un parámetro
        // separado ni de Filament::getTenant(): este método puede invocarse fuera del ciclo de
        // vida de una request de panel.
        $secuencia = SecuenciaNcf::query()
            ->where('empresa_id', $secuencia->empresa_id)
            ->whereKey($secuencia->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($this->existeRangoActivo($secuencia->tipo_comprobante, $secuencia->empresa, ignorarId: $secuencia->id)) {
            throw new RangoNcfSolapadoException(
                "Ya hay una secuencia activa para el comprobante {$secuencia->tipo_comprobante->value}; "
                .'desactívala antes de activar este rango.'
            );
        }

        $secuencia->activa = true;
        $secuencia->save();
    }

    /** Busca el rango encolado consecutivo (secuencia_desde = hasta_agotado + 1) del mismo tipo. */
    private function buscarSiguienteEncolado(TipoComprobante $tipo, SecuenciaNcf $agotado, Empresa $empresa): ?SecuenciaNcf
    {
        if ($agotado->secuencia_hasta === null) {
            return null;
        }

        return SecuenciaNcf::query()
            ->where('empresa_id', $empresa->id)
            ->where('tipo_comprobante', $tipo)
            ->where('activa', false)
            ->where('secuencia_desde', (int) $agotado->secuencia_hasta + 1)
            ->lockForUpdate()
            ->orderBy('secuencia_desde')
            ->first();
    }

    private function formatear(string $prefijo, int $numero, TipoComprobante $tipo): string
    {
        $longitud = $tipo->esElectronico() ? self::LONGITUD_SECUENCIAL_ELECTRONICA : self::LONGITUD_SECUENCIAL_FISICA;

        return $prefijo.str_pad((string) $numero, $longitud, '0', STR_PAD_LEFT);
    }

    private function alertarPorAgotarse(SecuenciaNcf $secuencia): void
    {
        try {
            $destinatarios = User::permission('secuencias.administrar')->get();

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
