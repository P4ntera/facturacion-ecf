<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToppingHelado extends Model
{
    protected $table = 'toppings_helado';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'icono',
        'precio',
        'activo',
        'orden',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    protected $attributes = [
        'icono' => '🍫',
        'activo' => true,
        'orden' => 0,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
