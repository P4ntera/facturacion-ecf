<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Impresora;
use Illuminate\Contracts\View\View;

/**
 * Vista de prueba para impresoras NAVEGADOR: el servidor no puede elegir la impresora física
 * (restricción del navegador), así que solo puede ofrecer el contenido formateado al ancho
 * correcto y disparar el diálogo de impresión (ver window.print() en la vista).
 */
class ImpresoraPruebaController extends Controller
{
    public function __invoke(Impresora $impresora): View
    {
        return view('impresoras.prueba', ['impresora' => $impresora]);
    }
}
