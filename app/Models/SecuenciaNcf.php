<?php

namespace App\Models;

use App\Enums\TipoComprobante;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecuenciaNcf extends Model
{
    use HasFactory;

    protected $table = 'secuencias_ncf';

    protected $fillable = [
        'tipo_comprobante', 'prefijo', 'secuencia_desde', 'secuencia_actual',
        'secuencia_hasta', 'vencimiento', 'activa',
    ];

    protected $casts = [
        'tipo_comprobante' => TipoComprobante::class,
        'vencimiento'      => 'date',
        'activa'           => 'boolean',
    ];
}
