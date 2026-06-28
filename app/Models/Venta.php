<?php

namespace App\Models;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    use HasFactory;

    protected $fillable = [
        'cliente_id', 'user_id', 'tipo_comprobante', 'ncf', 'ncf_modifica',
        'fecha', 'moneda', 'tasa_cambio',
        'subtotal', 'descuento',
        'monto_gravado_18', 'monto_gravado_16', 'monto_gravado_0', 'monto_exento',
        'itbis_18', 'itbis_16', 'total_itbis', 'total',
        'estado', 'estado_fiscal', 'ecf_track_id',
        'ecf_enviado_en', 'ecf_respuesta',
        'motivo_anulacion', 'anulada_en',
    ];

    protected $casts = [
        'tipo_comprobante'  => TipoComprobante::class,
        'estado'            => EstadoVenta::class,
        'estado_fiscal'     => EstadoFiscal::class,
        'fecha'             => 'datetime',
        'ecf_enviado_en'    => 'datetime',
        'anulada_en'        => 'datetime',
        'ecf_respuesta'     => 'array',
        'tasa_cambio'       => 'decimal:4',
        'subtotal'          => 'decimal:2',
        'descuento'         => 'decimal:2',
        'monto_gravado_18'  => 'decimal:2',
        'monto_gravado_16'  => 'decimal:2',
        'monto_gravado_0'   => 'decimal:2',
        'monto_exento'      => 'decimal:2',
        'itbis_18'          => 'decimal:2',
        'itbis_16'          => 'decimal:2',
        'total_itbis'       => 'decimal:2',
        'total'             => 'decimal:2',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleVenta::class);
    }

    public function estaAnulada(): bool
    {
        return $this->estado === EstadoVenta::ANULADA;
    }

    public function esElectronica(): bool
    {
        return $this->ncf !== null;
    }
}
