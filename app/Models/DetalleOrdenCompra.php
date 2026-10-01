<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleOrdenCompra extends Model
{
    protected $table = 'detalle_ordenes_compra';

    protected $fillable = [
        'orden_compra_id', 'producto_id', 'cantidad_solicitada',
        'cantidad_recibida', 'precio_unitario', 'itbis', 'subtotal',
    ];

    protected $casts = [
        'cantidad_solicitada' => 'decimal:4',
        'cantidad_recibida' => 'decimal:4',
        'precio_unitario' => 'decimal:2',
        'itbis' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected $attributes = [
        'cantidad_recibida' => 0,
        'itbis' => 0,
    ];

    public function ordenCompra(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function cantidadPendiente(): string
    {
        return bcsub((string) $this->cantidad_solicitada, (string) $this->cantidad_recibida, 4);
    }
}
