<?php

declare(strict_types=1);

namespace App\Strategies\Impuesto;

use App\Enums\TasaItbis;

/** El precio unitario del producto NO incluye el ITBIS; se suma aparte. */
final class SinItbisIncluido implements ImpuestoStrategy
{
    use RedondeoMonetario;

    public function calcular(string $precioUnitario, float $cantidad, string $descuento, TasaItbis $tasa): DesgloseLinea
    {
        $base = $this->redondear(bcsub(bcmul($precioUnitario, $this->formatearCantidad($cantidad), 6), $descuento, 6));

        if (! $tasa->esGravado()) {
            return new DesgloseLinea(base: $base, itbis: '0.00', total: $base, tasa: $tasa);
        }

        $itbis = $this->redondear(bcmul($base, bcdiv((string) $tasa->porcentaje(), '100', 6), 6));
        $total = bcadd($base, $itbis, 2);

        return new DesgloseLinea(base: $base, itbis: $itbis, total: $total, tasa: $tasa);
    }
}
