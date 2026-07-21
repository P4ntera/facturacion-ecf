<?php

namespace App\Models;

use App\Enums\EstadoArqueoCaja;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArqueoCaja extends Model
{
    protected $table = 'arqueos_caja';

    protected $fillable = [
        'user_id', 'fondo_inicial', 'abierto_en', 'cerrado_en', 'estado',
        'total_ventas_efectivo', 'total_ventas_tarjeta', 'total_ventas_transferencia',
        'efectivo_esperado', 'efectivo_contado', 'diferencia', 'notas',
    ];

    protected $casts = [
        'estado'                     => EstadoArqueoCaja::class,
        'abierto_en'                 => 'datetime',
        'cerrado_en'                 => 'datetime',
        'fondo_inicial'              => 'decimal:2',
        'total_ventas_efectivo'      => 'decimal:2',
        'total_ventas_tarjeta'       => 'decimal:2',
        'total_ventas_transferencia' => 'decimal:2',
        'efectivo_esperado'          => 'decimal:2',
        'efectivo_contado'           => 'decimal:2',
        'diferencia'                 => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'arqueo_caja_id');
    }

    public function estaAbierto(): bool
    {
        return $this->estado === EstadoArqueoCaja::ABIERTO;
    }

    public function estaCerrado(): bool
    {
        return $this->estado === EstadoArqueoCaja::CERRADO;
    }
}
