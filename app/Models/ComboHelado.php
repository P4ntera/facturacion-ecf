<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboHelado extends Model
{
    protected $table = 'combos_helado';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'icono',
        'descripcion',
        'scoops_incluidos',
        'toppings_incluidos',
        'precio',
        'activo',
        'orden',
    ];

    protected $casts = [
        'scoops_incluidos' => 'integer',
        'toppings_incluidos' => 'integer',
        'precio' => 'decimal:2',
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    protected $attributes = [
        'icono' => '🍨',
        'scoops_incluidos' => 2,
        'toppings_incluidos' => 1,
        'activo' => true,
        'orden' => 0,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
