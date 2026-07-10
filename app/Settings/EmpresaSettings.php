<?php

namespace App\Settings;

use Spatie\LaravelSettings\Attributes\ShouldBeEncrypted;
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

    // Se cifra en reposo (Spatie\LaravelSettings\Support\Crypto, igual que el cast 'encrypted' de
    // Eloquent): nunca se guarda ni se registra en texto plano. Se lee ya desencriptada.
    #[ShouldBeEncrypted]
    public ?string $dgii_api_key;

    public string $dgii_ambiente;

    public string $dgii_base_url;

    public static function group(): string
    {
        return 'empresa';
    }
}
