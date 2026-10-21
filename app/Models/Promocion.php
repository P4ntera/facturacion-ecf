<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoPromocion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Promocion extends Model
{
    use LogsActivity;

    protected $table = 'promociones';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'tipo',
        'valor',
        'fecha_inicio',
        'fecha_fin',
        'activa',
    ];

    protected $casts = [
        'tipo' => TipoPromocion::class,
        'valor' => 'decimal:2',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'activa' => 'boolean',
    ];

    // ── Relaciones ──────────────────────────────────────────────

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'promocion_producto')
            ->withTimestamps();
    }

    // ── Scopes ──────────────────────────────────────────────────

    /**
     * Promociones activas y cuya vigencia cubra la fecha indicada.
     */
    public function scopeVigentes(Builder $query, ?string $fecha = null): Builder
    {
        $fecha ??= now()->toDateString();

        return $query
            ->where('activa', true)
            ->where('fecha_inicio', '<=', $fecha)
            ->where('fecha_fin', '>=', $fecha);
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * ¿La promoción está vigente en la fecha indicada?
     */
    public function estaVigente(?string $fecha = null): bool
    {
        $fecha ??= now()->toDateString();

        return $this->activa
            && $this->fecha_inicio->toDateString() <= $fecha
            && $this->fecha_fin->toDateString() >= $fecha;
    }

    // ── Auditoría ───────────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'tipo', 'valor', 'fecha_inicio', 'fecha_fin', 'activa'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Promociones');
    }
}
