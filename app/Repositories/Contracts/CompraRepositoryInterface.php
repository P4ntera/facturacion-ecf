<?php

namespace App\Repositories\Contracts;

use App\Models\Compra;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

interface CompraRepositoryInterface extends RepositoryInterface
{
    public function findConDetalles(int $id): ?Compra;

    /** @return Collection<int, Compra> */
    public function porRango(CarbonInterface $desde, CarbonInterface $hasta): Collection;

    public function agregarDetalles(Compra $compra, array $lineas): void;
}
