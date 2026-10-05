<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AreaRestaurante extends Model
{
    protected $table = 'areas_restaurante';

    protected $fillable = [
        'empresa_id', 'nombre', 'descripcion', 'orden', 'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    protected $attributes = [
        'activa' => true,
        'orden' => 0,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function mesas(): HasMany
    {
        return $this->hasMany(Mesa::class);
    }

    public function mesasActivas(): HasMany
    {
        return $this->mesas()->where('activa', true);
    }
}
