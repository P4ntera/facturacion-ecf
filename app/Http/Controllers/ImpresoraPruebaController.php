<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Impresora;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Vista de prueba para impresoras NAVEGADOR: el servidor no puede elegir la impresora física
 * (restricción del navegador), así que solo puede ofrecer el contenido formateado al ancho
 * correcto y disparar el diálogo de impresión (ver window.print() en la vista).
 */
class ImpresoraPruebaController extends Controller
{
    public function __invoke(Request $request, Impresora $impresora): View
    {
        // Ruta fuera del panel de Filament: el scoping automático por tenant no aplica aquí (ver
        // BelongsToTenant de Filament), así que la pertenencia a la empresa se verifica a mano.
        abort_unless($request->user()->perteneceAEmpresa($impresora->empresa_id), 403);

        return view('impresoras.prueba', ['impresora' => $impresora]);
    }
}
