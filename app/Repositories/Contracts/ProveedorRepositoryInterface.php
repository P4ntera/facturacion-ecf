<?php

namespace App\Repositories\Contracts;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Collection;

interface ProveedorRepositoryInterface extends RepositoryInterface
{
    public function findByRnc(string $rnc): ?Proveedor;

    /** @return Collection<int, Proveedor> */
    public function activos(): Collection;
}
