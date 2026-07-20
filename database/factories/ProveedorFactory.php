<?php

namespace Database\Factories;

use App\Enums\TipoProveedor;
use App\Models\Empresa;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'rnc' => fake()->unique()->numerify('#########'),
            'tipo' => TipoProveedor::FORMAL->value,
            'nombre' => fake()->company(),
            'nombre_comercial' => null,
            'actividad_economica' => null,
            'telefono' => null,
            'email' => null,
            'direccion' => null,
            'estado' => 'ACTIVO',
            'activo' => true,
        ];
    }

    public function informal(): static
    {
        return $this->state(fn () => ['tipo' => TipoProveedor::INFORMAL->value]);
    }
}
