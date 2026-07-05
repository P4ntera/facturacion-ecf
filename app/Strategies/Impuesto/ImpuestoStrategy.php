<?php

declare(strict_types=1);

namespace App\Strategies\Impuesto;

use App\Enums\TasaItbis;

interface ImpuestoStrategy
{
    /**
     * Calcula el desglose (base/itbis/total) de una línea de venta.
     *
     * @param  string  $precioUnitario  Dinero, escala 2.
     * @param  float  $cantidad  Cantidad del producto (puede tener decimales).
     * @param  string  $descuento  Dinero, escala 2.
     */
    public function calcular(string $precioUnitario, float $cantidad, string $descuento, TasaItbis $tasa): DesgloseLinea;
}
