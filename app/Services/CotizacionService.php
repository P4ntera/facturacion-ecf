<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoCotizacion;
use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CotizacionService
{
    public function __construct(
        private readonly VentaService $ventaService,
    ) {}

    public function generarNumero(Empresa $empresa): string
    {
        $ultimo = Cotizacion::where('empresa_id', $empresa->id)
            ->orderByDesc('id')
            ->value('numero');

        $siguiente = $ultimo ? ((int) Str::after($ultimo, 'COT-')) + 1 : 1;

        return 'COT-'.str_pad((string) $siguiente, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array{
     *   cliente_id: int|null,
     *   fecha: string,
     *   dias_vigencia: int,
     *   condiciones_pago: string|null,
     *   notas: string|null,
     *   lineas: array<int, array{producto_id: int, cantidad: float|string, precio_unitario: float|string, descuento?: float|string}>
     * } $datos
     */
    public function crear(array $datos, int $userId, Empresa $empresa): Cotizacion
    {
        $datos['lineas'] = array_values(array_filter(
            $datos['lineas'] ?? [],
            fn (array $l) => filled($l['producto_id'] ?? null)
                && filled($l['cantidad'] ?? null)
                && filled($l['precio_unitario'] ?? null),
        ));

        if (empty($datos['lineas'])) {
            throw new RuntimeException('La cotización debe tener al menos una línea.');
        }

        return DB::transaction(function () use ($datos, $userId, $empresa) {
            $diasVigencia = (int) ($datos['dias_vigencia'] ?? 15);
            $fecha = $datos['fecha'];

            $cotizacion = Cotizacion::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $datos['cliente_id'] ?? null,
                'user_id' => $userId,
                'numero' => $this->generarNumero($empresa),
                'fecha' => $fecha,
                'fecha_vencimiento' => now()->parse($fecha)->addDays($diasVigencia)->toDateString(),
                'dias_vigencia' => $diasVigencia,
                'condiciones_pago' => $datos['condiciones_pago'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'estado' => EstadoCotizacion::BORRADOR,
            ]);

            $subtotalTotal = '0';
            $descuentoTotal = '0';
            $itbisTotal = '0';

            foreach ($datos['lineas'] as $linea) {
                $producto = Producto::where('empresa_id', $empresa->id)
                    ->findOrFail($linea['producto_id']);

                $cantidad = (string) $linea['cantidad'];
                $precioUnitario = (string) $linea['precio_unitario'];
                $descuentoLinea = (string) ($linea['descuento'] ?? '0');

                $bruto = bcmul($cantidad, $precioUnitario, 2);
                $subtotal = bcsub($bruto, $descuentoLinea, 2);

                $porcentaje = (string) $producto->tasa_itbis->porcentaje();
                $itbis = bcdiv(bcmul($subtotal, $porcentaje, 4), '100', 2);

                DetalleCotizacion::create([
                    'cotizacion_id' => $cotizacion->id,
                    'producto_id' => $producto->id,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precioUnitario,
                    'descuento' => $descuentoLinea,
                    'itbis' => $itbis,
                    'subtotal' => $subtotal,
                ]);

                $subtotalTotal = bcadd($subtotalTotal, $subtotal, 2);
                $descuentoTotal = bcadd($descuentoTotal, $descuentoLinea, 2);
                $itbisTotal = bcadd($itbisTotal, $itbis, 2);
            }

            $cotizacion->update([
                'subtotal' => $subtotalTotal,
                'descuento' => $descuentoTotal,
                'itbis' => $itbisTotal,
                'total' => bcadd($subtotalTotal, $itbisTotal, 2),
            ]);

            return $cotizacion->refresh();
        });
    }

    public function convertirAVenta(Cotizacion $cotizacion, Empresa $empresa, array $opcionesVenta = []): Venta
    {
        if ((int) $cotizacion->empresa_id !== (int) $empresa->id) {
            throw new RuntimeException('La cotización no pertenece a esta empresa.');
        }

        if (! $cotizacion->puedeConvertirse()) {
            throw new RuntimeException('La cotización no se puede convertir: debe estar aprobada y sin facturar.');
        }

        $cotizacion->load('detalles');

        return DB::transaction(function () use ($cotizacion, $empresa, $opcionesVenta) {
            $lineas = $cotizacion->detalles->map(fn (DetalleCotizacion $d) => [
                'producto_id' => $d->producto_id,
                'cantidad' => (float) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario,
            ])->toArray();

            $venta = $this->ventaService->registrar([
                'cliente_id' => $cotizacion->cliente_id,
                'user_id' => auth()->id(),
                'lineas' => $lineas,
                'tipo_comprobante' => $opcionesVenta['tipo_comprobante'] ?? null,
                'forma_pago' => $opcionesVenta['forma_pago'] ?? null,
                'tipo_pago' => $opcionesVenta['tipo_pago'] ?? null,
                'sin_comprobante' => $opcionesVenta['sin_comprobante'] ?? false,
            ], $empresa);

            $cotizacion->update([
                'venta_id' => $venta->id,
                'estado' => EstadoCotizacion::FACTURADA,
            ]);

            return $venta;
        });
    }

    public function duplicar(Cotizacion $cotizacion, Empresa $empresa): Cotizacion
    {
        if ((int) $cotizacion->empresa_id !== (int) $empresa->id) {
            throw new RuntimeException('La cotización no pertenece a esta empresa.');
        }

        $cotizacion->load('detalles');

        $lineas = $cotizacion->detalles->map(fn (DetalleCotizacion $d) => [
            'producto_id' => $d->producto_id,
            'cantidad' => (string) $d->cantidad,
            'precio_unitario' => (string) $d->precio_unitario,
            'descuento' => (string) $d->descuento,
        ])->toArray();

        return $this->crear([
            'cliente_id' => $cotizacion->cliente_id,
            'fecha' => now()->toDateString(),
            'dias_vigencia' => $cotizacion->dias_vigencia,
            'condiciones_pago' => $cotizacion->condiciones_pago,
            'notas' => $cotizacion->notas,
            'lineas' => $lineas,
        ], auth()->id(), $empresa);
    }

    public function vencerExpiradas(): int
    {
        return Cotizacion::whereIn('estado', [
            EstadoCotizacion::BORRADOR->value,
            EstadoCotizacion::ENVIADA->value,
        ])
            ->where('fecha_vencimiento', '<', now()->startOfDay())
            ->update(['estado' => EstadoCotizacion::VENCIDA->value]);
    }
}
