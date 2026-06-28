<?php

namespace App\Repositories\Eloquent;

use App\Models\Cliente;
use App\Repositories\Contracts\ClienteRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentClienteRepository extends BaseRepository implements ClienteRepositoryInterface
{
    public function __construct(Cliente $model)
    {
        parent::__construct($model);
    }

    public function findByDocumento(string $documento): ?Cliente
    {
        /** @var Cliente|null */
        return $this->model->newQuery()->where('documento', $documento)->first();
    }

    public function activos(): Collection
    {
        return $this->model->newQuery()->where('activo', true)->get();
    }

    public function buscar(string $termino): Collection
    {
        return $this->model->newQuery()
            ->where('nombre', 'ILIKE', "%{$termino}%")
            ->orWhere('documento', 'ILIKE', "%{$termino}%")
            ->get();
    }
}
