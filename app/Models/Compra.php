<?php

namespace App\Models;

use App\Enums\EstadoCompra;
use App\Enums\TipoComprobante;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Compra extends Model
{
    use HasFactory;

    protected $fillable = [
        'proveedor_id', 'user_id', 'tipo_comprobante', 'ncf',
        'fecha', 'subtotal', 'itbis', 'total',
        'estado', 'motivo_anulacion', 'anulada_en',
    ];

    protected $casts = [
        'tipo_comprobante' => TipoComprobante::class,
        'estado'           => EstadoCompra::class,
        'fecha'            => 'datetime',
        'anulada_en'       => 'datetime',
        'subtotal'         => 'decimal:2',
        'itbis'            => 'decimal:2',
        'total'            => 'decimal:2',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleCompra::class);
    }
}
