<?php

namespace App\Repositories\Eloquent;

use App\Models\Compra;
use App\Repositories\Contracts\CompraRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentCompraRepository extends BaseRepository implements CompraRepositoryInterface
{
    public function __construct(Compra $model)
    {
        parent::__construct($model);
    }

    public function findConDetalles(int $id): ?Compra
    {
        /** @var Compra|null */
        return $this->model->newQuery()->with(['detalles', 'proveedor'])->find($id);
    }

    public function porRango(CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return $this->model->newQuery()
            ->whereBetween('fecha', [$desde, $hasta])
            ->get();
    }

    public function agregarDetalles(Compra $compra, array $lineas): void
    {
        $compra->detalles()->createMany($lineas);
    }
}
