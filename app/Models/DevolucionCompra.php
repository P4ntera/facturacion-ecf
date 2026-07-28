<?php

namespace App\Models;

use App\Enums\EstadoDevolucion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DevolucionCompra extends Model
{
    use HasFactory;

    protected $table = 'devoluciones_compra';

    protected $fillable = [
        'empresa_id', 'compra_id', 'proveedor_id', 'user_id', 'fecha', 'motivo',
        'subtotal', 'monto_gravado_18', 'monto_gravado_16', 'monto_gravado_0',
        'itbis_18', 'itbis_16', 'itbis', 'total',
        'estado', 'motivo_anulacion', 'anulada_en',
    ];

    protected $casts = [
        'estado' => EstadoDevolucion::class,
        'fecha' => 'datetime',
        'anulada_en' => 'datetime',
        'subtotal' => 'decimal:2',
        'monto_gravado_18' => 'decimal:2',
        'monto_gravado_16' => 'decimal:2',
        'monto_gravado_0' => 'decimal:2',
        'itbis_18' => 'decimal:2',
        'itbis_16' => 'decimal:2',
        'itbis' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

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
        return $this->hasMany(DetalleDevolucionCompra::class, 'devolucion_compra_id');
    }

    public function estaAnulada(): bool
    {
        return $this->estado === EstadoDevolucion::ANULADA;
    }
}
