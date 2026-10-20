<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PerfilEmpresa;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\ModulosEmpresaService;
use App\Services\RolesEmpresaService;
use Illuminate\Database\Seeder;

/**
 * Supermercado dominicano realista para pruebas end-to-end de todos los módulos:
 * POS, Caja, Facturación, Ventas, Compras, Devoluciones, Inventario, Arqueo.
 *
 * Ejecutar solo este seeder:
 *   sail artisan db:seed --class=SupermercadoDemoSeeder
 */
class SupermercadoDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Empresa ──────────────────────────────────────────────────
        $empresa = Empresa::firstOrCreate(
            ['rnc' => '131456789'],
            [
                'razon_social' => 'Supermercado La Esquina del Ahorro SRL',
                'nombre_comercial' => 'La Esquina del Ahorro',
                'direccion' => 'Av. 27 de Febrero #345, Ensanche Naco, Santo Domingo, D.N.',
                'telefono' => '809-555-1234',
                'email' => 'info@laesquinadelahorro.com.do',
                'usa_ecf' => true,
                'activa' => true,
                'perfil' => PerfilEmpresa::FACTURACION,
            ],
        );

        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);
        app(ModulosEmpresaService::class)->sembrarModulos($empresa);

        // ── Usuarios ─────────────────────────────────────────────────
        $admin = $this->crearUsuario($empresa, 'Gerente Supermercado', 'gerente@laesquina.test', 'Administrador');
        $this->crearUsuario($empresa, 'María Santos', 'maria@laesquina.test', 'Vendedor');
        $this->crearUsuario($empresa, 'Pedro Almonte', 'pedro@laesquina.test', 'Vendedor');
        $this->crearUsuario($empresa, 'Luis Reyes', 'luis@laesquina.test', 'Almacenista');

        // ── Categorías ───────────────────────────────────────────────
        $categorias = $this->sembrarCategorias($empresa);

        // ── Productos ────────────────────────────────────────────────
        $this->sembrarProductos($empresa, $categorias);

        // ── Clientes ─────────────────────────────────────────────────
        $this->sembrarClientes($empresa);

        // ── Proveedores ──────────────────────────────────────────────
        $this->sembrarProveedores($empresa);

        // ── Cajas registradoras ──────────────────────────────────────
        $this->sembrarCajas($empresa);

        // ── Secuencias NCF ───────────────────────────────────────────
        $this->sembrarSecuenciasNcf($empresa);

        $this->command->info("✓ Supermercado «{$empresa->nombre_comercial}» listo para pruebas.");
        $this->command->info("  Login: gerente@laesquina.test / password123");
        $this->command->info("  Cajeras: maria@laesquina.test / pedro@laesquina.test");
        $this->command->info("  Almacén: luis@laesquina.test");
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    private function crearUsuario(Empresa $empresa, string $nombre, string $email, string $rol): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'empresa_id' => $empresa->id,
                'name' => $nombre,
                'password' => bcrypt('password123'),
                'email_verified_at' => now(),
            ],
        );

        if ($rol === 'Administrador') {
            app(RolesEmpresaService::class)->asignarAdministrador($user, $empresa);
        } else {
            $anterior = getPermissionsTeamId();
            setPermissionsTeamId($empresa->id);
            try {
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->assignRole($rol);
            } finally {
                setPermissionsTeamId($anterior);
            }
        }

        return $user;
    }

    /** @return array<string, Categoria> */
    private function sembrarCategorias(Empresa $empresa): array
    {
        $datos = [
            'lacteos' => ['nombre' => 'Lácteos y Huevos', 'descripcion' => 'Leche, queso, yogurt, mantequilla, huevos', 'margen_ganancia' => 25.00],
            'bebidas' => ['nombre' => 'Bebidas', 'descripcion' => 'Refrescos, jugos, agua, cervezas', 'margen_ganancia' => 30.00],
            'carnes' => ['nombre' => 'Carnes y Embutidos', 'descripcion' => 'Res, cerdo, pollo, salchichas, jamón', 'margen_ganancia' => 28.00],
            'abarrotes' => ['nombre' => 'Abarrotes', 'descripcion' => 'Arroz, habichuelas, aceite, azúcar, harina', 'margen_ganancia' => 20.00],
            'snacks' => ['nombre' => 'Snacks y Confitería', 'descripcion' => 'Galletas, dulces, chocolates, papitas', 'margen_ganancia' => 35.00],
            'limpieza' => ['nombre' => 'Limpieza del Hogar', 'descripcion' => 'Detergente, cloro, desinfectante, escobas', 'margen_ganancia' => 30.00],
            'higiene' => ['nombre' => 'Higiene Personal', 'descripcion' => 'Jabón, champú, pasta dental, desodorante', 'margen_ganancia' => 32.00],
            'frutas' => ['nombre' => 'Frutas y Vegetales', 'descripcion' => 'Plátano, guineo, tomate, cebolla, ají', 'margen_ganancia' => 40.00],
            'panaderia' => ['nombre' => 'Panadería', 'descripcion' => 'Pan, bizcocho, repostería', 'margen_ganancia' => 45.00],
            'enlatados' => ['nombre' => 'Enlatados y Conservas', 'descripcion' => 'Salsa de tomate, atún, sardinas, maíz', 'margen_ganancia' => 25.00],
            'condimentos' => ['nombre' => 'Condimentos y Sazones', 'descripcion' => 'Sazón, adobo, vinagre, salsa picante', 'margen_ganancia' => 35.00],
        ];

        $categorias = [];
        foreach ($datos as $key => $d) {
            $categorias[$key] = Categoria::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $d['nombre']],
                [
                    'empresa_id' => $empresa->id,
                    'descripcion' => $d['descripcion'],
                    'margen_ganancia' => $d['margen_ganancia'],
                    'activo' => true,
                ],
            );
        }

        return $categorias;
    }

    /** @param array<string, Categoria> $cat */
    private function sembrarProductos(Empresa $empresa, array $cat): void
    {
        // [codigo, codigo_barra, nombre, categoria_key, costo, precio, tasa_itbis, stock, stock_minimo]
        $productos = [
            // ── Lácteos ──
            ['SM-001', '7451234560010', 'Leche Entera Rica 1L', 'lacteos', 55.00, 75.00, TasaItbis::CERO, 120, 20],
            ['SM-002', '7451234560027', 'Queso de Freír Sosúa 340g', 'lacteos', 95.00, 135.00, TasaItbis::CERO, 60, 10],
            ['SM-003', '7451234560034', 'Yogurt Yoplait Fresa 150g', 'lacteos', 28.00, 42.00, TasaItbis::CERO, 80, 15],
            ['SM-004', '7451234560041', 'Mantequilla Lurpak 200g', 'lacteos', 120.00, 165.00, TasaItbis::CERO, 40, 8],
            ['SM-005', '7451234560058', 'Huevos Blancos (15 uds)', 'lacteos', 70.00, 95.00, TasaItbis::CERO, 100, 20],

            // ── Bebidas ──
            ['SM-010', '7451234560065', 'Coca-Cola 2L', 'bebidas', 50.00, 80.00, TasaItbis::DIECIOCHO, 200, 30],
            ['SM-011', '7451234560072', 'Agua Crystal 1 Galón', 'bebidas', 25.00, 40.00, TasaItbis::CERO, 300, 50],
            ['SM-012', '7451234560089', 'Jugo de Chinola Rica 1L', 'bebidas', 42.00, 65.00, TasaItbis::DIECIOCHO, 90, 15],
            ['SM-013', '7451234560096', 'Cerveza Presidente Lata 355ml', 'bebidas', 45.00, 75.00, TasaItbis::DIECIOCHO, 240, 48],
            ['SM-014', '7451234560102', 'Malta Morena 355ml', 'bebidas', 30.00, 50.00, TasaItbis::DIECIOCHO, 120, 24],

            // ── Carnes ──
            ['SM-020', '7451234560119', 'Pollo Entero Fresco (lb)', 'carnes', 55.00, 78.00, TasaItbis::CERO, 80, 15],
            ['SM-021', '7451234560126', 'Chuleta de Cerdo (lb)', 'carnes', 85.00, 120.00, TasaItbis::CERO, 50, 10],
            ['SM-022', '7451234560133', 'Res para Guisar (lb)', 'carnes', 110.00, 155.00, TasaItbis::CERO, 40, 8],
            ['SM-023', '7451234560140', 'Salchichas Oscar Mayer 453g', 'carnes', 75.00, 110.00, TasaItbis::DIECIOCHO, 60, 12],
            ['SM-024', '7451234560157', 'Jamón de Pierna Induveca (lb)', 'carnes', 95.00, 140.00, TasaItbis::DIECIOCHO, 35, 7],

            // ── Abarrotes ──
            ['SM-030', '7451234560164', 'Arroz Selecto La Garza 5lb', 'abarrotes', 115.00, 150.00, TasaItbis::CERO, 200, 40],
            ['SM-031', '7451234560171', 'Habichuelas Rojas La Famosa 400g', 'abarrotes', 38.00, 52.00, TasaItbis::CERO, 150, 25],
            ['SM-032', '7451234560188', 'Aceite de Soya Crisol 1L', 'abarrotes', 85.00, 115.00, TasaItbis::CERO, 100, 20],
            ['SM-033', '7451234560195', 'Azúcar Blanca Cristal 2lb', 'abarrotes', 42.00, 58.00, TasaItbis::CERO, 180, 30],
            ['SM-034', '7451234560201', 'Harina de Trigo Gold Medal 5lb', 'abarrotes', 95.00, 130.00, TasaItbis::CERO, 80, 15],
            ['SM-035', '7451234560218', 'Espaguetis La Muñeca 400g', 'abarrotes', 28.00, 42.00, TasaItbis::CERO, 120, 20],

            // ── Snacks ──
            ['SM-040', '7451234560225', 'Galletas Oreo 432g', 'snacks', 65.00, 95.00, TasaItbis::DIECIOCHO, 70, 12],
            ['SM-041', '7451234560232', 'Doritos Nachos 150g', 'snacks', 50.00, 78.00, TasaItbis::DIECIOCHO, 90, 15],
            ['SM-042', '7451234560249', 'Chocolate Snickers 52g', 'snacks', 25.00, 40.00, TasaItbis::DIECIOCHO, 100, 20],
            ['SM-043', '7451234560256', 'Barra de Cereal Nature Valley 42g', 'snacks', 35.00, 55.00, TasaItbis::DIECIOCHO, 60, 10],

            // ── Limpieza ──
            ['SM-050', '7451234560263', 'Detergente Ace 2kg', 'limpieza', 110.00, 155.00, TasaItbis::DIECIOCHO, 80, 15],
            ['SM-051', '7451234560270', 'Cloro Bravo 1 Galón', 'limpieza', 60.00, 90.00, TasaItbis::DIECIOCHO, 50, 10],
            ['SM-052', '7451234560287', 'Mistolín Lavanda 1L', 'limpieza', 55.00, 82.00, TasaItbis::DIECIOCHO, 60, 12],
            ['SM-053', '7451234560294', 'Papel Higiénico Suave 12 rollos', 'limpieza', 85.00, 125.00, TasaItbis::DIECIOCHO, 70, 12],

            // ── Higiene Personal ──
            ['SM-060', '7451234560300', 'Jabón de Baño Palmolive 3-pack', 'higiene', 50.00, 78.00, TasaItbis::DIECIOCHO, 60, 10],
            ['SM-061', '7451234560317', 'Champú Head & Shoulders 400ml', 'higiene', 125.00, 185.00, TasaItbis::DIECIOCHO, 40, 8],
            ['SM-062', '7451234560324', 'Pasta Dental Colgate 150ml', 'higiene', 55.00, 85.00, TasaItbis::DIECIOCHO, 80, 15],
            ['SM-063', '7451234560331', 'Desodorante Dove Roll-On 50ml', 'higiene', 90.00, 135.00, TasaItbis::DIECIOCHO, 50, 10],

            // ── Frutas y Vegetales ──
            ['SM-070', '7451234560348', 'Plátano Verde (ud)', 'frutas', 8.00, 12.00, TasaItbis::CERO, 300, 50],
            ['SM-071', '7451234560355', 'Guineo Maduro (ud)', 'frutas', 5.00, 8.00, TasaItbis::CERO, 200, 40],
            ['SM-072', '7451234560362', 'Tomate Ensalada (lb)', 'frutas', 30.00, 48.00, TasaItbis::CERO, 80, 15],
            ['SM-073', '7451234560379', 'Cebolla Roja (lb)', 'frutas', 25.00, 40.00, TasaItbis::CERO, 100, 20],
            ['SM-074', '7451234560386', 'Ají Cubanela (lb)', 'frutas', 22.00, 35.00, TasaItbis::CERO, 60, 10],
            ['SM-075', '7451234560393', 'Yuca Fresca (lb)', 'frutas', 15.00, 25.00, TasaItbis::CERO, 120, 20],
            ['SM-076', '7451234560409', 'Aguacate (ud)', 'frutas', 20.00, 35.00, TasaItbis::CERO, 60, 10],

            // ── Panadería ──
            ['SM-080', '7451234560416', 'Pan de Agua (ud)', 'panaderia', 5.00, 10.00, TasaItbis::CERO, 150, 30],
            ['SM-081', '7451234560423', 'Pan Sobao Rebanado', 'panaderia', 45.00, 70.00, TasaItbis::CERO, 40, 8],

            // ── Enlatados ──
            ['SM-090', '7451234560430', 'Salsa de Tomate Barceló 227g', 'enlatados', 22.00, 35.00, TasaItbis::DIECIOCHO, 100, 20],
            ['SM-091', '7451234560447', 'Atún Sardimar en Aceite 170g', 'enlatados', 48.00, 72.00, TasaItbis::DIECIOCHO, 80, 15],
            ['SM-092', '7451234560454', 'Maíz Dulce Del Monte 432g', 'enlatados', 55.00, 82.00, TasaItbis::DIECIOCHO, 60, 10],

            // ── Condimentos ──
            ['SM-100', '7451234560461', 'Sazón Ranchero 230g', 'condimentos', 40.00, 62.00, TasaItbis::DIECIOCHO, 70, 12],
            ['SM-101', '7451234560478', 'Adobo Bohío 280g', 'condimentos', 45.00, 68.00, TasaItbis::DIECIOCHO, 60, 10],
            ['SM-102', '7451234560485', 'Vinagre Blanco La Famosa 500ml', 'condimentos', 22.00, 35.00, TasaItbis::DIECIOCHO, 80, 15],
        ];

        foreach ($productos as [$codigo, $codigoBarra, $nombre, $catKey, $costo, $precio, $tasaItbis, $stock, $stockMin]) {
            Producto::firstOrCreate(
                ['empresa_id' => $empresa->id, 'codigo' => $codigo],
                [
                    'empresa_id' => $empresa->id,
                    'codigo_barra' => $codigoBarra,
                    'nombre' => $nombre,
                    'categoria_id' => $cat[$catKey]->id,
                    'tipo' => TipoProducto::PRODUCTO,
                    'costo' => $costo,
                    'precio' => $precio,
                    'tasa_itbis' => $tasaItbis,
                    'controla_stock' => true,
                    'stock' => $stock,
                    'stock_minimo' => $stockMin,
                    'activo' => true,
                ],
            );
        }
    }

    private function sembrarClientes(Empresa $empresa): void
    {
        $clientes = [
            ['tipo' => TipoDocumentoCliente::RNC, 'documento' => '130987654', 'nombre' => 'Hotel Barceló Santo Domingo', 'telefono' => '809-555-5001', 'email' => 'compras@barcelo.test', 'direccion' => 'Av. Máximo Gómez, Ens. La Fe'],
            ['tipo' => TipoDocumentoCliente::RNC, 'documento' => '101234567', 'nombre' => 'Restaurante El Mesón D\'la Cava', 'telefono' => '809-555-5002', 'email' => 'pedidos@meson.test', 'direccion' => 'Av. Mirador del Sur'],
            ['tipo' => TipoDocumentoCliente::CEDULA, 'documento' => '00114725836', 'nombre' => 'Ana María Rodríguez', 'telefono' => '809-555-6001'],
            ['tipo' => TipoDocumentoCliente::CEDULA, 'documento' => '40212345678', 'nombre' => 'Carlos Jiménez Peña', 'telefono' => '849-555-6002'],
            ['tipo' => TipoDocumentoCliente::CEDULA, 'documento' => '22301987654', 'nombre' => 'Rosa Altagracia Méndez', 'telefono' => '829-555-6003'],
            ['tipo' => TipoDocumentoCliente::RNC, 'documento' => '131876543', 'nombre' => 'Colmado Don Fello', 'telefono' => '809-555-5003', 'direccion' => 'C/ Duarte #78, Villa Mella'],
            ['tipo' => TipoDocumentoCliente::SIN_DOCUMENTO, 'documento' => null, 'nombre' => 'Consumidor Final Genérico'],
        ];

        foreach ($clientes as $c) {
            $searchKey = $c['documento']
                ? ['empresa_id' => $empresa->id, 'documento' => $c['documento']]
                : ['empresa_id' => $empresa->id, 'nombre' => $c['nombre']];

            Cliente::firstOrCreate(
                $searchKey,
                [
                    'empresa_id' => $empresa->id,
                    'tipo_documento' => $c['tipo'],
                    'documento' => $c['documento'],
                    'nombre' => $c['nombre'],
                    'telefono' => $c['telefono'] ?? null,
                    'email' => $c['email'] ?? null,
                    'direccion' => $c['direccion'] ?? null,
                    'activo' => true,
                ],
            );
        }
    }

    private function sembrarProveedores(Empresa $empresa): void
    {
        $proveedores = [
            ['rnc' => '101567890', 'nombre' => 'Distribuidora Nacional CxA', 'nombre_comercial' => 'DistNacional', 'tipo' => TipoProveedor::FORMAL, 'actividad' => 'Distribución de alimentos', 'telefono' => '809-555-7001', 'email' => 'ventas@distnacional.test'],
            ['rnc' => '130345678', 'nombre' => 'Bebidas del Caribe SRL', 'nombre_comercial' => 'BebiCaribe', 'tipo' => TipoProveedor::FORMAL, 'actividad' => 'Distribución de bebidas', 'telefono' => '809-555-7002', 'email' => 'pedidos@bebicaribe.test'],
            ['rnc' => '131678901', 'nombre' => 'Carnes y Embutidos del Cibao SRL', 'nombre_comercial' => 'CEC', 'tipo' => TipoProveedor::FORMAL, 'actividad' => 'Procesadora de carnes', 'telefono' => '809-555-7003', 'email' => 'ventas@cec.test'],
            ['rnc' => '101890123', 'nombre' => 'Productos de Limpieza Quisqueya CxA', 'nombre_comercial' => 'LimpiaQuisqueya', 'tipo' => TipoProveedor::FORMAL, 'actividad' => 'Fabricación de productos de limpieza', 'telefono' => '809-555-7004'],
            ['rnc' => null, 'nombre' => 'Finca Los Mangos', 'nombre_comercial' => null, 'tipo' => TipoProveedor::INFORMAL, 'actividad' => 'Producción agrícola', 'telefono' => '829-555-7005'],
        ];

        foreach ($proveedores as $p) {
            $searchKey = $p['rnc']
                ? ['rnc' => $p['rnc']]
                : ['empresa_id' => $empresa->id, 'nombre' => $p['nombre']];

            Proveedor::firstOrCreate(
                $searchKey,
                [
                    'empresa_id' => $empresa->id,
                    'tipo' => $p['tipo'],
                    'nombre' => $p['nombre'],
                    'nombre_comercial' => $p['nombre_comercial'],
                    'actividad_economica' => $p['actividad'],
                    'telefono' => $p['telefono'],
                    'email' => $p['email'] ?? null,
                    'activo' => true,
                ],
            );
        }
    }

    private function sembrarCajas(Empresa $empresa): void
    {
        foreach (['Caja 1' => 'CAJA-01', 'Caja 2' => 'CAJA-02', 'Caja Express' => 'CAJA-EX'] as $nombre => $codigo) {
            Caja::firstOrCreate(
                ['empresa_id' => $empresa->id, 'codigo' => $codigo],
                [
                    'empresa_id' => $empresa->id,
                    'nombre' => $nombre,
                    'codigo' => $codigo,
                    'activo' => true,
                ],
            );
        }
    }

    private function sembrarSecuenciasNcf(Empresa $empresa): void
    {
        $secuencias = [
            ['tipo' => TipoComprobante::FACTURA_CREDITO_FISCAL, 'prefijo' => 'E310000000', 'desde' => 1, 'hasta' => 500],
            ['tipo' => TipoComprobante::FACTURA_CONSUMO, 'prefijo' => 'E320000000', 'desde' => 1, 'hasta' => 5000],
            ['tipo' => TipoComprobante::NOTA_DEBITO, 'prefijo' => 'E330000000', 'desde' => 1, 'hasta' => 200],
            ['tipo' => TipoComprobante::NOTA_CREDITO, 'prefijo' => 'E340000000', 'desde' => 1, 'hasta' => 200],
            // Físicos por si se prueban comprobantes B-series
            ['tipo' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA, 'prefijo' => 'B0100000000', 'desde' => 1, 'hasta' => 100],
            ['tipo' => TipoComprobante::FACTURA_CONSUMO_FISICA, 'prefijo' => 'B0200000000', 'desde' => 1, 'hasta' => 1000],
        ];

        $vencimiento = now()->addYear();

        foreach ($secuencias as $s) {
            SecuenciaNcf::firstOrCreate(
                ['empresa_id' => $empresa->id, 'tipo_comprobante' => $s['tipo']],
                [
                    'empresa_id' => $empresa->id,
                    'tipo_comprobante' => $s['tipo'],
                    'prefijo' => $s['prefijo'],
                    'secuencia_desde' => $s['desde'],
                    'secuencia_actual' => $s['desde'],
                    'secuencia_hasta' => $s['hasta'],
                    'vencimiento' => $vencimiento,
                    'activa' => true,
                ],
            );
        }
    }
}
