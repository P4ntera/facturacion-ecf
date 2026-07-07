<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class EmpresaSettings extends Settings
{
    public string $rnc;

    public string $razon_social;

    public string $nombre_comercial;

    public ?string $direccion;

    public ?string $telefono;

    public ?string $email;

    public ?string $logo;

    public static function group(): string
    {
        return 'empresa';
    }
}
