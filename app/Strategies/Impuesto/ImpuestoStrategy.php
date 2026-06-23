<?php

namespace App\Strategies\Impuesto;

interface ImpuestoStrategy
{
    public function calcular(float $base): float;
}
