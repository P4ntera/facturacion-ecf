<?php

namespace App\Models;

use App\Enums\TasaItbis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleDevolucionCompra extends Model
{
    use HasFactory;

    protected $table = 'detalle_devoluciones_compra';

    protected $fillable = [
        'devolucion_compra_id', 'detalle_compra_id', 'producto_id',
        'cantidad', 'costo_unitario', 'tasa_itbis', 'itbis_monto', 'subtotal',
    ];

    protected $casts = [
        'tasa_itbis'     => TasaItbis::class,
        'cantidad'       => 'decimal:3',
        'costo_unitario' => 'decimal:2',
        'itbis_monto'    => 'decimal:2',
        'subtotal'       => 'decimal:2',
    ];

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(DevolucionCompra::class, 'devolucion_compra_id');
    }

    public function detalleCompra(): BelongsTo
    {
        return $this->belongsTo(DetalleCompra::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
