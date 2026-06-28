<?php

namespace App\Repositories\Contracts;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Collection;

interface ProductoRepositoryInterface extends RepositoryInterface
{
    public function findByCodigo(string $codigo): ?Producto;

    /** @return Collection<int, Producto> */
    public function activos(): Collection;

    /** @return Collection<int, Producto> */
    public function bajoMinimo(): Collection;

    public function lockById(int $id): ?Producto;
}
