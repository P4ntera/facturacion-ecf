<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class FacturacionSettings extends Settings
{
    public bool $aplica_itbis;

    public bool $precio_incluye_itbis;

    public string $tasa_itbis_defecto;

    public string $tipo_comprobante_defecto;

    public string $moneda;

    public static function group(): string
    {
        return 'facturacion';
    }
}
