<?php

namespace Database\Factories;

use App\Enums\TipoProveedor;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    public function definition(): array
    {
        return [
            // empresa_id: sin default aquí a propósito. En producción lo asocia Filament (tenant
            // ownership); en tests, Tests\Support\TenantDefaults lo rellena si no se pasa uno
            // explícito.
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
