<?php

declare(strict_types=1);

namespace App\Strategies\Impuesto;

trait RedondeoMonetario
{
    /** Redondeo "half up" a 2 decimales (bcadd/bcdiv truncan en vez de redondear). */
    private function redondear(string $numero): string
    {
        $ajuste = bccomp($numero, '0', 6) < 0 ? '-0.005' : '0.005';

        return bcadd($numero, $ajuste, 2);
    }

    private function formatearCantidad(float $cantidad): string
    {
        return number_format($cantidad, 4, '.', '');
    }
}
