<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade índices compuestos empresa_id + fecha (y empresa_id + FK) en las tablas
 * transaccionales más consultadas. PostgreSQL ya crea un índice por cada FK, pero
 * las queries de panel/reportes siempre filtran primero por empresa_id (tenant) y
 * luego por fecha o por relación; sin un índice compuesto, PostgreSQL hace un index
 * scan + filter que se degrada con el volumen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Cabeceras ---
        Schema::table('ventas', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha'], 'ventas_empresa_fecha_idx');
            $table->index(['empresa_id', 'estado'], 'ventas_empresa_estado_idx');
            $table->index(['empresa_id', 'cliente_id'], 'ventas_empresa_cliente_idx');
        });

        Schema::table('compras', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha'], 'compras_empresa_fecha_idx');
            $table->index(['empresa_id', 'estado'], 'compras_empresa_estado_idx');
            $table->index(['empresa_id', 'proveedor_id'], 'compras_empresa_proveedor_idx');
        });

        // --- Cuentas por cobrar / pagar ---
        Schema::table('cuentas_por_cobrar', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha_vencimiento'], 'cxc_empresa_vencimiento_idx');
            $table->index(['empresa_id', 'cliente_id'], 'cxc_empresa_cliente_idx');
        });

        Schema::table('cuentas_por_pagar', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha_vencimiento'], 'cxp_empresa_vencimiento_idx');
            $table->index(['empresa_id', 'proveedor_id'], 'cxp_empresa_proveedor_idx');
        });

        // --- Detalles ---
        Schema::table('detalle_ventas', function (Blueprint $table) {
            $table->index(['venta_id', 'producto_id'], 'det_ventas_venta_producto_idx');
        });

        Schema::table('detalle_compras', function (Blueprint $table) {
            $table->index(['compra_id', 'producto_id'], 'det_compras_compra_producto_idx');
        });

        // --- Pagos ---
        Schema::table('pagos_recibidos', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha'], 'pagos_recibidos_empresa_fecha_idx');
        });

        Schema::table('pagos_realizados', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha'], 'pagos_realizados_empresa_fecha_idx');
        });

        // --- Inventario (Kardex) ---
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->index(['empresa_id', 'producto_id', 'created_at'], 'kardex_empresa_producto_fecha_idx');
        });

        // --- Productos ---
        Schema::table('productos', function (Blueprint $table) {
            $table->index(['empresa_id', 'activo'], 'productos_empresa_activo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex('ventas_empresa_fecha_idx');
            $table->dropIndex('ventas_empresa_estado_idx');
            $table->dropIndex('ventas_empresa_cliente_idx');
        });

        Schema::table('compras', function (Blueprint $table) {
            $table->dropIndex('compras_empresa_fecha_idx');
            $table->dropIndex('compras_empresa_estado_idx');
            $table->dropIndex('compras_empresa_proveedor_idx');
        });

        Schema::table('cuentas_por_cobrar', function (Blueprint $table) {
            $table->dropIndex('cxc_empresa_vencimiento_idx');
            $table->dropIndex('cxc_empresa_cliente_idx');
        });

        Schema::table('cuentas_por_pagar', function (Blueprint $table) {
            $table->dropIndex('cxp_empresa_vencimiento_idx');
            $table->dropIndex('cxp_empresa_proveedor_idx');
        });

        Schema::table('detalle_ventas', function (Blueprint $table) {
            $table->dropIndex('det_ventas_venta_producto_idx');
        });

        Schema::table('detalle_compras', function (Blueprint $table) {
            $table->dropIndex('det_compras_compra_producto_idx');
        });

        Schema::table('pagos_recibidos', function (Blueprint $table) {
            $table->dropIndex('pagos_recibidos_empresa_fecha_idx');
        });

        Schema::table('pagos_realizados', function (Blueprint $table) {
            $table->dropIndex('pagos_realizados_empresa_fecha_idx');
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropIndex('kardex_empresa_producto_fecha_idx');
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->dropIndex('productos_empresa_activo_idx');
        });
    }
};
