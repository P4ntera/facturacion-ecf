<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Modulo;
use App\Enums\PerfilEmpresa;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Models\Categoria;
use App\Models\Descuento;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use Database\Seeders\Concerns\SiembraEmpresaDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Veterinaria / Pet Shop demo según la "Guía de Pruebas — Veterinaria / Pet Shop":
 * perfil RETAIL, productos con stock + servicios sin stock en las mismas categorías, venta por
 * peso, presentaciones con factor y descuento del 10 %.
 *
 * Deja listo el catálogo de las Fases 1 a 4 de la guía; las ventas de la Fase 5 NO se siembran,
 * porque la guía las hace a mano en el POS y verifica el stock esperado partiendo del inicial.
 *
 * Tres escenarios fiscales (ver SiembraEmpresaDemo):
 *  - Pet Shop Patitas      (informal) — sin ITBIS, sin comprobante.
 *  - Pet Shop Vet Center   (tipo B)   — ITBIS incluido, B01/B02.
 *  - Clínica Veterinaria   (e-CF)     — ITBIS aparte, E31/E32.
 *
 * El perfil RETAIL solo trae los módulos base; se habilitan además los que la guía usa
 * (descuentos, kardex, arqueo, secuencias, cuentas por cobrar).
 *
 *   sail artisan db:seed --class=VeterinariaDemoSeeder
 */
class VeterinariaDemoSeeder extends Seeder
{
    use SiembraEmpresaDemo;

    private const MODULOS_EXTRA = [
        Modulo::MAESTROS_DESCUENTOS,
        Modulo::VENTAS_ARQUEO_CAJA,
        Modulo::INVENTARIO_KARDEX,
        Modulo::ECF_SECUENCIAS,
        Modulo::CUENTAS_POR_COBRAR,
    ];

    public function run(): void
    {
        $this->abortarEnProduccion();

        $this->command->info('Veterinaria / Pet Shop demo:');

        $this->sembrarEscenario(self::ESCENARIO_INFORMAL, [
            'razon_social' => 'Pet Shop Patitas',
            'nombre_comercial' => 'Pet Shop Patitas',
            'rnc' => null,
            'slug' => 'demo-veterinaria-informal',
            'email_admin' => 'admin@vet-informal.test',
        ], 'vet-informal', '0000301');

        $this->sembrarEscenario(self::ESCENARIO_TIPO_B, [
            'razon_social' => 'Pet Shop Vet Center SRL',
            'nombre_comercial' => 'Pet Shop Vet Center',
            'rnc' => '131900301',
            'slug' => 'demo-veterinaria-tipo-b',
            'email_admin' => 'admin@vet-tipob.test',
        ], 'vet-tipob', '0000302');

        $this->sembrarEscenario(self::ESCENARIO_ECF, [
            'razon_social' => 'Clínica Veterinaria San Francisco SRL',
            'nombre_comercial' => 'Clínica Veterinaria',
            'rnc' => '131900302',
            'slug' => 'demo-veterinaria-ecf',
            'email_admin' => 'admin@vet-ecf.test',
        ], 'vet-ecf', '0000303');
    }

    /** @param  array<string, ?string>  $datos */
    private function sembrarEscenario(string $escenario, array $datos, string $dominio, string $sufijoRnc): void
    {
        [$empresa, $admin] = $this->crearEmpresaDemo(PerfilEmpresa::RETAIL, $escenario, $datos, self::MODULOS_EXTRA);

        $this->crearUsuarioDemo($empresa, 'Laura Cajera', "caja@{$dominio}.test", 'Vendedor');
        $this->crearUsuarioDemo($empresa, 'Carlos Almacén', "almacen@{$dominio}.test", 'Almacenista');

        $categorias = $this->sembrarCategorias($empresa);
        $this->sembrarProductos($empresa, $categorias);
        $this->sembrarClientesDemo($empresa, $sufijoRnc);

        // Fase 5, Venta 7: descuento del 10 %.
        Descuento::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => 'Descuento 10%'],
            ['porcentaje' => 10, 'activo' => true],
        );

        $this->resumenEmpresa($empresa, $escenario, [$admin->email, "caja@{$dominio}.test", "almacen@{$dominio}.test"]);
    }

    /** Fase 2 de la guía. @return Collection<string, Categoria> */
    private function sembrarCategorias(Empresa $empresa): Collection
    {
        $categorias = [
            'Alimentos' => 'Comida para perros, gatos, aves, etc.',
            'Medicamentos' => 'Fármacos veterinarios, antiparasitarios',
            'Accesorios' => 'Collares, correas, camas, juguetes',
            'Higiene' => 'Shampoos, cepillos, productos de limpieza',
            'Servicios' => 'Consultas, vacunaciones, baños, peluquería',
            'Suplementos' => 'Vitaminas, minerales, suplementos nutricionales',
        ];

        return collect($categorias)->mapWithKeys(fn (string $descripcion, string $nombre) => [$nombre => Categoria::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => $nombre],
            ['descripcion' => $descripcion, 'activo' => true],
        )]);
    }

    /** Fases 3 y 4 de la guía, más productos extra para los casos límite de la Fase 8. */
    private function sembrarProductos(Empresa $empresa, Collection $categorias): void
    {
        $producto = fn (string $codigo, array $datos) => Producto::firstOrCreate(
            ['empresa_id' => $empresa->id, 'codigo' => $codigo],
            $datos + [
                'tipo' => TipoProducto::PRODUCTO,
                'tipo_venta' => TipoVenta::CONTABLE,
                'unidad_base' => 'unidad',
                'tasa_itbis' => TasaItbis::DIECIOCHO,
                'controla_stock' => true,
                'activo' => true,
            ],
        );

        $servicio = fn (string $codigo, string $nombre, float $precio) => $producto($codigo, [
            'nombre' => $nombre,
            'categoria_id' => $categorias['Servicios']->id,
            'tipo' => TipoProducto::SERVICIO,
            'controla_stock' => false,
            'precio' => $precio,
            'costo' => 0,
            'stock' => 0,
            'stock_minimo' => 0,
        ]);

        // 3A — Producto por unidad.
        $producto('VET-001', [
            'nombre' => 'Collar para perro mediano',
            'categoria_id' => $categorias['Accesorios']->id,
            'precio' => 350.00, 'costo' => 180.00,
            'stock' => 25, 'stock_minimo' => 5,
        ]);

        // 3B — Producto a granel por peso (libra).
        $producto('VET-002', [
            'nombre' => 'Alimento Premium Perro (saco 125 lb)',
            'categoria_id' => $categorias['Alimentos']->id,
            'tipo_venta' => TipoVenta::PESADO,
            'unidad_base' => 'libra',
            'precio' => 45.00, 'precio_por_peso' => 45.00, 'costo' => 28.00,
            'stock' => 125.000, 'stock_minimo' => 20.000,
        ]);

        // 3C — Medicamento fraccionado por cc. El formulario de producto solo ofrece unidad
        // para productos pesados (libra/kg); en contables la unidad base es "unidad" y el cc
        // se representa con la presentación "Por cc" (factor 1).
        $vitamina = $producto('VET-003', [
            'nombre' => 'Vitamina Canina Inyectable',
            'categoria_id' => $categorias['Suplementos']->id,
            'descripcion' => 'Stock en cc',
            'precio' => 15.00, 'costo' => 8.00,
            'stock' => 200, 'stock_minimo' => 30,
        ]);

        // 3D y 3E — Servicios (sin stock).
        $servicio('VET-S01', 'Consulta veterinaria general', 800.00);
        $servicio('VET-S02', 'Baño + Peluquería canina', 500.00);

        // 3F — Producto con presentaciones (pastillas).
        $antiparasitario = $producto('VET-004', [
            'nombre' => 'Antiparasitario Oral',
            'categoria_id' => $categorias['Medicamentos']->id,
            'descripcion' => 'Stock en pastillas',
            'precio' => 25.00, 'costo' => 12.00,
            'stock' => 500, 'stock_minimo' => 50,
        ]);

        // 4A — Presentaciones de la vitamina.
        $this->presentaciones($empresa, $vitamina, [
            ['Por cc', 1, 15.00, true, 'VET003CC'],
            ['Frasco 50cc', 50, 650.00, false, 'VET003F50'],
            ['Frasco 100cc', 100, 1200.00, false, 'VET003F100'],
        ]);

        // 4B — Presentaciones del antiparasitario.
        $this->presentaciones($empresa, $antiparasitario, [
            ['Por pastilla', 1, 25.00, true, 'VET004PA'],
            ['Caja 10 pastillas', 10, 220.00, false, 'VET004C10'],
            ['Caja 30 pastillas', 30, 600.00, false, 'VET004C30'],
        ]);

        // Extras para la Fase 8 (casos límite) y para probar líneas con otra tasa.
        $producto('VET-005', [
            'nombre' => 'Shampoo antipulgas 500 ml',
            'categoria_id' => $categorias['Higiene']->id,
            'precio' => 425.00, 'costo' => 210.00,
            'stock' => 4, 'stock_minimo' => 5, // ya por debajo del mínimo: alerta de stock bajo
        ]);

        $producto('VET-006', [
            'nombre' => 'Vacuna antirrábica (dosis)',
            'categoria_id' => $categorias['Medicamentos']->id,
            'descripcion' => 'Producto exento de prueba: línea exenta junto a gravadas en la misma factura',
            'tasa_itbis' => TasaItbis::EXENTO,
            'precio' => 650.00, 'costo' => 300.00,
            'stock' => 40, 'stock_minimo' => 10,
        ]);

        $producto('VET-007', [
            'nombre' => 'Cama para gato',
            'categoria_id' => $categorias['Accesorios']->id,
            'codigo_barra' => '7460000000071',
            'precio' => 1250.00, 'costo' => 700.00,
            'stock' => 0, 'stock_minimo' => 2, // sin stock: venta debe bloquearse
        ]);

        $servicio('VET-S03', 'Vacunación (aplicación)', 300.00);
    }

    /** @param  array<int, array{0: string, 1: int, 2: float, 3: bool, 4: string}>  $presentaciones */
    private function presentaciones(Empresa $empresa, Producto $producto, array $presentaciones): void
    {
        foreach ($presentaciones as [$nombre, $factor, $precio, $esBase, $codigoBarra]) {
            ProductoPresentacion::firstOrCreate(
                ['empresa_id' => $empresa->id, 'producto_id' => $producto->id, 'nombre' => $nombre],
                [
                    'factor' => $factor,
                    'precio' => $precio,
                    'es_base' => $esBase,
                    // Código de barras distinto por empresa: el UNIQUE es (empresa_id, codigo_barra).
                    'codigo_barra' => $codigoBarra.$empresa->id,
                    'activa' => true,
                ],
            );
        }
    }
}
