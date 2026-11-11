<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TipoComprobante;
use App\Models\Empresa;
use App\Models\Venta;
use App\Services\SecuenciaNcfService;

/**
 * Qué comprobante puede emitir una empresa al cobrar, según SU configuración: ventas sin
 * comprobante habilitadas o no, e-CF activo o no, y secuencias con números disponibles.
 *
 * Lo usan los módulos verticales (lavandería, heladería) para ofrecer las mismas opciones que
 * el POS general en vez de asumir "sin comprobante": una empresa que no lo permite (o que
 * factura con e-CF) no podía cobrar desde esos módulos.
 */
final class ComprobanteVenta
{
    public const SIN_COMPROBANTE = 'sin_comprobante';

    /** @return array<string, string> valor => etiqueta, en el orden en que se ofrecen. */
    public static function opciones(Empresa $empresa): array
    {
        $disponibles = app(SecuenciaNcfService::class)->tiposDisponibles($empresa);

        $tipos = collect(TipoComprobante::cases())
            ->filter(fn (TipoComprobante $tipo) => $tipo->esDeVenta() && ! $tipo->esNotaCredito() && ! $tipo->esNotaDebito())
            ->filter(fn (TipoComprobante $tipo) => in_array($tipo, $disponibles, true))
            ->mapWithKeys(fn (TipoComprobante $tipo) => [$tipo->value => "{$tipo->value} — {$tipo->etiqueta()}"])
            ->all();

        return $empresa->config()->permite_ventas_sin_comprobante
            ? [self::SIN_COMPROBANTE => Venta::ETIQUETA_SIN_COMPROBANTE] + $tipos
            : $tipos;
    }

    /**
     * Selección inicial: "sin comprobante" si la empresa lo permite (cobro rápido, igual que el
     * POS); si no, su tipo por defecto cuando es emitible, o el primero disponible.
     */
    public static function porDefecto(Empresa $empresa): ?string
    {
        $opciones = self::opciones($empresa);

        if (isset($opciones[self::SIN_COMPROBANTE])) {
            return self::SIN_COMPROBANTE;
        }

        $defecto = TipoComprobante::defectoParaEmpresa($empresa->config(), $empresa->usaEcf())->value;

        return isset($opciones[$defecto]) ? $defecto : (array_key_first($opciones) ?? null);
    }

    /**
     * Traduce la selección a las claves que entiende VentaService::registrar(). Sin opciones
     * emitibles devuelve [] para que VentaService resuelva el tipo por defecto y explique él
     * mismo qué falta (secuencia agotada, e-CF desactivado...).
     *
     * @return array{sin_comprobante?: bool, tipo_comprobante?: string}
     */
    public static function aDatosVenta(?string $seleccion, Empresa $empresa): array
    {
        $seleccion = filled($seleccion) ? $seleccion : self::porDefecto($empresa);

        return match (true) {
            $seleccion === null => [],
            $seleccion === self::SIN_COMPROBANTE => ['sin_comprobante' => true],
            default => ['sin_comprobante' => false, 'tipo_comprobante' => $seleccion],
        };
    }
}
