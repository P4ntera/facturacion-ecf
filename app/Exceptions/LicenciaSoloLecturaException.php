<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Se intentó escribir datos de negocio con la licencia de la empresa activa no válida. */
class LicenciaSoloLecturaException extends HttpException
{
    public function __construct(?string $tabla = null)
    {
        parent::__construct(
            403,
            'Licencia no válida. El sistema está en modo solo lectura.'
                .($tabla !== null ? " (escritura bloqueada en «{$tabla}»)" : '')
        );
    }
}
