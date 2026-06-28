<?php
$interfaces = [
    \App\Repositories\Contracts\ProductoRepositoryInterface::class,
    \App\Repositories\Contracts\ClienteRepositoryInterface::class,
    \App\Repositories\Contracts\ProveedorRepositoryInterface::class,
    \App\Repositories\Contracts\VentaRepositoryInterface::class,
    \App\Repositories\Contracts\CompraRepositoryInterface::class,
    \App\Repositories\Contracts\MovimientoInventarioRepositoryInterface::class,
];
foreach ($interfaces as $iface) {
    echo get_class(app($iface)) . PHP_EOL;
}
