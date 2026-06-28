<?php

namespace App\Repositories\Eloquent;

use App\Models\Proveedor;
use App\Repositories\Contracts\ProveedorRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentProveedorRepository extends BaseRepository implements ProveedorRepositoryInterface
{
    public function __construct(Proveedor $model)
    {
        parent::__construct($model);
    }

    public function findByRnc(string $rnc): ?Proveedor
    {
        /** @var Proveedor|null */
        return $this->model->newQuery()->where('rnc', $rnc)->first();
    }

    public function activos(): Collection
    {
        return $this->model->newQuery()->where('activo', true)->get();
    }
}
