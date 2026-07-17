<?php

namespace App\Models;

use App\Enums\TasaItbis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetallePedidoCompra extends Model
{
    use HasFactory;

    protected $table = 'detalle_pedidos_compra';

    protected $fillable = [
        'pedido_compra_id', 'producto_id',
        'cantidad', 'costo_unitario', 'tasa_itbis', 'itbis_monto', 'subtotal',
    ];

    protected $casts = [
        'tasa_itbis'     => TasaItbis::class,
        'cantidad'       => 'decimal:3',
        'costo_unitario' => 'decimal:2',
        'itbis_monto'    => 'decimal:2',
        'subtotal'       => 'decimal:2',
    ];

    public function pedidoCompra(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
