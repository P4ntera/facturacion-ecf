<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicioLavanderia extends Model
{
    protected $table = 'servicios_lavanderia';

    protected $fillable = [
        'empresa_id', 'nombre', 'descripcion', 'precio_base',
        'aplica_itbis', 'activo',
    ];

    protected $casts = [
        'precio_base' => 'decimal:2',
        'aplica_itbis' => 'boolean',
        'activo' => 'boolean',
    ];

    protected $attributes = [
        'aplica_itbis' => true,
        'activo' => true,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
