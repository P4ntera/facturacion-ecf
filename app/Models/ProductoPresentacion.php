<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoPresentacion extends Model
{
    use HasFactory;

    protected $table = 'producto_presentaciones';

    protected $fillable = [
        'empresa_id', 'producto_id', 'nombre', 'factor',
        'codigo_barra', 'precio', 'es_base', 'activa',
    ];

    protected $casts = [
        'factor' => 'decimal:3',
        'precio' => 'decimal:2',
        'es_base' => 'boolean',
        'activa' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
