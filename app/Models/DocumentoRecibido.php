<?php

namespace App\Models;

use App\Enums\CanalRecepcionEcf;
use App\Enums\EstadoAprobacionComercial;
use App\Enums\EstadoReenvioPac;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class DocumentoRecibido extends Model
{
    use HasFactory;
    use LogsActivity;

    // Eloquent solo pluraliza la última palabra ("documento_recibidos"); la tabla es "documentos_recibidos".
    protected $table = 'documentos_recibidos';

    protected $fillable = [
        'empresa_id', 'canal', 'rnc_destino',
        'rnc_emisor', 'razon_social_emisor', 'encf', 'tipo_comprobante', 'monto_total', 'fecha_emision',
        'xml', 'estado_reenvio', 'error', 'respuesta_pac', 'ip_origen', 'aprobacion_comercial',
    ];

    protected $casts = [
        'canal' => CanalRecepcionEcf::class,
        'estado_reenvio' => EstadoReenvioPac::class,
        'aprobacion_comercial' => EstadoAprobacionComercial::class,
        'fecha_emision' => 'date',
        'monto_total' => 'decimal:2',
        'respuesta_pac' => 'array',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['canal', 'encf', 'estado_reenvio', 'aprobacion_comercial'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Documentos recibidos (DGII)');
    }
}
