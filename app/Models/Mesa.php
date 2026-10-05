<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoComanda;
use App\Enums\EstadoMesa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Mesa extends Model
{
    protected $fillable = [
        'empresa_id', 'area_restaurante_id', 'numero',
        'capacidad', 'estado', 'activa',
    ];

    protected $casts = [
        'estado' => EstadoMesa::class,
        'activa' => 'boolean',
    ];

    protected $attributes = [
        'estado' => 'disponible',
        'activa' => true,
        'capacidad' => 4,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(AreaRestaurante::class, 'area_restaurante_id');
    }

    public function comandas(): HasMany
    {
        return $this->hasMany(Comanda::class);
    }

    public function comandaActiva(): HasOne
    {
        return $this->hasOne(Comanda::class)
            ->whereNotIn('estado', [EstadoComanda::CERRADA, EstadoComanda::CANCELADA])
            ->latest();
    }

    public function estaDisponible(): bool
    {
        return $this->estado === EstadoMesa::DISPONIBLE;
    }

    public function ocupar(): void
    {
        $this->update(['estado' => EstadoMesa::OCUPADA]);
    }

    public function liberar(): void
    {
        $this->update(['estado' => EstadoMesa::DISPONIBLE]);
    }
}
