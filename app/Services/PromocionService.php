<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TipoPromocion;
use App\Models\Empresa;
use App\Models\Promocion;
use Illuminate\Support\Collection;

/**
 * Resuelve las promociones vigentes aplicables a un conjunto de líneas de venta. Devuelve
 * las líneas con el descuento de promoción ya inyectado en `descuento` (campo monetario de
 * DetalleVenta), sin tocar el descuento global (que sigue siendo responsabilidad de
 * VentaService::resolverDescuentoGlobal()).
 *
 * Para 2×1: duplica la cantidad y descuenta las unidades bonificadas al precio unitario.
 * Para porcentaje/monto fijo: calcula el descuento sobre el bruto de la línea.
 */
class PromocionService
{
    /**
     * Aplica las promociones vigentes a las líneas del carrito.
     *
     * @param  array<int, array<string, mixed>>  $lineas  Líneas del carrito (producto_id, cantidad, precio_unitario, descuento...).
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     *     [0] Líneas con descuento de promoción aplicado.
     *     [1] Info de promociones aplicadas (para mostrar en el POS).
     */
    public function aplicar(array $lineas, Empresa $empresa, ?string $fecha = null): array
    {
        $fecha ??= now()->toDateString();

        $productosIds = array_filter(array_column($lineas, 'producto_id'));

        if (empty($productosIds)) {
            return [$lineas, []];
        }

        // Cargar todas las promociones vigentes que aplican a alguno de los productos del carrito.
        $promociones = Promocion::where('empresa_id', $empresa->id)
            ->vigentes($fecha)
            ->whereHas('productos', fn ($q) => $q->whereIn('productos.id', $productosIds))
            ->with(['productos' => fn ($q) => $q->whereIn('productos.id', $productosIds)])
            ->get();

        if ($promociones->isEmpty()) {
            return [$lineas, []];
        }

        // Indexar: producto_id → mejor promoción (si un producto tiene varias vigentes,
        // se elige la que dé mayor descuento — calculado por línea más abajo).
        $promoPorProducto = $this->indexarMejorPromocion($promociones, $lineas);

        $promocionesAplicadas = [];

        $lineas = array_map(function (array $linea) use ($promoPorProducto, &$promocionesAplicadas) {
            $productoId = $linea['producto_id'] ?? null;

            if ($productoId === null || ! isset($promoPorProducto[$productoId])) {
                return $linea;
            }

            $promo = $promoPorProducto[$productoId];
            $cantidad = (string) ($linea['cantidad'] ?? '0');
            $precioUnitario = (string) ($linea['precio_unitario'] ?? '0');
            $descuentoExistente = (string) ($linea['descuento'] ?? '0');

            $resultado = match ($promo->tipo) {
                TipoPromocion::PORCENTAJE => $this->aplicarPorcentaje($precioUnitario, $cantidad, $descuentoExistente, $promo),
                TipoPromocion::MONTO_FIJO => $this->aplicarMontoFijo($precioUnitario, $cantidad, $descuentoExistente, $promo),
                TipoPromocion::DOS_POR_UNO => $this->aplicarDosPorUno($precioUnitario, $cantidad, $descuentoExistente),
            };

            $linea['descuento'] = $resultado['descuento'];
            $linea['cantidad'] = $resultado['cantidad'];
            $linea['_promocion_id'] = $promo->id;
            $linea['_promocion_nombre'] = $promo->nombre;
            $linea['_promocion_tipo'] = $promo->tipo->value;

            $promocionesAplicadas[] = [
                'promocion_id' => $promo->id,
                'nombre' => $promo->nombre,
                'tipo' => $promo->tipo->value,
                'producto_id' => $productoId,
                'descuento_aplicado' => $resultado['descuento'],
            ];

            return $linea;
        }, $lineas);

        return [$lineas, $promocionesAplicadas];
    }

    /**
     * Devuelve las promociones vigentes para una empresa, con sus productos, para mostrar
     * en la UI del POS.
     *
     * @return Collection<int, Promocion>
     */
    public function vigentes(Empresa $empresa, ?string $fecha = null): Collection
    {
        $fecha ??= now()->toDateString();

        return Promocion::where('empresa_id', $empresa->id)
            ->vigentes($fecha)
            ->with('productos:id,nombre,codigo')
            ->orderBy('nombre')
            ->get();
    }

    // ── Tipos de descuento ──────────────────────────────────────

    /**
     * Descuento porcentual: valor% sobre el bruto de la línea.
     *
     * @return array{descuento: string, cantidad: string}
     */
    private function aplicarPorcentaje(string $precioUnitario, string $cantidad, string $descuentoExistente, Promocion $promo): array
    {
        $bruto = bcmul($precioUnitario, $cantidad, 4);
        $descuentoPromo = bcround(bcdiv(bcmul($bruto, (string) $promo->valor, 4), '100', 4));

        // No exceder el bruto
        $descuentoTotal = bcadd($descuentoExistente, $descuentoPromo, 2);
        if (bccomp($descuentoTotal, $bruto, 2) > 0) {
            $descuentoTotal = $bruto;
        }

        return ['descuento' => $descuentoTotal, 'cantidad' => $cantidad];
    }

    /**
     * Descuento de monto fijo: se resta $promo->valor por cada unidad.
     *
     * @return array{descuento: string, cantidad: string}
     */
    private function aplicarMontoFijo(string $precioUnitario, string $cantidad, string $descuentoExistente, Promocion $promo): array
    {
        $descuentoPromo = bcround(bcmul((string) $promo->valor, $cantidad, 4));
        $bruto = bcmul($precioUnitario, $cantidad, 4);

        $descuentoTotal = bcadd($descuentoExistente, $descuentoPromo, 2);
        if (bccomp($descuentoTotal, $bruto, 2) > 0) {
            $descuentoTotal = $bruto;
        }

        return ['descuento' => $descuentoTotal, 'cantidad' => $cantidad];
    }

    /**
     * 2×1: por cada par de unidades, una es gratis. La cantidad no cambia; el descuento es
     * el precio de las unidades bonificadas.
     *
     * Ejemplo: cantidad 5 a $100 → 2 unidades gratis (pares: 2), descuento = 2 × $100 = $200.
     *
     * @return array{descuento: string, cantidad: string}
     */
    private function aplicarDosPorUno(string $precioUnitario, string $cantidad, string $descuentoExistente): array
    {
        // Unidades bonificadas = floor(cantidad / 2)
        $cantidadEntera = (int) floor((float) $cantidad);
        $unidadesGratis = (string) intdiv($cantidadEntera, 2);

        $descuentoPromo = bcround(bcmul($precioUnitario, $unidadesGratis, 4));
        $bruto = bcmul($precioUnitario, $cantidad, 4);

        $descuentoTotal = bcadd($descuentoExistente, $descuentoPromo, 2);
        if (bccomp($descuentoTotal, $bruto, 2) > 0) {
            $descuentoTotal = $bruto;
        }

        return ['descuento' => $descuentoTotal, 'cantidad' => $cantidad];
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Si un producto tiene varias promociones vigentes, elige la que dé mayor descuento.
     *
     * @return array<int, Promocion>  producto_id → Promocion ganadora
     */
    private function indexarMejorPromocion(Collection $promociones, array $lineas): array
    {
        // Indexar líneas por producto_id para calcular el descuento hipotético
        $lineasPorProducto = [];
        foreach ($lineas as $linea) {
            $pid = $linea['producto_id'] ?? null;
            if ($pid !== null) {
                $lineasPorProducto[$pid] = $linea;
            }
        }

        $mejores = [];

        foreach ($promociones as $promo) {
            foreach ($promo->productos as $producto) {
                $pid = $producto->id;
                $linea = $lineasPorProducto[$pid] ?? null;

                if ($linea === null) {
                    continue;
                }

                $cantidad = (string) ($linea['cantidad'] ?? '0');
                $precioUnitario = (string) ($linea['precio_unitario'] ?? (string) $producto->precio);

                $descuento = $this->calcularDescuentoHipotetico($promo, $precioUnitario, $cantidad);

                if (! isset($mejores[$pid]) || bccomp($descuento, $mejores[$pid]['monto'], 2) > 0) {
                    $mejores[$pid] = ['promo' => $promo, 'monto' => $descuento];
                }
            }
        }

        return array_map(fn ($entry) => $entry['promo'], $mejores);
    }

    private function calcularDescuentoHipotetico(Promocion $promo, string $precioUnitario, string $cantidad): string
    {
        return match ($promo->tipo) {
            TipoPromocion::PORCENTAJE => bcround(bcdiv(bcmul(bcmul($precioUnitario, $cantidad, 4), (string) $promo->valor, 4), '100', 4)),
            TipoPromocion::MONTO_FIJO => bcround(bcmul((string) $promo->valor, $cantidad, 4)),
            TipoPromocion::DOS_POR_UNO => bcround(bcmul($precioUnitario, (string) intdiv((int) floor((float) $cantidad), 2), 4)),
        };
    }
}
