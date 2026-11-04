<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PerfilEmpresa;
use App\Enums\TasaItbis;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Models\ArqueoCaja;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\ServicioLavanderia;
use App\Models\TipoPrenda;
use App\Models\User;
use App\Services\ArqueoCajaService;
use App\Services\ModulosEmpresaService;
use App\Services\OrdenCompraService;
use App\Services\RolesEmpresaService;
use Illuminate\Database\Seeder;

/**
 * Empresas de prueba con distintos perfiles de negocio para verificar aislamiento entre
 * tenants y que cada perfil active los módulos correctos. Nombres a propósito distintos
 * entre sí para que una fuga sea obvia a simple vista.
 */
class EmpresaSeeder extends Seeder
{
    public function run(): void
    {
        $empresaA = $this->crearEmpresaFacturacion(
            'Empresa A SRL', '131000001', 'admin@empresa-a.test',
        );

        $tobogan = $this->crearEmpresaFacturacion(
            'Tobogán Diversiones SRL', '131000002', 'admin@tobogan.test',
        );

        $lavanderia = $this->crearEmpresaLavanderia(
            'Lavandería Express RD SRL', null, 'admin@lavanderia-express.test',
        );

        $this->command->info("Empresas de prueba listas: {$empresaA->slug} / {$tobogan->slug} / {$lavanderia->slug}");
    }

    /**
     * Crea una empresa con perfil FACTURACION (el flujo completo de productos, clientes,
     * proveedores, órdenes de compra y arqueos de caja).
     */
    private function crearEmpresaFacturacion(string $razonSocial, string $rnc, string $emailAdmin): Empresa
    {
        $empresa = Empresa::firstOrCreate(
            ['rnc' => $rnc],
            [
                'razon_social' => $razonSocial,
                'perfil' => PerfilEmpresa::FACTURACION,
                'usa_ecf' => true,
                'activa' => true,
            ],
        );

        $admin = $this->configurarEmpresaBase($empresa, $emailAdmin);

        $this->sembrarProductos($empresa, [
            ['codigo' => 'A-001', 'nombre' => 'Producto '.$empresa->razon_social.' 1', 'precio' => 100],
            ['codigo' => 'A-002', 'nombre' => 'Producto '.$empresa->razon_social.' 2', 'precio' => 200],
        ]);

        $this->sembrarClientes($empresa, [
            ['nombre' => 'Cliente '.$empresa->razon_social, 'documento' => '001'.substr($rnc, -5).'01'],
        ]);

        $proveedorCreado = $this->sembrarProveedor($empresa, [
            'rnc' => '131500'.substr($rnc, -3),
            'nombre' => 'Proveedor '.$empresa->razon_social,
        ]);

        $primerProducto = Producto::where('empresa_id', $empresa->id)->first();

        if ($primerProducto !== null && ! OrdenCompra::where('empresa_id', $empresa->id)->exists()) {
            app(OrdenCompraService::class)->crear([
                'proveedor_id' => $proveedorCreado->id,
                'fecha' => now()->toDateString(),
                'fecha_esperada' => null,
                'notas' => 'Orden de prueba sembrada por EmpresaSeeder.',
                'lineas' => [
                    ['producto_id' => $primerProducto->id, 'cantidad_solicitada' => 10, 'precio_unitario' => (float) $primerProducto->costo],
                ],
            ], $admin->id, $empresa);
        }

        if (! ArqueoCaja::where('empresa_id', $empresa->id)->exists()) {
            $arqueo = app(ArqueoCajaService::class)->abrir('1000.00', $admin->id, $empresa);
            app(ArqueoCajaService::class)->cerrar($arqueo, '1000.00', 'Arqueo de prueba sembrado por EmpresaSeeder.', $admin->id);
        }

        return $empresa;
    }

    /**
     * Crea una empresa con perfil LAVANDERIA (sin RNC, con tipos de prenda y servicios de prueba).
     */
    private function crearEmpresaLavanderia(string $razonSocial, ?string $rnc, string $emailAdmin): Empresa
    {
        // Para lavandería el RNC puede ser null — buscar por razon_social en ese caso.
        $empresa = $rnc
            ? Empresa::firstOrCreate(
                ['rnc' => $rnc],
                ['razon_social' => $razonSocial, 'perfil' => PerfilEmpresa::LAVANDERIA, 'usa_ecf' => false, 'activa' => true],
            )
            : Empresa::firstOrCreate(
                ['razon_social' => $razonSocial, 'perfil' => PerfilEmpresa::LAVANDERIA],
                ['rnc' => null, 'usa_ecf' => false, 'activa' => true],
            );

        $this->configurarEmpresaBase($empresa, $emailAdmin);

        // Lavandería vende sin comprobante por defecto.
        $empresa->config()->update(['permite_ventas_sin_comprobante' => true]);

        // Sembrar tipos de prenda de prueba.
        $prendas = ['Camisa', 'Pantalón', 'Vestido', 'Sábana', 'Cortina'];
        foreach ($prendas as $prenda) {
            TipoPrenda::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $prenda],
                ['activo' => true],
            );
        }

        // Sembrar servicios de prueba.
        $servicios = [
            ['nombre' => 'Lavado', 'precio_base' => 80, 'aplica_itbis' => true],
            ['nombre' => 'Lavado y Planchado', 'precio_base' => 150, 'aplica_itbis' => true],
            ['nombre' => 'Planchado', 'precio_base' => 60, 'aplica_itbis' => true],
            ['nombre' => 'Lavado en Seco', 'precio_base' => 250, 'aplica_itbis' => true],
        ];
        foreach ($servicios as $servicio) {
            ServicioLavanderia::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $servicio['nombre']],
                ['precio_base' => $servicio['precio_base'], 'aplica_itbis' => $servicio['aplica_itbis'], 'activo' => true],
            );
        }

        // Cliente de prueba para la lavandería.
        $this->sembrarClientes($empresa, [
            ['nombre' => 'María Pérez (Prueba)', 'documento' => '00300000003'],
        ]);

        return $empresa;
    }

    /**
     * Configura roles, módulos y usuario admin para cualquier perfil de empresa.
     */
    private function configurarEmpresaBase(Empresa $empresa, string $emailAdmin): User
    {
        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);
        app(ModulosEmpresaService::class)->sembrarModulos($empresa);

        $admin = User::firstOrCreate(
            ['email' => $emailAdmin],
            [
                'empresa_id' => $empresa->id,
                'name' => 'Admin '.$empresa->razon_social,
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
            ],
        );

        app(RolesEmpresaService::class)->asignarAdministrador($admin, $empresa);

        return $admin;
    }

    /**
     * @param  array<int, array{codigo: string, nombre: string, precio: int}>  $productos
     */
    private function sembrarProductos(Empresa $empresa, array $productos): void
    {
        foreach ($productos as $datosProducto) {
            Producto::firstOrCreate(
                ['empresa_id' => $empresa->id, 'codigo' => $datosProducto['codigo']],
                [
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
        }
    }

    /**
     * @param  array<int, array{nombre: string, documento: string}>  $clientes
     */
    private function sembrarClientes(Empresa $empresa, array $clientes): void
    {
        foreach ($clientes as $datosCliente) {
            Cliente::firstOrCreate(
                ['empresa_id' => $empresa->id, 'documento' => $datosCliente['documento']],
                [
                    'tipo_documento' => TipoDocumentoCliente::CEDULA,
                    'nombre' => $datosCliente['nombre'],
                    'activo' => true,
                ],
            );
        }
    }

    /**
     * @param  array{rnc: string, nombre: string}  $proveedor
     */
    private function sembrarProveedor(Empresa $empresa, array $proveedor): Proveedor
    {
        return Proveedor::firstOrCreate(
            ['empresa_id' => $empresa->id, 'rnc' => $proveedor['rnc']],
            [
                'tipo' => TipoProveedor::FORMAL,
                'nombre' => $proveedor['nombre'],
                'activo' => true,
            ],
        );
    }
}
