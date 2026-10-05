<?php

namespace App\Models;

use App\Enums\DestinoDevolucion;
use App\Enums\TasaItbis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleVenta extends Model
{
    use HasFactory;

    protected $fillable = [
        'venta_id', 'producto_id', 'presentacion_id', 'descripcion',
        'cantidad', 'factor', 'precio_unitario', 'descuento',
        'tasa_itbis', 'itbis_monto', 'subtotal', 'costo_unitario', 'destino_devolucion',
    ];

    protected $casts = [
        'tasa_itbis' => TasaItbis::class,
        'cantidad' => 'decimal:3',
        'factor' => 'decimal:3',
        'precio_unitario' => 'decimal:2',
        'descuento' => 'decimal:2',
        'itbis_monto' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'costo_unitario' => 'decimal:2',
        'destino_devolucion' => DestinoDevolucion::class,
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class);
    }
}
