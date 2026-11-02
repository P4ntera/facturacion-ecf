<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

abstract class ReportePdfController extends Controller
{
    /**
     * Resuelve la empresa para el reporte:
     *   1) Query param ?empresa= (viene del panel cuando el tenant ≠ la empresa del usuario)
     *   2) empresa_id del usuario autenticado (caso normal: usuario regular en su empresa)
     *
     * El super-admin (empresa_id null) que navega un tenant manda ?empresa= desde el panel;
     * sin ese param no se puede saber qué empresa exportar → 403.
     *
     * Valida que el usuario pertenezca a la empresa, o que sea super-admin, antes de devolver.
     */
    protected function empresaId(Request $request): int
    {
        $empresaId = (int) ($request->query('empresa') ?? $request->user()->empresa_id);

        abort_unless($empresaId > 0, 403, 'No se pudo determinar la empresa.');

        $usuario = $request->user();

        // Super-admin puede acceder a cualquier empresa
        if ($usuario->es_super_admin) {
            // Fijar contexto de permisos a la empresa del reporte
            setPermissionsTeamId($empresaId);
            $usuario->unsetRelation('roles')->unsetRelation('permissions');

            return $empresaId;
        }

        // Usuario regular: solo su propia empresa
        abort_unless($usuario->empresa_id === $empresaId, 403);

        return $empresaId;
    }

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
        Request $request,
        string $titulo,
        array $columnas,
        array $filas,
        array $totales = [],
        array $resumen = [],
        ?Carbon $desde = null,
        ?Carbon $hasta = null,
    ): Response {
        // Resolver la empresa: preferir el param ?empresa (tenant del panel),
        // fallback a la empresa del usuario (caso normal).
        $empresaId = $this->empresaId($request);
        $empresa   = Empresa::findOrFail($empresaId);

        $pdf = Pdf::loadView('reportes.pdf', [
            'titulo' => $titulo,
            'empresa' => $empresa,
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
