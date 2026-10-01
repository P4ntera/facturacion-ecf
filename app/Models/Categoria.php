<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Categoria extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['empresa_id', 'nombre', 'descripcion', 'activo', 'margen_ganancia'];

    protected $casts = [
        'activo' => 'boolean',
        'margen_ganancia' => 'decimal:2',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'descripcion', 'activo', 'margen_ganancia'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Categorias');
    }
}
