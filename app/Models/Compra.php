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
        'proveedor_id', 'user_id', 'tipo_comprobante', 'ncf', 'fecha',
        'itbis_incluido',
        'subtotal', 'monto_gravado_18', 'monto_gravado_16', 'monto_gravado_0', 'monto_exento',
        'itbis_18', 'itbis_16', 'itbis', 'total',
        'estado', 'motivo_anulacion', 'anulada_en',
    ];

    protected $casts = [
        'tipo_comprobante' => TipoComprobante::class,
        'estado'           => EstadoCompra::class,
        'itbis_incluido'   => 'boolean',
        'fecha'            => 'datetime',
        'anulada_en'       => 'datetime',
        'subtotal'         => 'decimal:2',
        'monto_gravado_18' => 'decimal:2',
        'monto_gravado_16' => 'decimal:2',
        'monto_gravado_0'  => 'decimal:2',
        'monto_exento'     => 'decimal:2',
        'itbis_18'         => 'decimal:2',
        'itbis_16'         => 'decimal:2',
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

    public function estaAnulada(): bool
    {
        return $this->estado === EstadoCompra::ANULADA;
    }
}
