<?php

namespace App\Repositories\Eloquent;

use App\Models\Producto;
use App\Repositories\Contracts\ProductoRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentProductoRepository extends BaseRepository implements ProductoRepositoryInterface
{
    public function __construct(Producto $model)
    {
        parent::__construct($model);
    }

    public function findByCodigo(string $codigo): ?Producto
    {
        /** @var Producto|null */
        return $this->model->newQuery()->where('codigo', $codigo)->first();
    }

    public function activos(): Collection
    {
        return $this->model->newQuery()->where('activo', true)->get();
    }

    public function bajoMinimo(): Collection
    {
        return $this->model->newQuery()
            ->where('controla_stock', true)
            ->whereColumn('stock', '<=', 'stock_minimo')
            ->get();
    }

    public function lockById(int $id): ?Producto
    {
        /** @var Producto|null */
        return $this->model->newQuery()->whereKey($id)->lockForUpdate()->first();
    }
}
