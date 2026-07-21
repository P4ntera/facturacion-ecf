<?php

namespace App\Models;

use App\Enums\EstadoPedidoCompra;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoCompra extends Model
{
    use HasFactory;

    protected $table = 'pedidos_compra';

    protected $fillable = [
        'proveedor_id', 'user_id', 'fecha', 'notas',
        'subtotal', 'monto_gravado_18', 'monto_gravado_16', 'monto_gravado_0', 'monto_exento',
        'itbis_18', 'itbis_16', 'itbis', 'total',
        'estado', 'enviado_en', 'enviado_a', 'motivo_cancelacion', 'cancelado_en',
    ];

    protected $casts = [
        'estado'               => EstadoPedidoCompra::class,
        'fecha'                => 'datetime',
        'enviado_en'           => 'datetime',
        'cancelado_en'         => 'datetime',
        'subtotal'             => 'decimal:2',
        'monto_gravado_18'     => 'decimal:2',
        'monto_gravado_16'     => 'decimal:2',
        'monto_gravado_0'      => 'decimal:2',
        'monto_exento'         => 'decimal:2',
        'itbis_18'             => 'decimal:2',
        'itbis_16'             => 'decimal:2',
        'itbis'                => 'decimal:2',
        'total'                => 'decimal:2',
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
        return $this->hasMany(DetallePedidoCompra::class);
    }

    public function estaCancelado(): bool
    {
        return $this->estado === EstadoPedidoCompra::CANCELADO;
    }

    public function fueEnviado(): bool
    {
        return $this->enviado_en !== null;
    }
}
