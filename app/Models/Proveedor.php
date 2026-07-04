<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Proveedor extends Model
{
    use HasFactory, SoftDeletes;
    use LogsActivity;

    protected $table = 'proveedores';

    protected $fillable = [
        'rnc',
        'nombre',
        'nombre_comercial',
        'actividad_economica',
        'telefono',
        'email',
        'direccion',
        'estado',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['rnc', 'nombre', 'nombre_comercial', 'actividad_economica', 'telefono', 'email', 'direccion', 'estado', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Proveedores');
    }
}
