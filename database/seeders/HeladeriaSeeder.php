<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CategoriaSabor;
use App\Models\ComboHelado;
use App\Models\Empresa;
use App\Models\PresentacionHelado;
use App\Models\SaborHelado;
use App\Models\ToppingHelado;
use Illuminate\Database\Seeder;

/**
 * Seeder de datos demo para el módulo de heladería.
 * Siembra sabores, presentaciones, toppings y combos para TODAS las empresas existentes.
 */
class HeladeriaSeeder extends Seeder
{
    public function run(): void
    {
        $empresas = Empresa::all();

        foreach ($empresas as $empresa) {
            $this->sembrarSabores($empresa);
            $this->sembrarPresentaciones($empresa);
            $this->sembrarToppings($empresa);
            $this->sembrarCombos($empresa);
        }
    }

    private function sembrarSabores(Empresa $empresa): void
    {
        $sabores = [
            // Cremosos
            ['nombre' => 'Vainilla',          'color_hex' => '#F5E6CA', 'categoria' => CategoriaSabor::CREMOSO,  'premium' => false, 'orden' => 1],
            ['nombre' => 'Chocolate',         'color_hex' => '#5C3317', 'categoria' => CategoriaSabor::CREMOSO,  'premium' => false, 'orden' => 2],
            ['nombre' => 'Cookies & Cream',   'color_hex' => '#D2C6B2', 'categoria' => CategoriaSabor::CREMOSO,  'premium' => false, 'orden' => 3],
            ['nombre' => 'Dulce de Leche',    'color_hex' => '#C68E3C', 'categoria' => CategoriaSabor::CREMOSO,  'premium' => false, 'orden' => 4],
            ['nombre' => 'Mantecado',         'color_hex' => '#FFF8DC', 'categoria' => CategoriaSabor::CREMOSO,  'premium' => false, 'orden' => 5],
            ['nombre' => 'Ron con Pasas',     'color_hex' => '#D4A574', 'categoria' => CategoriaSabor::CREMOSO,  'premium' => true,  'orden' => 6],
            ['nombre' => 'Nutella',           'color_hex' => '#6B3A2A', 'categoria' => CategoriaSabor::CREMOSO,  'premium' => true,  'orden' => 7],

            // Frutales
            ['nombre' => 'Fresa',             'color_hex' => '#E8456B', 'categoria' => CategoriaSabor::FRUTAL,   'premium' => false, 'orden' => 8],
            ['nombre' => 'Limón',             'color_hex' => '#C5E17A', 'categoria' => CategoriaSabor::FRUTAL,   'premium' => false, 'orden' => 9],
            ['nombre' => 'Chinola',           'color_hex' => '#F5A623', 'categoria' => CategoriaSabor::FRUTAL,   'premium' => false, 'orden' => 10],
            ['nombre' => 'Coco',              'color_hex' => '#FAFAFA', 'categoria' => CategoriaSabor::FRUTAL,   'premium' => false, 'orden' => 11],
            ['nombre' => 'Mango',             'color_hex' => '#FFB347', 'categoria' => CategoriaSabor::FRUTAL,   'premium' => false, 'orden' => 12],

            // Especiales
            ['nombre' => 'Pistacchio',        'color_hex' => '#93C572', 'categoria' => CategoriaSabor::ESPECIAL, 'premium' => true,  'orden' => 13],
            ['nombre' => 'Matcha',            'color_hex' => '#78A55A', 'categoria' => CategoriaSabor::ESPECIAL, 'premium' => true,  'orden' => 14],
            ['nombre' => 'Cheesecake',        'color_hex' => '#F5DEB3', 'categoria' => CategoriaSabor::ESPECIAL, 'premium' => true,  'orden' => 15],
        ];

        foreach ($sabores as $sabor) {
            SaborHelado::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $sabor['nombre']],
                array_merge($sabor, [
                    'disponible' => true,
                    'activo'     => true,
                ]),
            );
        }
    }

    private function sembrarPresentaciones(Empresa $empresa): void
    {
        $presentaciones = [
            ['nombre' => 'Cono Sencillo',  'icono' => '🍦', 'scoops_incluidos' => 1, 'toppings_incluidos' => 0, 'precio' => 100, 'orden' => 1],
            ['nombre' => 'Cono Doble',     'icono' => '🍦', 'scoops_incluidos' => 2, 'toppings_incluidos' => 1, 'precio' => 175, 'orden' => 2],
            ['nombre' => 'Cono Triple',    'icono' => '🍦', 'scoops_incluidos' => 3, 'toppings_incluidos' => 1, 'precio' => 225, 'orden' => 3],
            ['nombre' => 'Copa Pequeña',   'icono' => '🍨', 'scoops_incluidos' => 2, 'toppings_incluidos' => 1, 'precio' => 200, 'orden' => 4],
            ['nombre' => 'Copa Grande',    'icono' => '🍨', 'scoops_incluidos' => 3, 'toppings_incluidos' => 2, 'precio' => 300, 'orden' => 5],
            ['nombre' => 'Sundae',         'icono' => '🥤', 'scoops_incluidos' => 2, 'toppings_incluidos' => 3, 'precio' => 275, 'orden' => 6],
            ['nombre' => 'Banana Split',   'icono' => '🍌', 'scoops_incluidos' => 3, 'toppings_incluidos' => 3, 'precio' => 350, 'orden' => 7],
        ];

        foreach ($presentaciones as $p) {
            PresentacionHelado::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $p['nombre']],
                array_merge($p, ['activo' => true]),
            );
        }
    }

    private function sembrarToppings(Empresa $empresa): void
    {
        $toppings = [
            ['nombre' => 'Chispas de Chocolate', 'icono' => '🍫', 'precio' => 35, 'orden' => 1],
            ['nombre' => 'Gomitas',              'icono' => '🍬', 'precio' => 40, 'orden' => 2],
            ['nombre' => 'Galleta Oreo',         'icono' => '🍪', 'precio' => 45, 'orden' => 3],
            ['nombre' => 'Maní Triturado',       'icono' => '🥜', 'precio' => 30, 'orden' => 4],
            ['nombre' => 'Sirope de Chocolate',  'icono' => '🫠', 'precio' => 25, 'orden' => 5],
            ['nombre' => 'Sirope de Fresa',      'icono' => '🍓', 'precio' => 25, 'orden' => 6],
            ['nombre' => 'Caramelo',             'icono' => '🍯', 'precio' => 25, 'orden' => 7],
            ['nombre' => 'Crema Batida',         'icono' => '🍰', 'precio' => 30, 'orden' => 8],
            ['nombre' => 'Sprinkles',            'icono' => '✨', 'precio' => 20, 'orden' => 9],
            ['nombre' => 'Brownie',              'icono' => '🟫', 'precio' => 50, 'orden' => 10],
        ];

        foreach ($toppings as $t) {
            ToppingHelado::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $t['nombre']],
                array_merge($t, ['activo' => true]),
            );
        }
    }

    private function sembrarCombos(Empresa $empresa): void
    {
        $combos = [
            [
                'nombre'             => 'Combo Niño Feliz',
                'icono'              => '👶',
                'descripcion'        => '1 cono sencillo + 1 topping + 1 agua',
                'scoops_incluidos'   => 1,
                'toppings_incluidos' => 1,
                'precio'             => 150,
                'orden'              => 1,
            ],
            [
                'nombre'             => 'Combo Pareja',
                'icono'              => '💕',
                'descripcion'        => '2 conos dobles + 2 toppings',
                'scoops_incluidos'   => 4,
                'toppings_incluidos' => 2,
                'precio'             => 400,
                'orden'              => 2,
            ],
            [
                'nombre'             => 'Combo Familiar',
                'icono'              => '👨‍👩‍👧‍👦',
                'descripcion'        => '4 copas pequeñas + 4 toppings',
                'scoops_incluidos'   => 8,
                'toppings_incluidos' => 4,
                'precio'             => 700,
                'orden'              => 3,
            ],
            [
                'nombre'             => 'Sundae Especial',
                'icono'              => '🌟',
                'descripcion'        => 'Sundae grande con 3 scoops premium + todos los toppings',
                'scoops_incluidos'   => 3,
                'toppings_incluidos' => 5,
                'precio'             => 450,
                'orden'              => 4,
            ],
        ];

        foreach ($combos as $c) {
            ComboHelado::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $c['nombre']],
                array_merge($c, ['activo' => true]),
            );
        }
    }
}
