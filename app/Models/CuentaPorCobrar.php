<?php

namespace App\Models;

use App\Enums\EstadoCuenta;
use App\Enums\EstadoPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuentaPorCobrar extends Model
{
    protected $table = 'cuentas_por_cobrar';

    protected $fillable = [
        'empresa_id', 'venta_id', 'cliente_id',
        'monto_total', 'monto_pagado', 'fecha_emision', 'fecha_vencimiento',
    ];

    protected $casts = [
        'monto_total' => 'decimal:2',
        'monto_pagado' => 'decimal:2',
        'fecha_emision' => 'date',
        'fecha_vencimiento' => 'date',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoRecibido::class);
    }

    public function montoPendiente(): float
    {
        return round((float) $this->monto_total - (float) $this->monto_pagado, 2);
    }

    public function diasVencido(): int
    {
        if ($this->fecha_vencimiento->isFuture() || $this->fecha_vencimiento->isToday()) {
            return 0;
        }

        return (int) $this->fecha_vencimiento->diffInDays(today());
    }

    public function porcentajeCobrado(): float
    {
        if ((float) $this->monto_total <= 0) {
            return 0.0;
        }

        return round(((float) $this->monto_pagado / (float) $this->monto_total) * 100, 2);
    }

    public function estado(): EstadoCuenta
    {
        if ($this->montoPendiente() <= 0.0) {
            return EstadoCuenta::PAGADA;
        }

        if ($this->diasVencido() > 0) {
            return EstadoCuenta::VENCIDA;
        }

        if ((float) $this->monto_pagado > 0.0) {
            return EstadoCuenta::PARCIAL;
        }

        return EstadoCuenta::PENDIENTE;
    }

    public function pagosVigentes(): HasMany
    {
        return $this->pagos()->where('estado', EstadoPago::REGISTRADO);
    }
}
