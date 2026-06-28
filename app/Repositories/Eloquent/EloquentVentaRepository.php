<?php

namespace App\Repositories\Eloquent;

use App\Models\DetalleVenta;
use App\Models\Venta;
use App\Repositories\Contracts\VentaRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentVentaRepository extends BaseRepository implements VentaRepositoryInterface
{
    public function __construct(Venta $model)
    {
        parent::__construct($model);
    }

    public function findConDetalles(int $id): ?Venta
    {
        /** @var Venta|null */
        return $this->model->newQuery()->with(['detalles', 'cliente'])->find($id);
    }

    public function porRango(CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return $this->model->newQuery()
            ->whereBetween('fecha', [$desde, $hasta])
            ->get();
    }

    public function agregarDetalle(Venta $venta, array $linea): DetalleVenta
    {
        /** @var DetalleVenta */
        return $venta->detalles()->create($linea);
    }

    public function agregarDetalles(Venta $venta, array $lineas): void
    {
        $venta->detalles()->createMany($lineas);
    }
}
