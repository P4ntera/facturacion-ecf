<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Http\Controllers\Controller;
use App\Settings\EmpresaSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

abstract class ReportePdfController extends Controller
{
    protected function rangoDesde(Request $request): Carbon
    {
        return Carbon::parse($request->query('desde', now()->startOfMonth()->toDateString()))->startOfDay();
    }

    protected function rangoHasta(Request $request): Carbon
    {
        return Carbon::parse($request->query('hasta', now()->endOfMonth()->toDateString()))->endOfDay();
    }

    /**
     * Suma con bcmath para no arrastrar imprecisión de coma flotante en los totales del PDF.
     * `data_get` soporta tanto modelos Eloquent (objetos) como filas ya mapeadas a array.
     *
     * @param  Collection<int, object|array<string, mixed>>  $items
     */
    protected function sumarBc(Collection $items, string $atributo): string
    {
        return $items->reduce(
            fn (string $acumulado, object|array $item): string => bcadd($acumulado, (string) data_get($item, $atributo), 2),
            '0.00',
        );
    }

    /**
     * @param  array<int, array{key: string, label: string, align?: string}>  $columnas
     * @param  array<int, array<string, string>>  $filas
     * @param  array<string, string>  $totales
     * @param  array<string, string>  $resumen
     */
    protected function responder(
        string $titulo,
        array $columnas,
        array $filas,
        array $totales = [],
        array $resumen = [],
        ?Carbon $desde = null,
        ?Carbon $hasta = null,
    ): Response {
        $pdf = Pdf::loadView('reportes.pdf', [
            'titulo' => $titulo,
            'empresa' => app(EmpresaSettings::class),
            'columnas' => $columnas,
            'filas' => $filas,
            'totales' => $totales,
            'resumen' => $resumen,
            'desde' => $desde,
            'hasta' => $hasta,
        ]);

        return $pdf->stream(Str::slug($titulo).'.pdf');
    }
}
