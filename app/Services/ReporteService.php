<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\TipoDocumentoCliente;
use App\Models\Producto;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Agregaciones para el dashboard y los reportes de gestión. Todos los métodos que miden
 * ingresos excluyen ventas con estado ANULADA: una anulación revierte el efecto fiscal y
 * contable de la venta, así que no debe contar como ingreso.
 */
class ReporteService
{
    /**
     * Código DGII de "Tipo de Ingreso" para el 607 (01 = Ingresos por operaciones, no
     * financieros). Este ERP no distingue tipos de ingreso (financieros, extraordinarios,
     * arrendamientos, etc.): todas las ventas son ingresos operacionales, así que se fija
     * en 01 para todas las filas. Centralizado aquí para ajustarlo fácilmente si en el
     * futuro se necesita derivarlo de otra fuente (p. ej. una configuración).
     */
    public const TIPO_INGRESO_DEFECTO = '01';

    protected function ventasEmitidasEnRango(Carbon $desde, Carbon $hasta): Builder
    {
        return Venta::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('estado', '!=', EstadoVenta::ANULADA);
    }

    /**
     * Todas las ventas del rango (incluye ANULADAs) para el listado del reporte de ventas:
     * a diferencia de los agregados de ingresos, aquí interesa la trazabilidad completa.
     */
    public function ventasEnRangoQuery(Carbon $desde, Carbon $hasta): Builder
    {
        return Venta::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()]);
    }

    /**
     * Base del Formato 607 (Envío de Ventas de Bienes y Servicios) de la DGII: un e-NCF por
     * fila. Regla fiscal (confirmada en la Norma General 07-18 y en la comunidad de ayuda de
     * la DGII): las ventas ANULADAS NO se reportan en el 607 —se excluyen por completo, no
     * solo de los montos— porque un NCF anulado se declara aparte, en el Formato 608 (Ventas
     * Anuladas), junto con el motivo de anulación. Reportarlo también en el 607 duplicaría
     * la operación ante la DGII. Solo se incluyen comprobantes con e-NCF asignado, ya que el
     * 607 es un reporte de comprobantes fiscales emitidos.
     */
    public function reporte607Query(Carbon $desde, Carbon $hasta): Builder
    {
        return Venta::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('estado', '!=', EstadoVenta::ANULADA)
            ->whereNotNull('ncf')
            ->with('cliente');
    }

    /**
     * Una fila por e-NCF emitido en el rango, con el mapeo de columnas del 607.
     *
     * Notas de mapeo (verificadas contra el instructivo oficial de la DGII y su comunidad de
     * ayuda, no solo inferidas):
     * - tipo_identificacion: 1=RNC, 2=Cédula (códigos oficiales del 607). El código oficial 3
     *   es "Pasaporte", que este sistema no captura; para clientes sin documento (consumo por
     *   debajo del umbral de RD$250,000, donde la DGII indica NO solicitar identificación) se
     *   deja en null —el campo va en blanco en el 607 real, no con un código inventado—.
     * - monto_facturado: es la base ANTES de ITBIS (venta.subtotal), no el total. La DGII
     *   documenta "Monto Facturado" como el subtotal sin impuestos; el ITBIS va aparte en
     *   itbis_facturado.
     *
     * @return Collection<int, array{
     *   rnc_cedula: ?string,
     *   tipo_identificacion: ?int,
     *   numero_comprobante: string,
     *   numero_comprobante_modificado: ?string,
     *   tipo_ingreso: string,
     *   fecha_comprobante: Carbon,
     *   monto_facturado: string,
     *   itbis_facturado: string,
     *   monto_total: string,
     * }>
     */
    public function reporte607(Carbon $desde, Carbon $hasta): Collection
    {
        return $this->reporte607Query($desde, $hasta)
            ->orderBy('fecha')
            ->get()
            ->map(fn (Venta $venta) => [
                'rnc_cedula' => $this->rncCedula607($venta->cliente->tipo_documento, $venta->cliente->documento),
                'tipo_identificacion' => $this->tipoIdentificacion607($venta->cliente->tipo_documento),
                'numero_comprobante' => $venta->ncf,
                'numero_comprobante_modificado' => $venta->ncf_modifica,
                'tipo_ingreso' => self::TIPO_INGRESO_DEFECTO,
                'fecha_comprobante' => $venta->fecha,
                'monto_facturado' => (string) $venta->subtotal,
                'itbis_facturado' => (string) $venta->total_itbis,
                'monto_total' => (string) $venta->total,
            ]);
    }

    /**
     * RNC/Cédula del comprador para el 607, según el tipo de documento del cliente. Único
     * punto donde vive esta regla —usado tanto por reporte607() como por la página y los
     * exportadores del 607— para que el mapeo no se desalinee entre pantalla y archivo.
     */
    public function rncCedula607(TipoDocumentoCliente $tipoDocumento, ?string $documento): ?string
    {
        return match ($tipoDocumento) {
            TipoDocumentoCliente::RNC, TipoDocumentoCliente::CEDULA => $documento,
            TipoDocumentoCliente::SIN_DOCUMENTO => null,
        };
    }

    /**
     * Código DGII de "Tipo de Identificación" para el 607: 1=RNC, 2=Cédula. Ver la nota en
     * reporte607() sobre por qué "sin documento" es null y no un código 3 inventado.
     */
    public function tipoIdentificacion607(TipoDocumentoCliente $tipoDocumento): ?int
    {
        return match ($tipoDocumento) {
            TipoDocumentoCliente::RNC => 1,
            TipoDocumentoCliente::CEDULA => 2,
            TipoDocumentoCliente::SIN_DOCUMENTO => null,
        };
    }

    /**
     * Etiqueta legible del código de tipo_identificacion607(), para pantalla/PDF/exportables.
     */
    public function etiquetaTipoIdentificacion607(?int $codigo): string
    {
        return match ($codigo) {
            1 => 'RNC',
            2 => 'Cédula',
            default => '—',
        };
    }

    /**
     * @return array{total_vendido: string, total_itbis: string, cantidad_ventas: int, ticket_promedio: string}
     */
    public function ventasPorRango(Carbon $desde, Carbon $hasta): array
    {
        $fila = $this->ventasEmitidasEnRango($desde, $hasta)
            ->selectRaw('COALESCE(SUM(total), 0) as total_vendido')
            ->selectRaw('COALESCE(SUM(total_itbis), 0) as total_itbis')
            ->selectRaw('COUNT(*) as cantidad_ventas')
            ->first();

        $totalVendido = (string) $fila->total_vendido;
        $cantidadVentas = (int) $fila->cantidad_ventas;

        return [
            'total_vendido' => $totalVendido,
            'total_itbis' => (string) $fila->total_itbis,
            'cantidad_ventas' => $cantidadVentas,
            'ticket_promedio' => $cantidadVentas > 0
                ? bcdiv($totalVendido, (string) $cantidadVentas, 2)
                : '0.00',
        ];
    }

    /**
     * Serie para gráfico: fecha (Y-m-d) => total vendido ese día.
     *
     * @return Collection<string, string>
     */
    public function ventasPorDia(Carbon $desde, Carbon $hasta): Collection
    {
        return $this->ventasEmitidasEnRango($desde, $hasta)
            ->selectRaw('DATE(fecha) as dia')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->mapWithKeys(fn ($fila) => [(string) $fila->dia => (string) $fila->total]);
    }

    public function productosVendidosQuery(Carbon $desde, Carbon $hasta): Builder
    {
        return Producto::query()
            ->join('detalle_ventas', 'detalle_ventas.producto_id', '=', 'productos.id')
            ->join('ventas', 'ventas.id', '=', 'detalle_ventas.venta_id')
            ->whereBetween('ventas.fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('ventas.estado', '!=', EstadoVenta::ANULADA)
            ->groupBy('productos.id', 'productos.codigo', 'productos.nombre')
            ->selectRaw('productos.id, productos.codigo, productos.nombre')
            ->selectRaw('COALESCE(SUM(detalle_ventas.cantidad), 0) as cantidad_vendida')
            ->selectRaw('COALESCE(SUM(detalle_ventas.subtotal), 0) as ingresos');
    }

    /**
     * @return array{por_cantidad: Collection, por_ingresos: Collection}
     */
    public function topProductos(Carbon $desde, Carbon $hasta, int $limite = 10): array
    {
        $base = $this->productosVendidosQuery($desde, $hasta);

        return [
            'por_cantidad' => (clone $base)->orderByDesc('cantidad_vendida')->limit($limite)->get(),
            'por_ingresos' => (clone $base)->orderByDesc('ingresos')->limit($limite)->get(),
        ];
    }

    public function ventasPorClienteQuery(Carbon $desde, Carbon $hasta): Builder
    {
        return $this->ventasEmitidasEnRango($desde, $hasta)
            ->join('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->groupBy('clientes.id', 'clientes.nombre')
            // "id" además de "cliente_id": el modelo base de la consulta sigue siendo Venta,
            // y Filament identifica cada fila de tabla con getKey() (columna "id").
            ->selectRaw('clientes.id as id, clientes.id as cliente_id, clientes.nombre as cliente_nombre')
            ->selectRaw('COALESCE(SUM(ventas.total), 0) as total_vendido')
            ->selectRaw('COUNT(*) as cantidad_ventas');
    }

    public function ventasPorCliente(Carbon $desde, Carbon $hasta): Collection
    {
        return $this->ventasPorClienteQuery($desde, $hasta)->orderByDesc('total_vendido')->get();
    }

    public function ventasPorUsuarioQuery(Carbon $desde, Carbon $hasta): Builder
    {
        return $this->ventasEmitidasEnRango($desde, $hasta)
            ->join('users', 'users.id', '=', 'ventas.user_id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.id as id, users.id as user_id, users.name as user_nombre')
            ->selectRaw('COALESCE(SUM(ventas.total), 0) as total_vendido')
            ->selectRaw('COUNT(*) as cantidad_ventas');
    }

    public function ventasPorUsuario(Carbon $desde, Carbon $hasta): Collection
    {
        return $this->ventasPorUsuarioQuery($desde, $hasta)->orderByDesc('total_vendido')->get();
    }

    /**
     * Conteo por estado_fiscal, separado también por estado (emitida/anulada): incluye
     * anuladas porque el propósito aquí es monitoreo del ciclo fiscal DGII, no ingresos.
     */
    public function ventasPorEstadoFiscal(Carbon $desde, Carbon $hasta): Collection
    {
        return Venta::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->groupBy('estado', 'estado_fiscal')
            ->selectRaw('estado, estado_fiscal, COUNT(*) as cantidad')
            ->orderBy('estado_fiscal')
            ->get()
            ->map(fn ($fila) => [
                'estado' => $fila->estado,
                'estado_fiscal' => $fila->estado_fiscal,
                'cantidad' => (int) $fila->cantidad,
            ]);
    }

    public function valorInventario(): string
    {
        return (string) Producto::query()
            ->where('controla_stock', true)
            ->selectRaw('COALESCE(SUM(costo * stock), 0) as valor')
            ->value('valor');
    }

    public function productosBajoMinimoQuery(): Builder
    {
        return Producto::query()->activos()->bajoMinimo();
    }

    /**
     * @return Collection<int, Producto>
     */
    public function productosBajoMinimo(): Collection
    {
        return $this->productosBajoMinimoQuery()->orderBy('nombre')->get();
    }
}
