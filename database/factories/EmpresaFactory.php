<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    public function definition(): array
    {
        return [
            'rnc' => fake()->unique()->numerify('#########'),
            'razon_social' => fake()->unique()->company(),
            'nombre_comercial' => null,
            'usa_ecf' => true,
            'activa' => true,
        ];
    }
}
