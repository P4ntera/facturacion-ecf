<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TasaItbis;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dos empresas de prueba para verificar aislamiento entre tenants (cada una con su propio
 * usuario administrador y datos propios): "Empresa A" y "Tobogán". Nombres de producto/cliente
 * a propósito distintos entre sí para que una fuga entre empresas sea obvia a simple vista.
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
        ]);

        $tobogan = $this->crearEmpresa('Tobogán Diversiones SRL', '131000002', 'admin@tobogan.test', [
            ['codigo' => 'T-001', 'nombre' => 'Producto Tobogán 1', 'precio' => 300],
            ['codigo' => 'T-002', 'nombre' => 'Producto Tobogán 2', 'precio' => 400],
        ], [
            ['nombre' => 'Cliente Tobogán', 'documento' => '00200000002'],
        ]);

        $this->command->info("Empresas de prueba listas: {$empresaA->slug} / {$tobogan->slug}");
    }

    /**
     * @param  array<int, array{codigo: string, nombre: string, precio: int}>  $productos
     * @param  array<int, array{nombre: string, documento: string}>  $clientes
     */
    private function crearEmpresa(string $razonSocial, string $rnc, string $emailAdmin, array $productos, array $clientes): Empresa
    {
        $empresa = Empresa::firstOrCreate(
            ['rnc' => $rnc],
            ['razon_social' => $razonSocial, 'usa_ecf' => true, 'activa' => true],
        );

        $admin = User::firstOrCreate(
            ['email' => $emailAdmin],
            [
                'empresa_id' => $empresa->id,
                'name' => 'Admin '.$razonSocial,
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
            ],
        );
        $admin->assignRole('Administrador');

        foreach ($productos as $producto) {
            Producto::firstOrCreate(
                ['codigo' => $producto['codigo']],
                [
                    'empresa_id' => $empresa->id,
                    'nombre' => $producto['nombre'],
                    'tipo' => TipoProducto::PRODUCTO,
                    'costo' => $producto['precio'] * 0.6,
                    'precio' => $producto['precio'],
                    'tasa_itbis' => TasaItbis::DIECIOCHO,
                    'controla_stock' => true,
                    'stock' => 50,
                    'stock_minimo' => 5,
                    'activo' => true,
                ],
            );
        }

        foreach ($clientes as $cliente) {
            Cliente::firstOrCreate(
                ['documento' => $cliente['documento']],
                [
                    'empresa_id' => $empresa->id,
                    'tipo_documento' => TipoDocumentoCliente::CEDULA,
                    'nombre' => $cliente['nombre'],
                    'activo' => true,
                ],
            );
        }

        return $empresa;
    }
}
