<?php

namespace App\Models;

use App\Enums\EstadoDevolucion;
use App\Enums\TasaItbis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DetalleCompra extends Model
{
    use HasFactory;

    protected $fillable = [
        'compra_id', 'producto_id',
        'cantidad', 'costo_unitario', 'tasa_itbis', 'itbis_monto', 'subtotal',
    ];

    protected $casts = [
        'tasa_itbis'     => TasaItbis::class,
        'cantidad'       => 'decimal:3',
        'costo_unitario' => 'decimal:2',
        'itbis_monto'    => 'decimal:2',
        'subtotal'       => 'decimal:2',
    ];

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function devoluciones(): HasMany
    {
        return $this->hasMany(DetalleDevolucionCompra::class);
    }

    /** Suma de lo devuelto en devoluciones NO anuladas de esta línea. */
    public function cantidadDevuelta(): float
    {
        return (float) $this->devoluciones()
            ->whereHas('devolucion', fn ($q) => $q->where('estado', '!=', EstadoDevolucion::ANULADA))
            ->sum('cantidad');
    }

    /** Cuánto de lo comprado en esta línea aún puede devolverse. */
    public function cantidadDisponibleParaDevolver(): float
    {
        return max(0, (float) $this->cantidad - $this->cantidadDevuelta());
    }
}
