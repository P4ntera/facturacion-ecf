<?php

namespace App\Repositories\Contracts;

use App\Models\DetalleVenta;
use App\Models\Venta;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

interface VentaRepositoryInterface extends RepositoryInterface
{
    public function findConDetalles(int $id): ?Venta;

    /** @return Collection<int, Venta> */
    public function porRango(CarbonInterface $desde, CarbonInterface $hasta): Collection;

    public function agregarDetalle(Venta $venta, array $linea): DetalleVenta;

    public function agregarDetalles(Venta $venta, array $lineas): void;
}
