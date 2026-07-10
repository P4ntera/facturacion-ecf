<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CanalRecepcionEcf;

/** URL a registrar en la DGII como endpoint de "Recepción". Ver docs/dgii-recepcion.md. */
class RecepcionEcfController extends RecepcionEcfControllerBase
{
    protected function canal(): CanalRecepcionEcf
    {
        return CanalRecepcionEcf::RECEPCION;
    }
}
