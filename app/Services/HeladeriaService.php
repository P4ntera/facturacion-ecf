<?php

declare(strict_types=1);

namespace App\Services;

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

        return $this->ventaService->registrar(
            array_merge([
                'sin_comprobante' => true,
            ], $datosVenta, [
                'lineas' => $lineas,
            ]),
            $empresa,
        );
    }

    /**
     * Obtiene o crea un producto de tipo SERVICIO para un ítem de heladería.
     * Bridge pattern idéntico al de lavandería.
     */
    private function obtenerProductoServicio(Empresa $empresa, string $nombre, float $precio): Producto
    {
        // Generar código único basado en el nombre normalizado
        $codigo = 'HEL-' . mb_strtoupper(str_replace(' ', '-', mb_substr($nombre, 0, 30)));

        return Producto::firstOrCreate(
            [
                'empresa_id' => $empresa->id,
                'codigo' => $codigo,
            ],
            [
                'nombre' => 'Heladería: ' . $nombre,
                'tipo' => TipoProducto::SERVICIO,
                'costo' => 0,
                'precio' => $precio,
                'tasa_itbis' => $empresa->config()->aplica_itbis ? TasaItbis::DIECIOCHO : TasaItbis::CERO,
                'controla_stock' => false,
                'stock' => 0,
                'stock_minimo' => 0,
                'activo' => true,
            ],
        );
    }
}
