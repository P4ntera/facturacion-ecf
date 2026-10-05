<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPreparacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComandaDetalle extends Model
{
    protected $table = 'comanda_detalles';

    protected $fillable = [
        'comanda_id', 'producto_id', 'cantidad', 'precio_unitario',
        'notas', 'estado_preparacion', 'enviado_cocina_en', 'preparado_en',
    ];

    protected $casts = [
        'estado_preparacion' => EstadoPreparacion::class,
        'cantidad' => 'decimal:3',
        'precio_unitario' => 'decimal:2',
        'enviado_cocina_en' => 'datetime',
        'preparado_en' => 'datetime',
    ];

    protected $attributes = [
        'estado_preparacion' => 'pendiente',
        'cantidad' => '1.000',
    ];

    public function comanda(): BelongsTo
    {
        return $this->belongsTo(Comanda::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
