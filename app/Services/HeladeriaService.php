<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FormaPago;
use App\Enums\ModoPrecioHelado;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Models\ComboHelado;
use App\Models\Empresa;
use App\Models\EmpresaConfiguracion;
use App\Models\PresentacionHelado;
use App\Models\Producto;
use App\Models\SaborHelado;
use App\Models\ToppingHelado;
use App\Models\Venta;
use App\Strategies\Impuesto\ConItbisIncluido;
use App\Strategies\Impuesto\SinItbisIncluido;
use App\Support\ComprobanteVenta;
use Illuminate\Support\Str;

/**
 * Calcula precios de helados armados y combos, y delega la facturación a VentaService
 * usando el bridge pattern (auto-crea un Producto tipo SERVICIO para cada ítem del POS).
 */
class HeladeriaService
{
    public function __construct(
        private readonly VentaService $ventaService,
    ) {}

    /**
     * Calcula el precio de un helado armado:
     * - Modo POR_PRESENTACION: precio de la presentación + toppings extras
     * - Modo POR_SCOOP: (scoops × precio_scoop) + presentación base + toppings extras
     *
     * @param  array{sabores: array<int>, toppings: array<int>}  $seleccion
     */
    public function calcularPrecioArmado(
        PresentacionHelado $presentacion,
        array $seleccion,
        Empresa $empresa,
    ): array {
        $config = $empresa->config();
        $modo = $config->modo_precio_helado;

        $saboresIds = $seleccion['sabores'] ?? [];
        $toppingsIds = $seleccion['toppings'] ?? [];

        // Cargar sabores para saber cuáles son premium
        $sabores = SaborHelado::where('empresa_id', $empresa->id)
            ->whereIn('id', $saboresIds)
            ->get();

        $precioBase = $presentacion->precio;
        $precioScoops = 0;
        $precioToppings = 0;
        $descripcion = $presentacion->nombre;

        if ($modo === ModoPrecioHelado::POR_SCOOP) {
            $normales = $sabores->where('premium', false)->count();
            $premium = $sabores->where('premium', true)->count();

            $precioScoops = ($normales * $config->precio_scoop_normal)
                          + ($premium * $config->precio_scoop_premium);
        }

        // Toppings extras (los que exceden los incluidos en la presentación)
        $toppingsExtras = max(0, count($toppingsIds) - $presentacion->toppings_incluidos);

        if ($toppingsExtras > 0) {
            $toppings = ToppingHelado::where('empresa_id', $empresa->id)
                ->whereIn('id', $toppingsIds)
                ->get();

            // Los extras son los últimos seleccionados (los primeros van gratis)
            $precioToppings = $toppings->sortByDesc('precio')
                ->take($toppingsExtras)
                ->sum('precio');
        }

        $total = $precioBase + $precioScoops + $precioToppings;

        // Armar descripción legible
        $nombresSabores = $sabores->pluck('nombre')->implode(', ');
        if ($nombresSabores) {
            $descripcion .= ': ' . $nombresSabores;
        }

        // Incluir toppings en la descripción
        if (! empty($toppingsIds)) {
            $nombresToppings = ToppingHelado::where('empresa_id', $empresa->id)
                ->whereIn('id', $toppingsIds)
                ->pluck('nombre')
                ->implode(', ');

            if ($nombresToppings) {
                $descripcion .= ' + ' . $nombresToppings;
            }
        }

        return [
            'total' => round($total, 2),
            'precio_base' => $precioBase,
            'precio_scoops' => $precioScoops,
            'precio_toppings' => $precioToppings,
            'descripcion' => $descripcion,
        ];
    }

    /**
     * Calcula el precio de un combo (precio fijo).
     */
    public function calcularPrecioCombo(ComboHelado $combo): array
    {
        return [
            'total' => round((float) $combo->precio, 2),
            'descripcion' => $combo->nombre,
        ];
    }

    /**
     * Totales del carrito con la MISMA regla de impuestos que usará VentaService: respeta si la
     * empresa cobra ITBIS y si sus precios ya lo incluyen. El POS sumaba solo precios, así que con
     * ITBIS aparte cobraba un total y registraba otro mayor.
     *
     * @param  array<int, array{tipo?: string, precio: float|int|string, cantidad?: int, detalle?: array}>  $items
     * @return array{subtotal: string, itbis: string, total: string}
     */
    public function calcularTotales(array $items, Empresa $empresa): array
    {
        $config = $empresa->config();
        $estrategia = $config->precio_incluye_itbis ? new ConItbisIncluido : new SinItbisIncluido;

        $directos = Producto::where('empresa_id', $empresa->id)
            ->whereIn('id', collect($items)->pluck('detalle.producto_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $subtotal = '0.00';
        $itbis = '0.00';

        foreach ($items as $item) {
            $productoId = ($item['tipo'] ?? '') === 'directo' ? ($item['detalle']['producto_id'] ?? null) : null;
            $tasaProducto = $productoId !== null && $directos->has($productoId)
                ? $directos[$productoId]->tasa_itbis
                : TasaItbis::DIECIOCHO;
            $tasa = $config->aplica_itbis ? $tasaProducto : TasaItbis::CERO;

            $desglose = $estrategia->calcular(
                number_format((float) $item['precio'], 2, '.', ''),
                (string) max(1, (int) ($item['cantidad'] ?? 1)),
                '0.00',
                $tasa,
            );

            $subtotal = bcadd($subtotal, $desglose->base, 2);
            $itbis = bcadd($itbis, $desglose->itbis, 2);
        }

        return ['subtotal' => $subtotal, 'itbis' => $itbis, 'total' => bcadd($subtotal, $itbis, 2)];
    }

    /**
     * Registra una venta de heladería delegando a VentaService.
     *
     * @param  array<int, array{tipo: string, descripcion: string, precio: float, cantidad: int}>  $items
     */
    public function facturar(array $items, Empresa $empresa, array $datosVenta = []): Venta
    {
        $lineas = collect($items)->map(function (array $item) use ($empresa) {
            // Productos directos ya existen en el catálogo — no crear bridge
            if (($item['tipo'] ?? '') === 'directo' && ! empty($item['detalle']['producto_id'])) {
                $productoId = $item['detalle']['producto_id'];
            } else {
                $producto = $this->obtenerProductoServicio($empresa, $item['descripcion'], $item['precio']);
                $productoId = $producto->id;
            }

            return [
                'producto_id' => $productoId,
                'cantidad' => $item['cantidad'] ?? 1,
                'precio_unitario' => $item['precio'],
            ];
        })->toArray();

        // Comprobante: lo que eligió el cajero o, si no eligió, el que corresponde a la
        // configuración de la empresa (sin comprobante solo si lo tiene habilitado).
        $seleccion = $datosVenta['comprobante'] ?? null;
        unset($datosVenta['comprobante']);

        if (! array_key_exists('sin_comprobante', $datosVenta) && ! array_key_exists('tipo_comprobante', $datosVenta)) {
            $datosVenta = array_merge($datosVenta, ComprobanteVenta::aDatosVenta($seleccion, $empresa));
        }

        // Vuelto calculado en servidor contra el total real: termina en el e-CF
        // (TablaFormasPago declara efectivo − vuelto).
        if (($datosVenta['forma_pago'] ?? null) === FormaPago::EFECTIVO && (float) ($datosVenta['monto_efectivo'] ?? 0) > 0) {
            $total = $this->calcularTotales($items, $empresa)['total'];
            $vuelto = bcsub(number_format((float) $datosVenta['monto_efectivo'], 2, '.', ''), $total, 2);
            $datosVenta['vuelto'] = bccomp($vuelto, '0', 2) > 0 ? $vuelto : null;
        }

        return $this->ventaService->registrar(
            array_merge($datosVenta, ['lineas' => $lineas]),
            $empresa,
        );
    }

    /**
     * Obtiene o crea un producto de tipo SERVICIO para un ítem de heladería.
     * Bridge pattern idéntico al de lavandería.
     */
    private function obtenerProductoServicio(Empresa $empresa, string $nombre, float $precio): Producto
    {
        // Código único por descripción COMPLETA. Antes se usaban los primeros 30 caracteres:
        // dos helados que empezaban igual compartían producto y la factura salía con la
        // descripción del primero que se vendió.
        $codigo = 'HEL-' . mb_strtoupper(Str::slug(mb_substr($nombre, 0, 18))) . '-' . substr(md5(mb_strtolower($nombre)), 0, 10);
        $producto = Producto::firstOrCreate(
            [
                'empresa_id' => $empresa->id,
                'codigo' => $codigo,
            ],
            [
                'nombre' => 'Heladería: ' . $nombre,
                'tipo' => TipoProducto::SERVICIO,
                'costo' => 0,
                'precio' => $precio,
                'tasa_itbis' => TasaItbis::DIECIOCHO,
                'controla_stock' => false,
                'stock' => 0,
                'stock_minimo' => 0,
                'activo' => true,
            ],
        );

        // Los helados son gravados; si la empresa no cobra ITBIS, VentaService ya lo anula por
        // configuración. Se corrige aquí a los productos puente creados con ITBIS apagado, que
        // quedaban en 0 % para siempre aunque la empresa lo activara después.
        if ($producto->tasa_itbis !== TasaItbis::DIECIOCHO) {
            $producto->update(['tasa_itbis' => TasaItbis::DIECIOCHO]);
        }

        return $producto;
    }
}
