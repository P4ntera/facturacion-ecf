<?php

declare(strict_types=1);

namespace App\Strategies\Impuesto;

use App\Enums\TasaItbis;

final readonly class DesgloseLinea
{
    public function __construct(
        public string $base,
        public string $itbis,
        public string $total,
        public TasaItbis $tasa,
    ) {}
}
