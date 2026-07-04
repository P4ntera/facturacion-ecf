<?php

namespace App\Models;

use App\Enums\TipoComprobante;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SecuenciaNcf extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'secuencias_ncf';

    protected $fillable = [
        'tipo_comprobante', 'prefijo', 'secuencia_desde', 'secuencia_actual',
        'secuencia_hasta', 'vencimiento', 'activa',
    ];

    protected $casts = [
        'tipo_comprobante' => TipoComprobante::class,
        'vencimiento' => 'date',
        'activa' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo_comprobante', 'prefijo', 'secuencia_desde', 'secuencia_hasta', 'secuencia_actual', 'vencimiento', 'activa'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Secuencias NCF');
    }
}
