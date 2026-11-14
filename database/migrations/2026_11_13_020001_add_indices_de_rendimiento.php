<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices que faltaban, detectados midiendo las consultas reales de cada pantalla.
 *
 * PostgreSQL NO crea índice para una clave foránea: sin él, "las líneas de este documento" o
 * "los pagos de esta cuenta" recorren la tabla hija completa, y cada vez más lento según crece.
 * Solo se agregan los de tablas que crecen con el uso (detalles, pagos, ventas, movimientos);
 * los catálogos pequeños no lo necesitan.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> tabla => índices (lista de columnas) */
    private const INDICES = [
        // Líneas por documento padre (ninguna tenía índice por su padre).
        'detalle_cotizaciones' => [['cotizacion_id']],
        'detalle_ordenes_compra' => [['orden_compra_id']],
        'detalle_pedidos_compra' => [['pedido_compra_id']],
        'detalle_devoluciones_compra' => [['devolucion_compra_id'], ['detalle_compra_id']],
        'detalle_recepciones_compra' => [['recepcion_compra_id']],
        // Pagos por cuenta (estado de cuenta, saldo).
        'pagos_recibidos' => [['cuenta_por_cobrar_id']],
        'pagos_realizados' => [['cuenta_por_pagar_id']],
        // Movimientos y ventas por producto (kardex, top productos, margen).
        'detalle_ventas' => [['producto_id']],
        'detalle_compras' => [['producto_id']],
        'producto_presentaciones' => [['producto_id']],
        // Ventas: por arqueo (cierre de caja), por venta original (notas de crédito/débito).
        'ventas' => [['arqueo_caja_id'], ['venta_modificada_id']],
        // Arqueo abierto del cajero: se consulta en cada carga del POS.
        'arqueos_caja' => [['empresa_id', 'estado', 'user_id']],
        // Listados que ordenan por fecha dentro de la empresa.
        'tickets_lavanderia' => [['empresa_id', 'created_at'], ['venta_id']],
        'movimientos_inventario' => [['empresa_id', 'created_at']],
        'productos' => [['empresa_id', 'categoria_id']],
        // Bitácora: es la tabla que más crece y se lista por fecha.
        'activity_log' => [['created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($indices as $columnas) {
                if (! Schema::hasColumns($tabla, $columnas) || Schema::hasIndex($tabla, $columnas)) {
                    continue;
                }

                Schema::table($tabla, fn (Blueprint $t) => $t->index($columnas, $this->nombre($tabla, $columnas)));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($indices as $columnas) {
                if (Schema::hasIndex($tabla, $this->nombre($tabla, $columnas))) {
                    Schema::table($tabla, fn (Blueprint $t) => $t->dropIndex($this->nombre($tabla, $columnas)));
                }
            }
        }
    }

    /** @param list<string> $columnas */
    private function nombre(string $tabla, array $columnas): string
    {
        return 'idx_'.$tabla.'_'.implode('_', $columnas);
    }
};
