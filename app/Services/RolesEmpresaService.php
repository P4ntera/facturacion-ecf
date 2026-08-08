<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Empresa;
use App\Models\Role;
use App\Models\User;
use App\Support\Permisos;
use Spatie\Permission\PermissionRegistrar;

/**
 * Los roles son por empresa (spatie/laravel-permission con 'teams' => true, empresa_id como
 * team_foreign_key): no hay un "Vendedor" global, cada empresa tiene el suyo. Este service
 * concentra la siembra de los roles base y la asignación del primer Administrador, para no
 * repetir el manejo del contexto de permisos (setPermissionsTeamId) en cada sitio que da de
 * alta una empresa (el seeder principal y EmpresaResource).
 */
class RolesEmpresaService
{
    public function sembrarRolesBase(Empresa $empresa): void
    {
        $this->dentroDelContextoDe($empresa, function () use ($empresa) {
            $administrador = Role::firstOrCreate([
                'empresa_id' => $empresa->id,
                'name' => 'Administrador',
                'guard_name' => 'web',
            ]);
            // Preserva el hueco preexistente en Permisos::catalogo() (gestionar_arqueo_caja no
            // está listado ahí): Administrador debe seguir teniendo TODOS los permisos reales,
            // no solo los del catálogo.
            $administrador->syncPermissions([...Permisos::todos(), 'gestionar_arqueo_caja']);

            $vendedor = Role::firstOrCreate([
                'empresa_id' => $empresa->id,
                'name' => 'Vendedor',
                'guard_name' => 'web',
            ]);
            $vendedor->syncPermissions([
                // Vendedor conserva ambas pantallas: Caja para el día a día rápido, y
                // Facturación por si necesita emitir un comprobante con más control (a
                // diferencia de Cajero, que un Administrador crea aparte solo con Caja).
                'pos.acceder',
                'facturacion.acceder',
                'ventas.ver',
                'ventas.imprimir',
                'gestionar_arqueo_caja',
                // "Maestros" del Vendedor se limita a consultar lo que necesita para vender
                // (precios de productos, datos de clientes); crear/editar/desactivar maestros
                // queda fuera de su pantalla de trabajo.
                'productos.ver',
                'clientes.ver',
                // Exportar va junto con ver: un vendedor que puede consultar un reporte también
                // necesita poder sacarlo en PDF/Excel para el día a día (cierre de caja, etc.).
                'reportes.ver',
                'reportes.exportar',
                // Cobrar facturas a crédito es parte del día a día de quien vende; corregir un
                // pago mal registrado (cxc.anular) queda para Administrador, igual que anular
                // ventas/compras.
                'cxc.ver',
                'cxc.cobrar',
            ]);

            $almacenista = Role::firstOrCreate([
                'empresa_id' => $empresa->id,
                'name' => 'Almacenista',
                'guard_name' => 'web',
            ]);
            $almacenista->syncPermissions([
                'inventario.ajustar',
                'kardex.ver',
                'compras.ver',
                'compras.crear',
                // compras.anular queda fuera a propósito: anular una compra tiene impacto contable/
                // de inventario que se reserva al Administrador (regla ya existente y probada).
                'devoluciones.ver',
                'devoluciones.crear',
                'productos.ver',
                'productos.crear',
                'productos.editar',
                'productos.desactivar',
                // Solo lectura: necesita ver el proveedor al registrar una compra, pero darlo de
                // alta/editarlo es tarea de quien mantiene los maestros, no del almacén.
                'proveedores.ver',
                // Solo consulta: saber cuánto se le debe a un proveedor ayuda a decidir si
                // comprarle de nuevo, pero pagarle es una decisión financiera de Administrador.
                'cxp.ver',
            ]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Asigna el rol Administrador de $empresa a $usuario, dentro del contexto de esa empresa. */
    public function asignarAdministrador(User $usuario, Empresa $empresa): void
    {
        $this->dentroDelContextoDe($empresa, function () use ($usuario) {
            $usuario->unsetRelation('roles')->unsetRelation('permissions');
            $usuario->assignRole('Administrador');
        });
    }

    /**
     * Ejecuta $callback con el contexto de permisos fijado en $empresa, y restaura el contexto
     * anterior al salir (incluso si $callback lanza).
     */
    private function dentroDelContextoDe(Empresa $empresa, callable $callback): void
    {
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId($empresa->id);

        try {
            $callback();
        } finally {
            setPermissionsTeamId($anterior);
        }
    }
}
