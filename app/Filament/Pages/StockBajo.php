<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Producto;
use App\Models\Proveedor;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class StockBajo extends Page
{
    protected string $view = 'filament.pages.stock-bajo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Compras';

    protected static ?string $navigationLabel = 'Stock Bajo';

    protected static ?string $title = 'Productos con Stock Bajo';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('gestionar_compras') ?? false;
    }

    /** @return Collection<int, Producto> */
    public function productosBajoMinimo(): Collection
    {
        return Producto::query()->bajoMinimo()->with('proveedores')->orderBy('nombre')->get();
    }

    /** @return Collection<int, array{proveedor: ?Proveedor, productos: Collection}> */
    public function gruposPorProveedor(): Collection
    {
        return $this->productosBajoMinimo()
            ->groupBy(fn (Producto $producto) => $producto->proveedorPrincipal()?->id ?? 'sin_proveedor')
            ->map(fn (Collection $productos, $key) => [
                'proveedor'  => $key === 'sin_proveedor' ? null : Proveedor::find($key),
                'productos'  => $productos,
            ])
            ->values();
    }
}
