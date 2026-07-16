<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Models\Producto;
use App\Services\ReporteService;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

class ReporteInventarioPdfController extends ReportePdfController
{
    public function __invoke(ReporteService $servicio): Response
    {
        $productos = $servicio->productosBajoMinimo();

        $filas = $productos->map(fn (Producto $producto) => [
            'codigo' => $producto->codigo,
            'nombre' => $producto->nombre,
            'stock' => rtrim(rtrim(number_format((float) $producto->stock, 3, '.', ''), '0'), '.'),
            'stock_minimo' => rtrim(rtrim(number_format((float) $producto->stock_minimo, 3, '.', ''), '0'), '.'),
            'costo' => number_format((float) $producto->costo, 2),
        ])->all();

        return $this->responder(
            titulo: 'Reporte de inventario',
            columnas: [
                ['key' => 'codigo', 'label' => 'Código'],
                ['key' => 'nombre', 'label' => 'Producto'],
                ['key' => 'stock', 'label' => 'Stock actual', 'align' => 'text-right'],
                ['key' => 'stock_minimo', 'label' => 'Stock mínimo', 'align' => 'text-right'],
                ['key' => 'costo', 'label' => 'Costo', 'align' => 'text-right'],
            ],
            filas: $filas,
            resumen: [
                'Valor total del inventario' => Number::currency((float) $servicio->valorInventario(), 'DOP'),
                'Productos bajo mínimo' => (string) $productos->count(),
            ],
        );
    }
}
