<?php

namespace App\Providers;

use App\Repositories\Contracts\ClienteRepositoryInterface;
use App\Repositories\Contracts\CompraRepositoryInterface;
use App\Repositories\Contracts\MovimientoInventarioRepositoryInterface;
use App\Repositories\Contracts\ProductoRepositoryInterface;
use App\Repositories\Contracts\ProveedorRepositoryInterface;
use App\Repositories\Contracts\VentaRepositoryInterface;
use App\Repositories\Eloquent\EloquentClienteRepository;
use App\Repositories\Eloquent\EloquentCompraRepository;
use App\Repositories\Eloquent\EloquentMovimientoInventarioRepository;
use App\Repositories\Eloquent\EloquentProductoRepository;
use App\Repositories\Eloquent\EloquentProveedorRepository;
use App\Repositories\Eloquent\EloquentVentaRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductoRepositoryInterface::class,             EloquentProductoRepository::class);
        $this->app->bind(ClienteRepositoryInterface::class,              EloquentClienteRepository::class);
        $this->app->bind(ProveedorRepositoryInterface::class,            EloquentProveedorRepository::class);
        $this->app->bind(VentaRepositoryInterface::class,                EloquentVentaRepository::class);
        $this->app->bind(CompraRepositoryInterface::class,               EloquentCompraRepository::class);
        $this->app->bind(MovimientoInventarioRepositoryInterface::class, EloquentMovimientoInventarioRepository::class);
    }
}
