<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AmbienteEcf;
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
        'moneda',
        'dgii_api_key',
        'dgii_ambiente',
        'dgii_base_url',
        'certificado_path',
        'certificado_password',
        'certificado_vence',
    ];

    protected $casts = [
        'aplica_itbis' => 'boolean',
        'precio_incluye_itbis' => 'boolean',
        'dgii_ambiente' => AmbienteEcf::class,
        'dgii_api_key' => 'encrypted',
        'certificado_password' => 'encrypted',
        'certificado_vence' => 'date',
    ];

    // Reflejan los defaults de la columna en la migración: sin esto, un ::create()/firstOrCreate()
    // que omite estos campos (el caso normal de Empresa::config()) deja el modelo en memoria con
    // null/'' hasta refrescarlo desde la BD (mismo patrón ya documentado en Impresora/User).
    protected $attributes = [
        'aplica_itbis' => true,
        'precio_incluye_itbis' => false,
        'tasa_itbis_defecto' => '18',
        'tipo_comprobante_defecto' => '32',
        'moneda' => 'DOP',
        'dgii_ambiente' => 'TesteCF',
    ];

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
