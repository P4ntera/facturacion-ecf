<?php

namespace App\Models;

use App\Enums\AmbienteEcf;
use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Observers\VentaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy(VentaObserver::class)]
class Venta extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'cliente_id', 'user_id', 'tipo_comprobante', 'ncf', 'ncf_modifica',
        'tipo_pago', 'fecha_limite_pago',
        'fecha', 'moneda', 'tasa_cambio',
        'subtotal', 'descuento',
        'monto_gravado_18', 'monto_gravado_16', 'monto_gravado_0', 'monto_exento',
        'itbis_18', 'itbis_16', 'total_itbis', 'total',
        'estado', 'estado_fiscal', 'ecf_track_id',
        'pac_id', 'codigo_seguridad', 'dgii_url', 'xml_url', 'ambiente',
        'ecf_enviado_en', 'ecf_respuesta',
        'motivo_anulacion', 'anulada_en',
    ];

    protected $casts = [
        'tipo_comprobante' => TipoComprobante::class,
        'estado' => EstadoVenta::class,
        'estado_fiscal' => EstadoFiscal::class,
        'tipo_pago' => TipoPago::class,
        'ambiente' => AmbienteEcf::class,
        'fecha_limite_pago' => 'date',
        'fecha' => 'datetime',
        'ecf_enviado_en' => 'datetime',
        'anulada_en' => 'datetime',
        'ecf_respuesta' => 'array',
        'tasa_cambio' => 'decimal:4',
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'monto_gravado_18' => 'decimal:2',
        'monto_gravado_16' => 'decimal:2',
        'monto_gravado_0' => 'decimal:2',
        'monto_exento' => 'decimal:2',
        'itbis_18' => 'decimal:2',
        'itbis_16' => 'decimal:2',
        'total_itbis' => 'decimal:2',
        'total' => 'decimal:2',
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

    /**
     * Umbral de la DGII para Factura de Consumo (e-CF 32): por debajo, el comprador es opcional
     * y el PAC convierte el documento a RFCE automáticamente; en/por encima, es obligatorio.
     */
    public const UMBRAL_CONSUMO = '250000.00';

    public function estaAnulada(): bool
    {
        return $this->estado === EstadoVenta::ANULADA;
    }

    public function esElectronica(): bool
    {
        return $this->ncf !== null;
    }

    /**
     * true si este comprobante exige RNC/razón social del comprador: siempre para Crédito Fiscal
     * (31); para Consumo (32) solo si el total alcanza UMBRAL_CONSUMO. Los demás tipos no forman
     * parte de esta regla.
     */
    public function requiereComprador(): bool
    {
        return match ($this->tipo_comprobante) {
            TipoComprobante::FACTURA_CREDITO_FISCAL => true,
            TipoComprobante::FACTURA_CONSUMO => bccomp((string) $this->total, self::UMBRAL_CONSUMO, 2) >= 0,
            default => false,
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['cliente_id', 'tipo_comprobante', 'ncf', 'total', 'estado', 'estado_fiscal', 'motivo_anulacion'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Ventas');
    }
}
