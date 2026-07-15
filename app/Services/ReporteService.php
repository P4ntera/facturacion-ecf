<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoVenta;
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
    protected function ventasEmitidasEnRango(Carbon $desde, Carbon $hasta): Builder
    {
        return Venta::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('estado', '!=', EstadoVenta::ANULADA);
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

    /**
     * @return array{por_cantidad: Collection, por_ingresos: Collection}
     */
    public function topProductos(Carbon $desde, Carbon $hasta, int $limite = 10): array
    {
        $base = Producto::query()
            ->join('detalle_ventas', 'detalle_ventas.producto_id', '=', 'productos.id')
            ->join('ventas', 'ventas.id', '=', 'detalle_ventas.venta_id')
            ->whereBetween('ventas.fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('ventas.estado', '!=', EstadoVenta::ANULADA)
            ->groupBy('productos.id', 'productos.codigo', 'productos.nombre')
            ->selectRaw('productos.id, productos.codigo, productos.nombre')
            ->selectRaw('COALESCE(SUM(detalle_ventas.cantidad), 0) as cantidad_vendida')
            ->selectRaw('COALESCE(SUM(detalle_ventas.subtotal), 0) as ingresos');

        return [
            'por_cantidad' => (clone $base)->orderByDesc('cantidad_vendida')->limit($limite)->get(),
            'por_ingresos' => (clone $base)->orderByDesc('ingresos')->limit($limite)->get(),
        ];
    }

    public function ventasPorCliente(Carbon $desde, Carbon $hasta): Collection
    {
        return $this->ventasEmitidasEnRango($desde, $hasta)
            ->join('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->groupBy('clientes.id', 'clientes.nombre')
            ->selectRaw('clientes.id as cliente_id, clientes.nombre as cliente_nombre')
            ->selectRaw('COALESCE(SUM(ventas.total), 0) as total_vendido')
            ->selectRaw('COUNT(*) as cantidad_ventas')
            ->orderByDesc('total_vendido')
            ->get();
    }

    public function ventasPorUsuario(Carbon $desde, Carbon $hasta): Collection
    {
        return $this->ventasEmitidasEnRango($desde, $hasta)
            ->join('users', 'users.id', '=', 'ventas.user_id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.id as user_id, users.name as user_nombre')
            ->selectRaw('COALESCE(SUM(ventas.total), 0) as total_vendido')
            ->selectRaw('COUNT(*) as cantidad_ventas')
            ->orderByDesc('total_vendido')
            ->get();
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

    /**
     * @return Collection<int, Producto>
     */
    public function productosBajoMinimo(): Collection
    {
        return Producto::query()
            ->activos()
            ->bajoMinimo()
            ->orderBy('nombre')
            ->get();
    }
}
