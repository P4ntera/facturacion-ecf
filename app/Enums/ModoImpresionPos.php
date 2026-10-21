<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Determina qué pasa con el ticket al cobrar en el POS:
 *
 * - DIRECTA: si hay impresora de red resuelta, envía ESC/POS directo al socket (sin diálogo);
 *   si no hay impresora, cae al navegador igual que PREVISUALIZAR.
 * - PREVISUALIZAR: siempre abre el ticket en una pestaña nueva para que el cajero elija la
 *   impresora desde el diálogo del navegador — ignora la impresora de red aunque esté configurada.
 */
enum ModoImpresionPos: string
{
    case DIRECTA = 'directa';
    case PREVISUALIZAR = 'previsualizar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::DIRECTA => 'Directa (impresora de red)',
            self::PREVISUALIZAR => 'Previsualizar en navegador',
        };
    }
}
