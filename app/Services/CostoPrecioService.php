<?php

namespace App\Services;

use App\Enums\MetodoCosto;
use App\Enums\TipoVenta;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Costo del inventario y precio sugerido.
 *
 * Costo: Producto::costo se actualiza al entrar mercancía con costo conocido, según el
 * MetodoCosto que la empresa eligió. Toda la aritmética va en bcmath (escala 6 en los pasos
 * intermedios, half-up a 2 al final) para no acumular errores de float.
 *
 * Precio sugerido: costo × (1 + porcentaje de ganancia), más ITBIS si la empresa maneja precios
 * con ITBIS incluido, redondeado hacia arriba según la configuración. Nunca se aplica solo: el
 * usuario revisa las sugerencias y elige cuáles aplicar (aplicarPrecios()).
 */
class CostoPrecioService
{
    /**
     * Actualiza el costo del producto tras una ENTRADA de mercancía (compra o recepción de orden).
     * Llamar después de InventarioService::registrarMovimiento() y dentro de la misma transacción:
     * la fila del producto ya está bloqueada, y $movimiento trae el stock que había antes.
     *
     * @param  MovimientoInventario|null  $movimiento  null si el producto no controla stock.
     */
    public function aplicarEntrada(
        Producto $producto,
        ?MovimientoInventario $movimiento,
        string|float|int $cantidad,
        string|float|int $costoUnitario,
        Empresa $empresa,
    ): void {
        $costoEntrada = $this->dinero($costoUnitario);

        $nuevoCosto = match ($empresa->config()->metodo_costo) {
            MetodoCosto::MANUAL => null,
            MetodoCosto::ULTIMA_COMPRA => $costoEntrada,
            MetodoCosto::PROMEDIO_PONDERADO => $this->promedioTrasEntrada($producto, $movimiento, $this->decimal($cantidad), $costoEntrada),
        };

        if ($nuevoCosto !== null) {
            Producto::whereKey($producto->id)->update(['costo' => $nuevoCosto]);
        }
    }

    /**
     * Deshace el efecto de una entrada en el costo promedio (anulación de una compra). Solo aplica
     * a PROMEDIO_PONDERADO: con ULTIMA_COMPRA no se sabe cuál era el costo "anterior" (se mantiene
     * el vigente, igual que antes de este cambio) y con MANUAL las compras nunca lo tocaron.
     *
     * @param  MovimientoInventario|null  $movimiento  la SALIDA de la anulación (stock antes/después).
     */
    public function revertirEntrada(
        Producto $producto,
        ?MovimientoInventario $movimiento,
        string|float|int $cantidad,
        string|float|int $costoUnitario,
        Empresa $empresa,
    ): void {
        if ($empresa->config()->metodo_costo !== MetodoCosto::PROMEDIO_PONDERADO || $movimiento === null) {
            return;
        }

        $stockRestante = $this->decimal($movimiento->stock_nuevo);
        $stockAntes = $this->decimal($movimiento->stock_anterior);

        // Si no queda nada en almacén, no hay promedio que recalcular: se deja el costo vigente.
        if (bccomp($stockRestante, '0', 6) <= 0) {
            return;
        }

        $costoActual = $this->decimal(Producto::whereKey($producto->id)->value('costo'));
        $valorRestante = bcsub(
            bcmul($stockAntes, $costoActual, 6),
            bcmul($this->decimal($cantidad), $this->dinero($costoUnitario), 6),
            6
        );

        // Un valor negativo o cero significa que el resto se compró más barato de lo que el
        // promedio sugiere (redondeos acumulados): no se fuerza un costo absurdo.
        if (bccomp($valorRestante, '0', 6) <= 0) {
            return;
        }

        Producto::whereKey($producto->id)->update([
            'costo' => $this->redondear2(bcdiv($valorRestante, $stockRestante, 6)),
        ]);
    }

    /** Porcentaje de ganancia que aplica al producto: el suyo propio, o el de su categoría. */
    public function margenEfectivo(Producto $producto): ?string
    {
        $margen = $producto->margen_ganancia ?? $producto->categoria?->margen_ganancia;

        return $margen === null ? null : $this->decimal($margen);
    }

    /** Campo de precio que se sugiere: los productos pesados se venden por unidad de peso. */
    public function campoPrecio(Producto $producto): string
    {
        return $producto->tipo_venta === TipoVenta::PESADO ? 'precio_por_peso' : 'precio';
    }

    /** Precio sugerido para el producto, o null si no tiene porcentaje de ganancia o costo. */
    public function precioSugerido(Producto $producto, Empresa $empresa): ?string
    {
        $margen = $this->margenEfectivo($producto);
        $costo = $this->decimal($producto->costo);

        if ($margen === null || bccomp($costo, '0', 6) <= 0) {
            return null;
        }

        $precio = bcmul($costo, bcadd('1', bcdiv($margen, '100', 6), 6), 6);

        $config = $empresa->config();
        if ($config->aplica_itbis && $config->precio_incluye_itbis) {
            $tasa = number_format($producto->tasa_itbis->porcentaje(), 2, '.', '');
            $precio = bcmul($precio, bcadd('1', bcdiv($tasa, '100', 6), 6), 6);
        }

        $multiplo = $config->redondeo_precio->multiplo();

        return $multiplo === null ? $this->redondear2($precio) : $this->redondearArriba($precio, $multiplo);
    }

    /**
     * Productos de la compra cuyo precio sugerido es distinto del precio actual.
     *
     * @return Collection<int, array{producto: Producto, campo: string, precio_actual: string, precio_sugerido: string}>
     */
    public function sugerenciasParaCompra(Compra $compra): Collection
    {
        $empresa = $compra->empresa;

        $productos = Producto::query()
            ->where('empresa_id', $compra->empresa_id)
            ->whereIn('id', $compra->detalles()->select('producto_id'))
            ->with('categoria')
            ->orderBy('nombre')
            ->get();

        return $productos
            ->map(function (Producto $producto) use ($empresa): ?array {
                $sugerido = $this->precioSugerido($producto, $empresa);
                $campo = $this->campoPrecio($producto);
                $actual = $this->dinero($producto->{$campo} ?? 0);

                if ($sugerido === null || bccomp($sugerido, $actual, 2) === 0) {
                    return null;
                }

                return [
                    'producto' => $producto,
                    'campo' => $campo,
                    'precio_actual' => $actual,
                    'precio_sugerido' => $sugerido,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Aplica los precios que el usuario aprobó. Los ids vienen del formulario (client-controllable):
     * solo se tocan productos de $empresa.
     *
     * @param  array<int|string, string|float|int>  $precios  producto_id => precio nuevo
     * @return int cantidad de productos actualizados
     */
    public function aplicarPrecios(array $precios, Empresa $empresa): int
    {
        $actualizados = 0;

        foreach ($precios as $productoId => $precio) {
            $precio = $this->dinero($precio);

            if (bccomp($precio, '0', 2) <= 0) {
                throw new RuntimeException('El precio de venta debe ser mayor que cero.');
            }

            $producto = Producto::where('empresa_id', $empresa->id)->find($productoId);

            if ($producto === null) {
                throw new RuntimeException("El producto #{$productoId} no existe.");
            }

            $producto->update([$this->campoPrecio($producto) => $precio]);
            $actualizados++;
        }

        return $actualizados;
    }

    private function promedioTrasEntrada(Producto $producto, ?MovimientoInventario $movimiento, string $cantidad, string $costoEntrada): string
    {
        // Sin control de stock (servicios) no hay existencias que promediar.
        if ($movimiento === null) {
            return $costoEntrada;
        }

        $stockAntes = $this->decimal($movimiento->stock_anterior);

        // Con el almacén vacío (o en negativo) lo que había no tiene valor que mezclar: el costo
        // es el de lo que entra. Es la regla estándar para que el promedio no salga absurdo.
        if (bccomp($stockAntes, '0', 6) <= 0) {
            return $costoEntrada;
        }

        $costoActual = $this->decimal(Producto::whereKey($producto->id)->value('costo'));
        $valor = bcadd(bcmul($stockAntes, $costoActual, 6), bcmul($cantidad, $costoEntrada, 6), 6);

        return $this->redondear2(bcdiv($valor, bcadd($stockAntes, $cantidad, 6), 6));
    }

    private function decimal(string|float|int|null $valor): string
    {
        if ($valor === null || $valor === '') {
            return '0';
        }

        return is_float($valor) ? number_format($valor, 6, '.', '') : bcadd((string) $valor, '0', 6);
    }

    private function dinero(string|float|int|null $valor): string
    {
        return $this->redondear2($this->decimal($valor));
    }

    /** Half-up a 2 decimales (bcmath trunca). */
    private function redondear2(string $valor): string
    {
        $ajuste = bccomp($valor, '0', 6) < 0 ? '-0.005' : '0.005';

        return bcadd($valor, $ajuste, 2);
    }

    /** Hacia arriba al múltiplo: 63.40 con múltiplo 5 → 65.00; 65.00 se queda en 65.00. */
    private function redondearArriba(string $valor, int $multiplo): string
    {
        $m = (string) $multiplo;
        $veces = bcdiv($valor, $m, 0);

        if (bccomp(bcmul($veces, $m, 6), $valor, 6) < 0) {
            $veces = bcadd($veces, '1', 0);
        }

        return bcmul($veces, $m, 2);
    }
}
