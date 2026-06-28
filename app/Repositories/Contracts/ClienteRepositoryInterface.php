<?php

namespace App\Repositories\Contracts;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Collection;

interface ClienteRepositoryInterface extends RepositoryInterface
{
    public function findByDocumento(string $documento): ?Cliente;

    /** @return Collection<int, Cliente> */
    public function activos(): Collection;

    /** @return Collection<int, Cliente> */
    public function buscar(string $termino): Collection;
}
