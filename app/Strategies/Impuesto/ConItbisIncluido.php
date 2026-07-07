<?php

declare(strict_types=1);

namespace App\Strategies\Impuesto;

use App\Enums\TasaItbis;

/** El precio unitario del producto YA incluye el ITBIS. */
final class ConItbisIncluido implements ImpuestoStrategy
{
    use RedondeoMonetario;

    public function calcular(string $precioUnitario, float $cantidad, string $descuento, TasaItbis $tasa): DesgloseLinea
    {
        $bruto = $this->redondear(bcmul($precioUnitario, $this->formatearCantidad($cantidad), 6));
        $totalLinea = bcsub($bruto, $descuento, 2);

        if (! $tasa->esGravado()) {
            return new DesgloseLinea(base: $totalLinea, itbis: '0.00', total: $totalLinea, tasa: $tasa);
        }

        $divisor = bcadd('1', bcdiv((string) $tasa->porcentaje(), '100', 6), 6);
        $base = $this->redondear(bcdiv($totalLinea, $divisor, 6));
        // Residual (no redondeo independiente) para que base + itbis cuadre exacto con totalLinea.
        $itbis = bcsub($totalLinea, $base, 2);

        return new DesgloseLinea(base: $base, itbis: $itbis, total: $totalLinea, tasa: $tasa);
    }
}
