<?php

namespace App\Repositories\Eloquent;

use App\Models\MovimientoInventario;
use App\Repositories\Contracts\MovimientoInventarioRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentMovimientoInventarioRepository extends BaseRepository implements MovimientoInventarioRepositoryInterface
{
    public function __construct(MovimientoInventario $model)
    {
        parent::__construct($model);
    }

    public function registrar(array $data): MovimientoInventario
    {
        /** @var MovimientoInventario */
        return $this->model->newQuery()->create($data);
    }

    public function porProducto(int $productoId): Collection
    {
        return $this->model->newQuery()
            ->where('producto_id', $productoId)
            ->orderBy('created_at')
            ->get();
    }

    public function porProductoEntreFechas(
        int $productoId,
        CarbonInterface $desde,
        CarbonInterface $hasta
    ): Collection {
        return $this->model->newQuery()
            ->where('producto_id', $productoId)
            ->whereBetween('created_at', [$desde, $hasta])
            ->orderBy('created_at')
            ->get();
    }
}
