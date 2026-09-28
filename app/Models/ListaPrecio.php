<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ListaPrecio extends Model
{
    protected $table = 'lista_precios';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'descripcion',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'lista_precio_producto')
            ->withPivot('precio')
            ->withTimestamps();
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    /**
     * Retorna el precio especial de un producto en esta lista, o null si no tiene uno asignado.
     */
    public function precioDeProducto(int $productoId): ?string
    {
        $pivot = $this->productos()->where('producto_id', $productoId)->first();

        return $pivot?->pivot->precio;
    }
}
