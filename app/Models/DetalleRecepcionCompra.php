<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleRecepcionCompra extends Model
{
    protected $table = 'detalle_recepciones_compra';

    protected $fillable = [
        'recepcion_compra_id', 'detalle_orden_compra_id', 'producto_id', 'cantidad_recibida',
    ];

    protected $casts = [
        'cantidad_recibida' => 'decimal:4',
    ];

    public function recepcionCompra(): BelongsTo
    {
        return $this->belongsTo(RecepcionCompra::class);
    }

    public function detalleOrdenCompra(): BelongsTo
    {
        return $this->belongsTo(DetalleOrdenCompra::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
