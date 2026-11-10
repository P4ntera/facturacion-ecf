<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoriaSabor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaborHelado extends Model
{
    protected $table = 'sabores_helado';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'color_hex',
        'categoria',
        'premium',
        'disponible',
        'activo',
        'orden',
    ];

    protected $casts = [
        'categoria' => CategoriaSabor::class,
        'premium' => 'boolean',
        'disponible' => 'boolean',
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    protected $attributes = [
        'color_hex' => '#A0522D',
        'premium' => false,
        'disponible' => true,
        'activo' => true,
        'orden' => 0,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
