<?php

namespace App\Models;

use App\Enums\AmbienteEcf;
use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Observers\VentaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy(VentaObserver::class)]
class Venta extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'cliente_id', 'user_id', 'tipo_comprobante', 'ncf', 'ncf_modifica', 'venta_modificada_id',
        'tipo_pago', 'fecha_limite_pago', 'forma_pago', 'arqueo_caja_id', 'caja_id',
        'empresa_id', 'cliente_id', 'user_id', 'tipo_comprobante', 'ncf', 'ncf_modifica',
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
        'forma_pago' => FormaPago::class,
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

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleVenta::class);
    }

    public function arqueoCaja(): BelongsTo
    {
        return $this->belongsTo(ArqueoCaja::class);
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function cuentaPorCobrar(): HasOne
    {
        return $this->hasOne(CuentaPorCobrar::class);
    }

    /** Para una Nota de Crédito de anulación: la venta que anula. */
    public function ventaModificada(): BelongsTo
    {
        return $this->belongsTo(self::class, 'venta_modificada_id');
    }

    /** Notas de Crédito emitidas para anular esta venta (a lo sumo una hoy). */
    public function notasCredito(): HasMany
    {
        return $this->hasMany(self::class, 'venta_modificada_id');
    }

    /** true si esta fila es una Nota de Crédito emitida para anular otra venta (no una venta). */
    public function esNotaCreditoDeAnulacion(): bool
    {
        return $this->venta_modificada_id !== null;
    }

    public function esACredito(): bool
    {
        return $this->tipo_pago === TipoPago::CREDITO;
    }

    /**
     * Umbral de la DGII para Factura de Consumo (e-CF 32): por debajo, el comprador es opcional
     * y el PAC convierte el documento a RFCE automáticamente; en/por encima, es obligatorio.
     */
    public const UMBRAL_CONSUMO = '250000.00';

    public const ETIQUETA_AL_PORTADOR = 'Al portador';

    public const ETIQUETA_SIN_COMPROBANTE = 'Sin comprobante';

    public function estaAnulada(): bool
    {
        return $this->estado === EstadoVenta::ANULADA;
    }

    /**
     * true si el tipo de comprobante es electrónico (e-CF, pasa por el PAC/DGII); false si es
     * NCF físico (tipo B). Determinado por el tipo, no por si tiene ncf asignado — un comprobante
     * físico también lleva un NCF real (ver VentaService::registrar()), solo que nunca se
     * transmite.
     */
    public function esElectronica(): bool
    {
        return $this->tipo_comprobante?->esElectronico() ?? false;
    }

    /**
     * Base imponible neta (sin ITBIS, ya descontado el descuento global) = suma de las líneas:
     * subtotal es BRUTO y el descuento global se prorratea en las líneas antes del ITBIS (ver
     * VentaService::calcularLineas()). Es el "Monto facturado" del 607 y el subtotal que imprimen
     * el ticket y el PDF (para que las líneas sumen lo que dice el pie).
     */
    public function subtotalNeto(): string
    {
        return bcsub((string) $this->subtotal, (string) $this->descuento, 2);
    }

    /** true si la venta se registró sin comprobante fiscal (sin NCF, fuera del 607). */
    public function esSinComprobante(): bool
    {
        return $this->tipo_comprobante === null;
    }

    /** Etiqueta del tipo de comprobante para tickets, PDFs y listados. */
    public function etiquetaComprobante(): string
    {
        return $this->tipo_comprobante?->etiqueta() ?? self::ETIQUETA_SIN_COMPROBANTE;
    }

    /** Nombre del cliente para tickets, PDFs y listados; "Al portador" si la venta no tiene. */
    public function nombreCliente(): string
    {
        return $this->cliente?->nombre ?? self::ETIQUETA_AL_PORTADOR;
    }

    /**
     * true si el tipo de comprobante exige que la venta tenga un cliente asociado (aunque no
     * necesariamente con documento — eso lo decide requiereComprador()): los que siempre
     * identifican al comprador (Crédito Fiscal, Regímenes Especiales, Gubernamental) y Consumo
     * desde el umbral.
     */
    public function requiereCliente(): bool
    {
        return $this->requiereComprador() || in_array($this->tipo_comprobante, [
            TipoComprobante::REGIMENES_ESPECIALES, TipoComprobante::REGIMENES_ESPECIALES_FISICA,
            TipoComprobante::GUBERNAMENTAL, TipoComprobante::GUBERNAMENTAL_FISICA,
        ], true);
    }

    /**
     * true si este comprobante exige RNC/razón social del comprador: siempre para Crédito Fiscal
     * (31/B01); para Consumo (32/B02) solo si el total alcanza UMBRAL_CONSUMO. La regla es la
     * misma para el físico y el electrónico (Norma DGII, no una particularidad del e-CF). Los
     * demás tipos no forman parte de esta regla.
     */
    public function requiereComprador(): bool
    {
        return match ($this->tipo_comprobante) {
            TipoComprobante::FACTURA_CREDITO_FISCAL, TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA => true,
            TipoComprobante::FACTURA_CONSUMO, TipoComprobante::FACTURA_CONSUMO_FISICA => bccomp((string) $this->total, self::UMBRAL_CONSUMO, 2) >= 0,
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
