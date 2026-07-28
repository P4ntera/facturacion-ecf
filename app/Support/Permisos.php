<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catálogo central de permisos granulares por módulo/pantalla. Única fuente de verdad,
 * consumida por: el seeder de roles (database/seeders/RolePermissionSeeder.php), la matriz de
 * RoleResource (agrupa el formulario por módulo) y cualquier Policy/gate que necesite listar
 * permisos válidos. Reemplaza los permisos gruesos anteriores (gestionar_maestros,
 * registrar_ventas, etc.) por uno por pantalla/acción.
 */
class Permisos
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function catalogo(): array
    {
        return [
            'Maestros' => [
                'productos.ver' => 'Ver productos',
                'productos.crear' => 'Crear productos',
                'productos.editar' => 'Editar productos',
                'productos.desactivar' => 'Activar/Desactivar productos',
                'clientes.ver' => 'Ver clientes',
                'clientes.crear' => 'Crear clientes',
                'clientes.editar' => 'Editar clientes',
                'clientes.desactivar' => 'Activar/Desactivar clientes',
                'proveedores.ver' => 'Ver proveedores',
                'proveedores.crear' => 'Crear proveedores',
                'proveedores.editar' => 'Editar proveedores',
                'proveedores.desactivar' => 'Activar/Desactivar proveedores',
                'categorias.ver' => 'Ver categorías',
                'categorias.crear' => 'Crear categorías',
                'categorias.editar' => 'Editar categorías',
                'categorias.desactivar' => 'Activar/Desactivar categorías',
            ],
            'Ventas' => [
                'pos.acceder' => 'Acceder al Punto de Venta',
                'ventas.ver' => 'Ver ventas',
                'ventas.anular' => 'Anular ventas',
                'ventas.imprimir' => 'Imprimir comprobantes y tickets',
            ],
            'Inventario' => [
                'kardex.ver' => 'Ver kardex de movimientos',
                'inventario.ajustar' => 'Ajustar stock',
            ],
            'Compras' => [
                'compras.ver' => 'Ver compras',
                'compras.crear' => 'Registrar compras',
                'compras.anular' => 'Anular compras',
                'devoluciones.ver' => 'Ver devoluciones a proveedor',
                'devoluciones.crear' => 'Registrar devoluciones a proveedor',
            ],
            'Reportes' => [
                'reportes.ver' => 'Ver reportes',
                'reportes.exportar' => 'Exportar reportes (PDF/Excel)',
            ],
            'Configuración' => [
                'empresa.administrar' => 'Administrar datos de la empresa',
                'facturacion.administrar' => 'Administrar configuración de facturación',
                'secuencias.administrar' => 'Administrar secuencias NCF',
                'impresoras.administrar' => 'Administrar impresoras',
                'usuarios.gestionar' => 'Gestionar usuarios',
                'roles.gestionar' => 'Gestionar roles y permisos',
                'auditoria.ver' => 'Ver auditoría',
                'ecf.gestionar' => 'Gestionar e-CF (envíos, recepción, estado fiscal)',
            ],
        ];
    }

    /**
     * Todos los nombres de permiso, sin agrupar. Usado para crearlos (seeder) y para el rol
     * Administrador, que siempre los tiene todos.
     *
     * @return array<int, string>
     */
    public static function todos(): array
    {
        return collect(self::catalogo())
            ->flatMap(fn (array $permisos) => array_keys($permisos))
            ->all();
    }

    /**
     * Etiqueta en español de un permiso, para pantallas que muestren su nombre legible
     * (por ejemplo, un listado plano fuera de la matriz agrupada de RoleResource).
     */
    public static function etiqueta(string $permiso): ?string
    {
        foreach (self::catalogo() as $permisos) {
            if (isset($permisos[$permiso])) {
                return $permisos[$permiso];
            }
        }

        return null;
    }
}
