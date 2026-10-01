<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCotizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cotizacion extends Model
{
    use SoftDeletes;

    protected $table = 'cotizaciones';

    protected $fillable = [
        'empresa_id', 'cliente_id', 'user_id', 'numero', 'fecha',
        'fecha_vencimiento', 'dias_vigencia', 'condiciones_pago', 'notas',
        'subtotal', 'descuento', 'itbis', 'total', 'estado',
        'venta_id', 'aprobado_por', 'aprobado_en',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_vencimiento' => 'date',
        'aprobado_en' => 'datetime',
        'estado' => EstadoCotizacion::class,
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'itbis' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    protected $attributes = [
        'subtotal' => 0,
        'descuento' => 0,
        'itbis' => 0,
        'total' => 0,
        'estado' => 'borrador',
        'dias_vigencia' => 15,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleCotizacion::class);
    }

    public function estaVigente(): bool
    {
        return $this->fecha_vencimiento->isFuture()
            && in_array($this->estado, [EstadoCotizacion::BORRADOR, EstadoCotizacion::ENVIADA, EstadoCotizacion::APROBADA]);
    }

    public function puedeConvertirse(): bool
    {
        return $this->estado === EstadoCotizacion::APROBADA && $this->venta_id === null;
    }
}
