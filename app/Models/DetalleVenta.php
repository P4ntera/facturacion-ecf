<?php

namespace App\Models;

use App\Enums\TasaItbis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleVenta extends Model
{
    use HasFactory;

    protected $fillable = [
        'venta_id', 'producto_id', 'descripcion',
        'cantidad', 'precio_unitario', 'descuento',
        'tasa_itbis', 'itbis_monto', 'subtotal',
    ];

    protected $casts = [
        'tasa_itbis'      => TasaItbis::class,
        'cantidad'        => 'decimal:3',
        'precio_unitario' => 'decimal:2',
        'descuento'       => 'decimal:2',
        'itbis_monto'     => 'decimal:2',
        'subtotal'        => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
