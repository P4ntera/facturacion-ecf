<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Modulo;
use App\Models\Empresa;
use App\Models\EmpresaModulo;

/** Siembra los módulos de una empresa nueva, todos habilitados por defecto. */
class ModulosEmpresaService
{
    public function sembrarModulos(Empresa $empresa): void
    {
        foreach (Modulo::cases() as $modulo) {
            EmpresaModulo::firstOrCreate([
                'empresa_id' => $empresa->id,
                'modulo' => $modulo,
            ], [
                'habilitado' => true,
            ]);
        }
    }
}
