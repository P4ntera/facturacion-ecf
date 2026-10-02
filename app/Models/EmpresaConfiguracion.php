<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AmbienteEcf;
use App\Enums\FormaReembolso;
use App\Enums\MetodoCosto;
use App\Enums\RedondeoPrecio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Configuración fiscal 1:1 por empresa (antes EmpresaSettings/FacturacionSettings, globales vía
 * spatie-settings). dgii_api_key y certificado_password se cifran en reposo (cast 'encrypted'):
 * nunca en texto plano en BD, logs ni respuestas — accede a ellas solo por el atributo del
 * modelo, nunca con consultas crudas a la columna.
 */
class EmpresaConfiguracion extends Model
{
    use LogsActivity;

    protected $table = 'empresa_configuracion';

    protected $fillable = [
        'empresa_id',
        'aplica_itbis',
        'precio_incluye_itbis',
        'tasa_itbis_defecto',
        'tipo_comprobante_defecto',
        'permite_ventas_sin_comprobante',
        'moneda',
        'dgii_api_key',
        'dgii_ambiente',
        'dgii_base_url',
        'certificado_path',
        'certificado_password',
        'certificado_vence',
        'metodo_costo',
        'redondeo_precio',
        'permite_stock_negativo',
        'acepta_devoluciones',
        'devolucion_plazo_dias',
        'devolucion_reembolsos',
        'devolucion_monto_supervisor',
    ];

    protected $casts = [
        'aplica_itbis' => 'boolean',
        'precio_incluye_itbis' => 'boolean',
        'permite_ventas_sin_comprobante' => 'boolean',
        'dgii_ambiente' => AmbienteEcf::class,
        'dgii_api_key' => 'encrypted',
        'certificado_password' => 'encrypted',
        'certificado_vence' => 'date',
        'metodo_costo' => MetodoCosto::class,
        'redondeo_precio' => RedondeoPrecio::class,
        'permite_stock_negativo' => 'boolean',
        'acepta_devoluciones' => 'boolean',
        'devolucion_plazo_dias' => 'integer',
        'devolucion_reembolsos' => 'array',
        'devolucion_monto_supervisor' => 'decimal:2',
    ];

    // Reflejan los defaults de la columna en la migración: sin esto, un ::create()/firstOrCreate()
    // que omite estos campos (el caso normal de Empresa::config()) deja el modelo en memoria con
    // null/'' hasta refrescarlo desde la BD (mismo patrón ya documentado en Impresora/User).
    protected $attributes = [
        'aplica_itbis' => true,
        'precio_incluye_itbis' => false,
        'tasa_itbis_defecto' => '18',
        'tipo_comprobante_defecto' => '32',
        'permite_ventas_sin_comprobante' => false,
        'moneda' => 'DOP',
        'dgii_ambiente' => 'TesteCF',
        'dgii_base_url' => 'https://sandbox.pac-ecf.example.do/api/v1',
        'metodo_costo' => 'ultima_compra',
        'redondeo_precio' => 'ninguno',
        'permite_stock_negativo' => false,
        'acepta_devoluciones' => true,
    ];

    /**
     * Formas de reembolso que la empresa acepta en una devolución. null en la columna = todas.
     *
     * @return array<int, FormaReembolso>
     */
    public function reembolsosPermitidos(): array
    {
        if (blank($this->devolucion_reembolsos)) {
            return FormaReembolso::cases();
        }

        return collect($this->devolucion_reembolsos)
            ->map(fn (string $valor) => FormaReembolso::tryFrom($valor))
            ->filter()
            ->values()
            ->all();
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function tieneCertificado(): bool
    {
        return filled($this->certificado_path);
    }

    /** true si el certificado vence en 30 días o menos (o ya venció). */
    public function certificadoPorVencer(): bool
    {
        return $this->certificado_vence !== null
            && now()->addDays(30)->greaterThanOrEqualTo($this->certificado_vence);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Nunca en el log de auditoría: dgii_api_key, certificado_password, certificado_path.
            ->logOnly([
                'aplica_itbis',
                'precio_incluye_itbis',
                'tasa_itbis_defecto',
                'tipo_comprobante_defecto',
                'moneda',
                'dgii_ambiente',
                'certificado_vence',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Configuración fiscal');
    }
}
