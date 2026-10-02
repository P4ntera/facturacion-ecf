<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pedido de compra y Orden de compra hacían lo mismo: se queda la Orden. Desde ahora "Recibir
 * mercancía" en una orden ES registrar la compra (con su NCF), y cada recepción queda ligada a la
 * compra que la registró. Así el stock entra una sola vez, por la compra.
 *
 * - recepciones_compra.compra_id: la compra que registró esa recepción.
 * - recepciones_compra.anulada_en: si se anula la compra, la recepción se marca anulada (no se
 *   borra) y la orden vuelve a tener pendiente lo que se había recibido.
 * - Los pedidos PENDIENTES pasan a órdenes de compra (enviada si el pedido ya se había enviado al
 *   proveedor, borrador si no), y el pedido queda cancelado con el número de la orden en el
 *   motivo. Los pedidos recibidos o cancelados se quedan como historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_compra', function (Blueprint $table) {
            $table->foreignId('compra_id')->nullable()->after('orden_compra_id')->constrained('compras')->restrictOnDelete();
            $table->timestamp('anulada_en')->nullable();
            $table->index('compra_id');
        });

        $this->migrarPedidosPendientes();
    }

    public function down(): void
    {
        Schema::table('recepciones_compra', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compra_id');
            $table->dropColumn('anulada_en');
        });
    }

    private function migrarPedidosPendientes(): void
    {
        $pedidos = DB::table('pedidos_compra')->where('estado', 'pendiente')->orderBy('id')->get();

        foreach ($pedidos as $pedido) {
            DB::transaction(function () use ($pedido) {
                $ultimo = DB::table('ordenes_compra')->where('empresa_id', $pedido->empresa_id)->orderByDesc('id')->value('numero');
                $numero = 'OC-'.str_pad((string) (($ultimo ? (int) Str::after($ultimo, 'OC-') : 0) + 1), 5, '0', STR_PAD_LEFT);

                $ordenId = DB::table('ordenes_compra')->insertGetId([
                    'empresa_id' => $pedido->empresa_id,
                    'proveedor_id' => $pedido->proveedor_id,
                    'user_id' => $pedido->user_id,
                    'numero' => $numero,
                    'fecha' => substr((string) $pedido->fecha, 0, 10),
                    'fecha_esperada' => null,
                    'notas' => trim("Migrado del pedido de compra #{$pedido->id}. ".($pedido->notas ?? '')),
                    'subtotal' => $pedido->subtotal,
                    'itbis' => $pedido->itbis,
                    'total' => $pedido->total,
                    'estado' => $pedido->enviado_en !== null ? 'enviada' : 'borrador',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach (DB::table('detalle_pedidos_compra')->where('pedido_compra_id', $pedido->id)->get() as $detalle) {
                    DB::table('detalle_ordenes_compra')->insert([
                        'orden_compra_id' => $ordenId,
                        'producto_id' => $detalle->producto_id,
                        'cantidad_solicitada' => $detalle->cantidad,
                        'cantidad_recibida' => 0,
                        'precio_unitario' => $detalle->costo_unitario,
                        'itbis' => $detalle->itbis_monto,
                        'subtotal' => $detalle->subtotal,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('pedidos_compra')->where('id', $pedido->id)->update([
                    'estado' => 'cancelado',
                    'motivo_cancelacion' => "Migrado a la orden de compra {$numero}.",
                    'cancelado_en' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }
};
