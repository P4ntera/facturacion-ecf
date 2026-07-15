<?php

namespace App\Enums;

enum TipoConexionImpresora: string
{
    // USB/local: el navegador abre el diálogo de impresión y el usuario elige la impresora
    // física — la app no puede seleccionarla (restricción del navegador).
    case NAVEGADOR = 'navegador';

    // IP directa por ESC/POS: el servidor manda los bytes al socket, sin diálogo.
    case RED = 'red';

    public function etiqueta(): string
    {
        return match ($this) {
            self::NAVEGADOR => 'Navegador (USB/local)',
            self::RED => 'Red (IP)',
        };
    }
}
