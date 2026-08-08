<?php

namespace App\Models;

use App\Enums\EstadoPago;
use App\Enums\FormaPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoRealizado extends Model
{
    protected $table = 'pagos_realizados';

    protected $fillable = [
        'empresa_id', 'cuenta_por_pagar_id', 'monto', 'fecha', 'forma_pago', 'referencia',
        'user_id', 'estado', 'motivo_anulacion', 'anulado_en',
    ];

    protected $casts = [
        'forma_pago' => FormaPago::class,
        'estado' => EstadoPago::class,
        'fecha' => 'date',
        'anulado_en' => 'datetime',
        'monto' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cuentaPorPagar(): BelongsTo
    {
        return $this->belongsTo(CuentaPorPagar::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function estaAnulado(): bool
    {
        return $this->estado === EstadoPago::ANULADO;
    }
}
