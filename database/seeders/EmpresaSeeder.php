<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TasaItbis;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Models\ArqueoCaja;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\PedidoCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\ArqueoCajaService;
use App\Services\ModulosEmpresaService;
use App\Services\PedidoCompraService;
use App\Services\RolesEmpresaService;
use Illuminate\Database\Seeder;

/**
 * Dos empresas de prueba para verificar aislamiento entre tenants (cada una con su propio
 * usuario administrador y datos propios): "Empresa A" y "Tobogán". Nombres de producto/cliente/
 * proveedor a propósito distintos entre sí (incluye un ArqueoCaja y un PedidoCompra por empresa)
 * para que una fuga entre empresas sea obvia a simple vista.
 */
class EmpresaSeeder extends Seeder
{
    public function run(): void
    {
        $empresaA = $this->crearEmpresa('Empresa A SRL', '131000001', 'admin@empresa-a.test', [
            ['codigo' => 'A-001', 'nombre' => 'Producto Empresa A 1', 'precio' => 100],
            ['codigo' => 'A-002', 'nombre' => 'Producto Empresa A 2', 'precio' => 200],
        ], [
            ['nombre' => 'Cliente Empresa A', 'documento' => '00100000001'],
        ], [
            'rnc' => '131500001', 'nombre' => 'Proveedor Empresa A',
        ]);

        $tobogan = $this->crearEmpresa('Tobogán Diversiones SRL', '131000002', 'admin@tobogan.test', [
            ['codigo' => 'T-001', 'nombre' => 'Producto Tobogán 1', 'precio' => 300],
            ['codigo' => 'T-002', 'nombre' => 'Producto Tobogán 2', 'precio' => 400],
        ], [
            ['nombre' => 'Cliente Tobogán', 'documento' => '00200000002'],
        ], [
            'rnc' => '131500002', 'nombre' => 'Proveedor Tobogán',
        ]);

        $this->command->info("Empresas de prueba listas: {$empresaA->slug} / {$tobogan->slug}");
    }

    /**
     * @param  array<int, array{codigo: string, nombre: string, precio: int}>  $productos
     * @param  array<int, array{nombre: string, documento: string}>  $clientes
     * @param  array{rnc: string, nombre: string}  $proveedor
     */
    private function crearEmpresa(string $razonSocial, string $rnc, string $emailAdmin, array $productos, array $clientes, array $proveedor): Empresa
    {
        $empresa = Empresa::firstOrCreate(
            ['rnc' => $rnc],
            ['razon_social' => $razonSocial, 'usa_ecf' => true, 'activa' => true],
        );

        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);
        app(ModulosEmpresaService::class)->sembrarModulos($empresa);

        $admin = User::firstOrCreate(
            ['email' => $emailAdmin],
            [
                'empresa_id' => $empresa->id,
                'name' => 'Admin '.$razonSocial,
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
            ],
        );

        app(RolesEmpresaService::class)->asignarAdministrador($admin, $empresa);

        $primerProducto = null;

        foreach ($productos as $datosProducto) {
            $producto = Producto::firstOrCreate(
                ['codigo' => $datosProducto['codigo']],
                [
                    'empresa_id' => $empresa->id,
                    'nombre' => $datosProducto['nombre'],
                    'tipo' => TipoProducto::PRODUCTO,
                    'costo' => $datosProducto['precio'] * 0.6,
                    'precio' => $datosProducto['precio'],
                    'tasa_itbis' => TasaItbis::DIECIOCHO,
                    'controla_stock' => true,
                    'stock' => 50,
                    'stock_minimo' => 5,
                    'activo' => true,
                ],
            );

            $primerProducto ??= $producto;
        }

        foreach ($clientes as $datosCliente) {
            Cliente::firstOrCreate(
                ['documento' => $datosCliente['documento']],
                [
                    'empresa_id' => $empresa->id,
                    'tipo_documento' => TipoDocumentoCliente::CEDULA,
                    'nombre' => $datosCliente['nombre'],
                    'activo' => true,
                ],
            );
        }

        $proveedorCreado = Proveedor::firstOrCreate(
            ['rnc' => $proveedor['rnc']],
            [
                'empresa_id' => $empresa->id,
                'tipo' => TipoProveedor::FORMAL,
                'nombre' => $proveedor['nombre'],
                'activo' => true,
            ],
        );

        if ($primerProducto !== null && ! PedidoCompra::where('empresa_id', $empresa->id)->exists()) {
            app(PedidoCompraService::class)->crear([
                'proveedor_id' => $proveedorCreado->id,
                'fecha' => now(),
                'notas' => 'Pedido de prueba sembrado por EmpresaSeeder.',
                'lineas' => [
                    ['producto_id' => $primerProducto->id, 'cantidad' => 10, 'costo_unitario' => (float) $primerProducto->costo],
                ],
            ], $admin->id, $empresa);
        }

        if (! ArqueoCaja::where('empresa_id', $empresa->id)->exists()) {
            $arqueo = app(ArqueoCajaService::class)->abrir('1000.00', $admin->id, $empresa);
            app(ArqueoCajaService::class)->cerrar($arqueo, '1000.00', 'Arqueo de prueba sembrado por EmpresaSeeder.', $admin->id);
        }

        return $empresa;
    }
}
