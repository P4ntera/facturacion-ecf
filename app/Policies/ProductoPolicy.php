<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Producto;
use App\Models\User;

class ProductoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('productos.ver');
    }

    public function view(User $user, Producto $producto): bool
    {
        return $user->can('productos.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('productos.crear');
    }

    public function update(User $user, Producto $producto): bool
    {
        return $user->can('productos.editar');
    }

    /**
     * Decisión de diseño deliberada, no un permiso pendiente: un producto puede estar referenciado
     * por ventas/compras/movimientos de inventario históricos, así que nunca se borra físicamente
     * (mismo criterio en todas las Policies de este proyecto — ver p. ej. ProveedorPolicy,
     * CategoriaPolicy, VentaPolicy — salvo UserPolicy, donde sí aplica un borrado real). El
     * catálogo de permisos (App\Support\Permisos) no define "productos.eliminar" a propósito.
     * La alternativa es desactivarlo: Toggle::make('activo') en ProductoResource, gateado por el
     * permiso 'productos.desactivar'.
     */
    public function delete(User $user, Producto $producto): bool
    {
        return false;
    }
}
