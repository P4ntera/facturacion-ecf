<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Contracts
use App\Repositories\Contracts\ClienteRepositoryInterface;
use App\Repositories\Contracts\CompraRepositoryInterface;
use App\Repositories\Contracts\MovimientoInventarioRepositoryInterface;
use App\Repositories\Contracts\ProductoRepositoryInterface;
use App\Repositories\Contracts\ProveedorRepositoryInterface;
use App\Repositories\Contracts\VentaRepositoryInterface;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // activar cuando existan las implementaciones Eloquent
        // $this->app->bind(ProductoRepositoryInterface::class, \App\Repositories\Eloquent\EloquentProductoRepository::class);
        // $this->app->bind(ClienteRepositoryInterface::class, \App\Repositories\Eloquent\EloquentClienteRepository::class);
        // $this->app->bind(ProveedorRepositoryInterface::class, \App\Repositories\Eloquent\EloquentProveedorRepository::class);
        // $this->app->bind(VentaRepositoryInterface::class, \App\Repositories\Eloquent\EloquentVentaRepository::class);
        // $this->app->bind(CompraRepositoryInterface::class, \App\Repositories\Eloquent\EloquentCompraRepository::class);
        // $this->app->bind(MovimientoInventarioRepositoryInterface::class, \App\Repositories\Eloquent\EloquentMovimientoInventarioRepository::class);
    }
}
