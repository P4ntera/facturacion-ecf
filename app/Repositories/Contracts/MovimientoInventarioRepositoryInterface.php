<?php

namespace App\Repositories\Contracts;

use App\Models\MovimientoInventario;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

interface MovimientoInventarioRepositoryInterface extends RepositoryInterface
{
    public function registrar(array $data): MovimientoInventario;

    /** @return Collection<int, MovimientoInventario> */
    public function porProducto(int $productoId): Collection;

    /** @return Collection<int, MovimientoInventario> */
    public function porProductoEntreFechas(
        int $productoId,
        CarbonInterface $desde,
        CarbonInterface $hasta
    ): Collection;
}
