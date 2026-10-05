<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TipoNotificacion;

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
                'descuentos.ver' => 'Ver descuentos configurados',
                'descuentos.crear' => 'Crear descuentos',
                'descuentos.editar' => 'Editar descuentos',
                'descuentos.desactivar' => 'Activar/Desactivar descuentos',
                'listas_precio.ver' => 'Ver listas de precio',
                'listas_precio.crear' => 'Crear listas de precio',
                'listas_precio.editar' => 'Editar listas de precio',
                'listas_precio.desactivar' => 'Activar/Desactivar listas de precio',
            ],
            'Cotizaciones' => [
                'cotizaciones.ver' => 'Ver cotizaciones',
                'cotizaciones.crear' => 'Crear cotizaciones',
                'cotizaciones.editar' => 'Editar cotizaciones',
                'cotizaciones.aprobar' => 'Aprobar cotizaciones',
                'cotizaciones.facturar' => 'Convertir cotización a venta',
                'cotizaciones.exportar' => 'Exportar cotización a PDF',
            ],
            'Ventas' => [
                'pos.acceder' => 'Acceder a Caja (venta rápida)',
                'facturacion.acceder' => 'Acceder a Facturación (comprobantes avanzados)',
                'ventas.ver' => 'Ver ventas',
                'ventas.anular' => 'Anular ventas',
                'ventas.nota_debito' => 'Emitir Notas de Débito',
                'ventas.devolucion' => 'Realizar devoluciones de clientes',
                'ventas.devolucion_autorizar' => 'Autorizar devoluciones por encima del monto límite (supervisor)',
                'ventas.imprimir' => 'Imprimir comprobantes y tickets',
                'arqueo.cerrar_ajeno' => 'Cerrar cajas de otros cajeros (no solo la propia)',
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
                'ordenes_compra.ver' => 'Ver órdenes de compra',
                'ordenes_compra.crear' => 'Crear órdenes de compra',
                'ordenes_compra.editar' => 'Editar órdenes de compra',
                'ordenes_compra.aprobar' => 'Aprobar órdenes de compra',
                'ordenes_compra.recibir' => 'Registrar recepción de mercancía',
                'ordenes_compra.cancelar' => 'Cancelar órdenes de compra',
                'ordenes_compra.exportar' => 'Exportar OC a PDF',
            ],
            'Cuentas' => [
                'cxc.ver' => 'Ver cuentas por cobrar',
                'cxc.cobrar' => 'Registrar pagos de clientes',
                'cxc.anular' => 'Anular pagos de clientes',
                'cxp.ver' => 'Ver cuentas por pagar',
                'cxp.pagar' => 'Registrar pagos a proveedores',
                'cxp.anular' => 'Anular pagos a proveedores',
            ],
            'Reportes' => [
                'reportes.ver' => 'Ver reportes',
                'reportes.exportar' => 'Exportar reportes (PDF/Excel)',
                'reportes.compras' => 'Ver reporte de compras',
                'reportes.arqueos' => 'Ver reporte de arqueos',
                'reportes.kardex' => 'Ver reporte de kardex',
                'reportes.cxc' => 'Ver reporte de cuentas por cobrar',
                'reportes.cxp' => 'Ver reporte de cuentas por pagar',
                'reportes.margen' => 'Ver reporte de margen por producto',
            ],
            'Restaurante' => [
                'mesas.ver' => 'Ver áreas y mesas',
                'mesas.crear' => 'Crear áreas y mesas',
                'mesas.editar' => 'Editar áreas y mesas',
                'mesas.desactivar' => 'Desactivar mesas',
                'comandas.ver' => 'Ver comandas',
                'comandas.crear' => 'Crear/abrir comandas',
                'comandas.editar' => 'Editar comandas (agregar/quitar productos)',
                'comandas.cerrar' => 'Cerrar comanda y generar venta',
                'comandas.cancelar' => 'Cancelar comandas',
                'comandas.transferir' => 'Transferir comanda a otra mesa',
                'cocina.ver' => 'Ver pantalla de cocina (KDS)',
                'cocina.preparar' => 'Marcar ítems como preparados',
            ],
            'Configuración' => [
                'empresa.administrar' => 'Administrar datos de la empresa',
                'facturacion.administrar' => 'Administrar configuración de facturación',
                'secuencias.administrar' => 'Administrar secuencias NCF',
                'impresoras.administrar' => 'Administrar impresoras',
                'cajas.ver' => 'Ver cajas registradoras',
                'cajas.crear' => 'Crear cajas registradoras',
                'cajas.editar' => 'Editar/Activar/Desactivar cajas registradoras',
                'usuarios.gestionar' => 'Gestionar usuarios',
                'roles.gestionar' => 'Gestionar roles y permisos',
                'auditoria.ver' => 'Ver auditoría',
                'ecf.gestionar' => 'Gestionar e-CF (envíos, recepción, estado fiscal)',
            ],
            'Notificaciones' => TipoNotificacion::catalogoPermisos(),
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
