<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CategoriaSabor;
use App\Enums\FormaPago;
use App\Enums\ModoPrecioHelado;
use App\Enums\PerfilEmpresa;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ComboHelado;
use App\Models\Empresa;
use App\Models\PresentacionHelado;
use App\Models\Producto;
use App\Models\SaborHelado;
use App\Models\ToppingHelado;
use App\Models\Venta;
use App\Services\HeladeriaService;
use App\Support\ComprobanteVenta;
use Database\Seeders\Concerns\SiembraEmpresaDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Heladería demo en tres escenarios fiscales (ver SiembraEmpresaDemo), que además cubren los
 * dos modos de precio del módulo:
 *
 *  - Helados Don Pepe     (informal) — sin ITBIS, sin comprobante, precio POR PRESENTACIÓN.
 *  - Heladería La Fresa   (tipo B)   — ITBIS incluido, B01/B02, precio POR SCOOP (normal/premium).
 *  - Gelato Artesanal     (e-CF)     — ITBIS aparte, E31/E32, precio POR PRESENTACIÓN.
 *
 * Cada una con sabores (normales y premium, uno agotado), presentaciones, toppings, combos y
 * productos directos de reventa (paletas, bebidas, snacks — uno exento de ITBIS), dos usuarios
 * (admin y heladero), clientes y algunas ventas de ejemplo con el comprobante del escenario.
 *
 * Ya NO siembra catálogos en todas las empresas existentes: antes llenaba de sabores y combos
 * también a lavanderías y demás perfiles.
 *
 *   sail artisan db:seed --class=HeladeriaSeeder
 */
class HeladeriaSeeder extends Seeder
{
    use SiembraEmpresaDemo;

    public function run(): void
    {
        $this->abortarEnProduccion();

        $this->command->info('Heladería demo:');

        $this->sembrarEscenario(self::ESCENARIO_INFORMAL, ModoPrecioHelado::POR_PRESENTACION, [
            'razon_social' => 'Helados Don Pepe',
            'nombre_comercial' => 'Helados Don Pepe',
            'rnc' => null,
            'slug' => 'demo-heladeria-informal',
            'email_admin' => 'admin@hel-informal.test',
        ], 'hel-informal', '0000201');

        $this->sembrarEscenario(self::ESCENARIO_TIPO_B, ModoPrecioHelado::POR_SCOOP, [
            'razon_social' => 'Heladería La Fresa SRL',
            'nombre_comercial' => 'Heladería La Fresa',
            'rnc' => '131900201',
            'slug' => 'demo-heladeria-tipo-b',
            'email_admin' => 'admin@hel-tipob.test',
        ], 'hel-tipob', '0000202');

        $this->sembrarEscenario(self::ESCENARIO_ECF, ModoPrecioHelado::POR_PRESENTACION, [
            'razon_social' => 'Gelato Artesanal Dominicano SRL',
            'nombre_comercial' => 'Gelato Artesanal',
            'rnc' => '131900202',
            'slug' => 'demo-heladeria-ecf',
            'email_admin' => 'admin@hel-ecf.test',
        ], 'hel-ecf', '0000203');
    }

    /** @param  array<string, ?string>  $datos */
    private function sembrarEscenario(string $escenario, ModoPrecioHelado $modo, array $datos, string $dominio, string $sufijoRnc): void
    {
        [$empresa, $admin] = $this->crearEmpresaDemo(PerfilEmpresa::HELADERIA, $escenario, $datos);

        $empresa->config()->update([
            'modo_precio_helado' => $modo,
            'precio_scoop_normal' => $modo === ModoPrecioHelado::POR_SCOOP ? 75 : 0,
            'precio_scoop_premium' => $modo === ModoPrecioHelado::POR_SCOOP ? 110 : 0,
            'permitir_scoop_extra' => true,
        ]);

        $this->crearUsuarioDemo($empresa, 'Pedro Heladero', "heladero@{$dominio}.test", 'Heladero');

        $this->sembrarSabores($empresa);
        $this->sembrarPresentaciones($empresa);
        $this->sembrarToppings($empresa);
        $this->sembrarCombos($empresa);
        $directos = $this->sembrarProductosDirectos($empresa);
        $clientes = $this->sembrarClientesDemo($empresa, $sufijoRnc);

        if (! Venta::where('empresa_id', $empresa->id)->exists()) {
            $this->sembrarVentas($empresa->fresh(), $escenario, $directos, $clientes);
        }

        $this->resumenEmpresa($empresa, $escenario, [$admin->email, "heladero@{$dominio}.test"]);
    }

    private function sembrarSabores(Empresa $empresa): void
    {
        $sabores = [
            ['Vainilla', '#F5E6CA', CategoriaSabor::CREMOSO, false],
            ['Chocolate', '#5C3317', CategoriaSabor::CREMOSO, false],
            ['Cookies & Cream', '#D2C6B2', CategoriaSabor::CREMOSO, false],
            ['Dulce de Leche', '#C68E3C', CategoriaSabor::CREMOSO, false],
            ['Mantecado', '#FFF8DC', CategoriaSabor::CREMOSO, false],
            ['Ron con Pasas', '#D4A574', CategoriaSabor::CREMOSO, true],
            ['Nutella', '#6B3A2A', CategoriaSabor::CREMOSO, true],
            ['Fresa', '#E8456B', CategoriaSabor::FRUTAL, false],
            ['Limón', '#C5E17A', CategoriaSabor::FRUTAL, false],
            ['Chinola', '#F5A623', CategoriaSabor::FRUTAL, false],
            ['Coco', '#FAFAFA', CategoriaSabor::FRUTAL, false],
            ['Mango', '#FFB347', CategoriaSabor::FRUTAL, false],
            ['Pistacchio', '#93C572', CategoriaSabor::ESPECIAL, true],
            ['Matcha', '#78A55A', CategoriaSabor::ESPECIAL, true],
            ['Cheesecake', '#F5DEB3', CategoriaSabor::ESPECIAL, true],
        ];

        foreach ($sabores as $orden => [$nombre, $color, $categoria, $premium]) {
            SaborHelado::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $nombre],
                [
                    'color_hex' => $color,
                    'categoria' => $categoria,
                    'premium' => $premium,
                    'orden' => $orden + 1,
                    // Un sabor agotado para probar que el POS no lo deja elegir.
                    'disponible' => $nombre !== 'Matcha',
                    'activo' => true,
                ],
            );
        }
    }

    private function sembrarPresentaciones(Empresa $empresa): void
    {
        $presentaciones = [
            ['Cono Sencillo', '🍦', 1, 0, 100],
            ['Cono Doble', '🍦', 2, 1, 175],
            ['Cono Triple', '🍦', 3, 1, 225],
            ['Copa Pequeña', '🍨', 2, 1, 200],
            ['Copa Grande', '🍨', 3, 2, 300],
            ['Sundae', '🥤', 2, 3, 275],
            ['Banana Split', '🍌', 3, 3, 350],
        ];

        foreach ($presentaciones as $orden => [$nombre, $icono, $scoops, $toppings, $precio]) {
            PresentacionHelado::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $nombre],
                [
                    'icono' => $icono,
                    'scoops_incluidos' => $scoops,
                    'toppings_incluidos' => $toppings,
                    'precio' => $precio,
                    'orden' => $orden + 1,
                    'activo' => true,
                ],
            );
        }
    }

    private function sembrarToppings(Empresa $empresa): void
    {
        $toppings = [
            ['Chispas de Chocolate', '🍫', 35], ['Gomitas', '🍬', 40], ['Galleta Oreo', '🍪', 45],
            ['Maní Triturado', '🥜', 30], ['Sirope de Chocolate', '🫠', 25], ['Sirope de Fresa', '🍓', 25],
            ['Caramelo', '🍯', 25], ['Crema Batida', '🍰', 30], ['Sprinkles', '✨', 20], ['Brownie', '🟫', 50],
        ];

        foreach ($toppings as $orden => [$nombre, $icono, $precio]) {
            ToppingHelado::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $nombre],
                ['icono' => $icono, 'precio' => $precio, 'orden' => $orden + 1, 'activo' => true],
            );
        }
    }

    private function sembrarCombos(Empresa $empresa): void
    {
        $combos = [
            ['Combo Niño Feliz', '👶', '1 cono sencillo + 1 topping + 1 agua', 1, 1, 150],
            ['Combo Pareja', '💕', '2 conos dobles + 2 toppings', 4, 2, 400],
            ['Combo Familiar', '👨‍👩‍👧‍👦', '4 copas pequeñas + 4 toppings', 8, 4, 700],
            ['Sundae Especial', '🌟', 'Sundae grande con 3 scoops premium + 5 toppings', 3, 5, 450],
        ];

        foreach ($combos as $orden => [$nombre, $icono, $descripcion, $scoops, $toppings, $precio]) {
            ComboHelado::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $nombre],
                [
                    'icono' => $icono,
                    'descripcion' => $descripcion,
                    'scoops_incluidos' => $scoops,
                    'toppings_incluidos' => $toppings,
                    'precio' => $precio,
                    'orden' => $orden + 1,
                    'activo' => true,
                ],
            );
        }
    }

    /**
     * Productos de reventa que el POS de heladería vende directo (pestaña "Productos").
     * El agua es exenta para probar una línea exenta junto a líneas gravadas.
     *
     * @return Collection<string, Producto>
     */
    private function sembrarProductosDirectos(Empresa $empresa): Collection
    {
        $categoria = Categoria::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => 'Reventa'],
            ['descripcion' => 'Paletas, bebidas y snacks', 'activo' => true],
        );

        $productos = [
            ['HEL-D-001', 'Paleta de Fresa', 60, 25, TasaItbis::DIECIOCHO, 80],
            ['HEL-D-002', 'Paleta de Coco', 60, 25, TasaItbis::DIECIOCHO, 80],
            ['HEL-D-003', 'Refresco 20 oz', 60, 35, TasaItbis::DIECIOCHO, 48],
            ['HEL-D-004', 'Galletas Oreo paquete', 50, 30, TasaItbis::DIECIOCHO, 3], // stock bajo
            ['HEL-D-005', 'Agua 16 oz', 30, 12, TasaItbis::EXENTO, 120],
        ];

        return collect($productos)->mapWithKeys(fn (array $p) => [$p[1] => Producto::firstOrCreate(
            ['empresa_id' => $empresa->id, 'codigo' => $p[0]],
            [
                'nombre' => $p[1],
                'categoria_id' => $categoria->id,
                'tipo' => TipoProducto::PRODUCTO,
                'precio' => $p[2],
                'costo' => $p[3],
                'tasa_itbis' => $p[4],
                'controla_stock' => true,
                'stock' => $p[5],
                'stock_minimo' => 10,
                'activo' => true,
            ],
        )]);
    }

    /**
     * Ventas de ejemplo: helado armado + producto directo, combo con crédito fiscal (si el
     * escenario lo tiene) y una venta con línea exenta.
     *
     * @param  array{rnc: Cliente, cedula: Cliente, sin_documento: Cliente}  $clientes
     */
    private function sembrarVentas(Empresa $empresa, string $escenario, Collection $directos, array $clientes): void
    {
        $servicio = app(HeladeriaService::class);

        [$consumo, $credito] = match ($escenario) {
            self::ESCENARIO_INFORMAL => [ComprobanteVenta::SIN_COMPROBANTE, null],
            self::ESCENARIO_TIPO_B => ['B02', 'B01'],
            self::ESCENARIO_ECF => ['32', '31'],
        };

        $directo = fn (string $nombre, int $cantidad = 1) => [
            'tipo' => 'directo',
            'descripcion' => $nombre,
            'precio' => (float) $directos[$nombre]->precio,
            'cantidad' => $cantidad,
            'detalle' => ['producto_id' => $directos[$nombre]->id],
        ];

        // 1. Helado armado + paleta, efectivo con vuelto, al portador.
        $servicio->facturar([
            ['tipo' => 'armado', 'descripcion' => 'Copa Grande: Vainilla, Chocolate, Fresa + Chispas de Chocolate, Caramelo', 'precio' => 300, 'cantidad' => 1],
            $directo('Paleta de Fresa', 2),
        ], $empresa, ['forma_pago' => FormaPago::EFECTIVO, 'monto_efectivo' => 1000, 'comprobante' => $consumo]);

        // 2. Combo familiar con tarjeta, cliente con cédula.
        $servicio->facturar([
            ['tipo' => 'combo', 'descripcion' => 'Combo Familiar', 'precio' => 700, 'cantidad' => 1],
        ], $empresa, [
            'forma_pago' => FormaPago::TARJETA,
            'comprobante' => $consumo,
            'cliente_id' => $consumo === ComprobanteVenta::SIN_COMPROBANTE ? null : $clientes['cedula']->id,
        ]);

        // 3. Venta con línea exenta (agua) + gravada (refresco), transferencia.
        $servicio->facturar([
            $directo('Agua 16 oz', 3),
            $directo('Refresco 20 oz', 2),
        ], $empresa, ['forma_pago' => FormaPago::TRANSFERENCIA, 'comprobante' => $consumo]);

        // 4. Crédito fiscal a empresa con RNC (pedido para una actividad).
        if ($credito !== null) {
            $servicio->facturar([
                ['tipo' => 'combo', 'descripcion' => 'Sundae Especial', 'precio' => 450, 'cantidad' => 10],
                $directo('Agua 16 oz', 10),
            ], $empresa, ['forma_pago' => FormaPago::TRANSFERENCIA, 'comprobante' => $credito, 'cliente_id' => $clientes['rnc']->id]);
        }
    }
}
